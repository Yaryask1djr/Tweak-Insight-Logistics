<?php

require_once __DIR__ . '/jwt.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/authorization_policy.php';
require_once __DIR__ . '/operations_schema.php';
require_once __DIR__ . '/monitoring.php';
require_once __DIR__ . '/logger.php';

class AuthMiddleware
{
    private static ?bool $hasAccountStatusColumn = null;

    /**
     * Extracts Bearer token, validates JWT, verifies user in database and checks allowed roles.
     * Returns authenticated user array or terminates execution with JSON error.
     */
    public static function verify(PDO $db, array $allowedRoles = []): array
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        
        if (empty($authHeader)) {
            // Attempt fallback to headers() function for Apache/Nginx
            if (function_exists('apache_request_headers')) {
                $headers = apache_request_headers();
                $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
            }
        }

        $matches = [];
        if (empty($authHeader) || !preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            Response::unauthorized('Authorization Bearer token is missing or malformed.');
        }

        $token = trim($matches[1] ?? '');

        if ($token === '' || $token === 'null' || $token === 'undefined') {
            Response::unauthorized('Authorization Bearer token is missing or malformed.');
        }

        try {
            $decoded = JWTHelper::decode($token);
            if (($decoded->typ ?? '') !== 'access') {
                Response::unauthorized('This token cannot be used for API access.');
            }
            $tokenData = $decoded->data ?? null;

            if (!$tokenData || empty($tokenData->id) || empty($tokenData->role)) {
                Response::unauthorized('Invalid token payload structure.');
            }

            // Fetch the current database role. The JWT role is only a claim:
            // authorization always uses the current stored role below.
            $columns = 'id, role, full_name, email, phone, is_approved, token_version';
            if (self::hasAccountStatusColumn($db)) {
                $columns .= ', account_status';
            }
            $stmt = $db->prepare("SELECT {$columns} FROM users WHERE id = ?");
            $stmt->execute([$tokenData->id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                Response::unauthorized('User associated with this token no longer exists.');
            }

            if (!hash_equals((string)$user['role'], (string)$tokenData->role)) {
                Response::unauthorized('This token no longer matches the account role. Please sign in again.');
            }

            // token_version mismatch means the token was revoked (password change, logout, suspension)
            if ((int)($user['token_version'] ?? 0) !== (int)($tokenData->token_version ?? 0)) {
                Response::unauthorized('This session has been revoked. Please sign in again.');
            }

            if (($user['account_status'] ?? 'active') !== 'active') {
                Response::forbidden('This account is not active. Please contact operations support.');
            }

            // NOTE: We no longer block delivery partners globally here.
            // KYC/approval restrictions are enforced per-endpoint via requireDriverKyc().
            // This allows drivers to log in and access their profile/KYC upload endpoints.

            // Enforce role authorization if specified
            if (!empty($allowedRoles) && !in_array($user['role'], $allowedRoles, true)) {
                Response::forbidden(sprintf('Access restricted. Required roles: %s.', implode(', ', $allowedRoles)));
            }

            return $user;
        } catch (\Firebase\JWT\ExpiredException $e) {
            // Normal JWT lifecycle expiration. Frontend refreshes via HttpOnly cookie.
            // Do not log this standard lifecycle event as a security anomaly.
            Logger::debug('Access token expired naturally: ' . $e->getMessage());
            Response::unauthorized('Your session is invalid or has expired. Please sign in again.');
        } catch (Exception $e) {
            Monitoring::authAnomaly('invalid_access_token', [
                'exception' => get_class($e),
            ]);
            Response::unauthorized('Your session is invalid or has expired. Please sign in again.');
        }
    }

    /**
     * Enforce KYC verification for clients before allowing operational actions.
     * Must be called AFTER verify() to guarantee $user is populated.
     *
     * @param PDO   $db     Active database connection
     * @param int   $userId Authenticated client's user ID
     */
    public static function requireClientKyc(PDO $db, int $userId): void
    {
        OperationsSchema::requireTables($db, ['clients']);
        $stmt = $db->prepare(
            "SELECT kyc_status FROM clients WHERE user_id = ? LIMIT 1"
        );
        $stmt->execute([$userId]);
        $status = $stmt->fetchColumn();

        if ($status !== 'verified') {
            Response::error(
                'Your account must be KYC verified before you can request a delivery. ' .
                'Please complete your identity verification in the dashboard.',
                403
            );
        }
    }

    /**
     * Enforce KYC verification for delivery partners before allowing operational actions.
     * Checks both users.is_approved and drivers.kyc_status.
     *
     * @param PDO   $db     Active database connection
     * @param int   $userId Authenticated driver's user ID
     */
    public static function requireDriverKyc(PDO $db, int $userId): void
    {
        OperationsSchema::requireTables($db, ['drivers']);
        $stmt = $db->prepare(
            "SELECT u.is_approved, d.kyc_status
             FROM users u
             LEFT JOIN drivers d ON d.user_id = u.id
             WHERE u.id = ? LIMIT 1"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || (int)$row['is_approved'] !== 1 || $row['kyc_status'] !== 'verified') {
            Response::forbidden(
                'Your delivery partner account must be KYC verified and approved by operations ' .
                'before you can access this feature.'
            );
        }
    }

    /**
     * Safely retrieves the authenticated user from the Bearer token if present and valid.
     * Returns null if unauthenticated, without terminating the request.
     */
    public static function getAuthenticatedUser(PDO $db): ?array
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        
        if (empty($authHeader) && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }

        $matches = [];
        if (empty($authHeader) || !preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return null;
        }

        $token = trim($matches[1] ?? '');

        if ($token === '' || $token === 'null' || $token === 'undefined') {
            return null;
        }

        try {
            $decoded = JWTHelper::decode($token);
            if (($decoded->typ ?? '') !== 'access') {
                return null;
            }
            $tokenData = $decoded->data ?? null;

            if (!$tokenData || empty($tokenData->id) || empty($tokenData->role)) {
                return null;
            }

            $columns = 'id, role, full_name, email, phone, is_approved, token_version';
            if (self::hasAccountStatusColumn($db)) {
                $columns .= ', account_status';
            }
            $stmt = $db->prepare("SELECT {$columns} FROM users WHERE id = ?");
            $stmt->execute([$tokenData->id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user || !hash_equals((string)$user['role'], (string)$tokenData->role)) {
                return null;
            }

            // Reject revoked tokens (post-logout, post-password-change)
            if ((int)($user['token_version'] ?? 0) !== (int)($tokenData->token_version ?? 0)) {
                return null;
            }

            if (($user['account_status'] ?? 'active') !== 'active') {
                return null;
            }

            return $user;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Validate identity, then enforce a named backend permission. */
    public static function verifyPermission(PDO $db, string $permission): array
    {
        $user = self::verify($db);
        AuthorizationPolicy::authorize($user, $permission);
        return $user;
    }

    private static function hasAccountStatusColumn(PDO $db): bool
    {
        if (self::$hasAccountStatusColumn !== null) {
            return self::$hasAccountStatusColumn;
        }

        $statement = $db->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'account_status'"
        );
        $statement->execute();
        return self::$hasAccountStatusColumn = (bool)$statement->fetchColumn();
    }
}
