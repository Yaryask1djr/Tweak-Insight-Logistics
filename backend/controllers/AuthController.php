<?php

require_once __DIR__ . '/../helpers/password_policy.php';
require_once __DIR__ . '/../helpers/account_registration.php';

require_once __DIR__ . '/../helpers/jwt.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/kano_service_area.php';
require_once __DIR__ . '/../helpers/operations_schema.php';
require_once __DIR__ . '/../helpers/refresh_session.php';

class AuthController
{
    /**
     * Authenticate a user, issue a short-lived access JWT, and set an opaque
     * HttpOnly refresh cookie. No long-lived bearer credential is returned to
     * JavaScript or browser storage.
     */
    public static function login(PDO $db): void
    {
        $data = (object)HttpInput::readObject();
        try {
            $data->email = HttpInput::text((array)$data, 'email', 191);
            $data->password = HttpInput::text((array)$data, 'password', 1024, true, false);
        } catch (TransactionBusinessException $e) {
            Response::error($e->getMessage(), $e->getStatusCode());
        }

        $stmt = $db->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([trim($data->email)]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($data->password, $user['password_hash'])) {
            Response::error('Invalid email or password credentials.', 401);
        }

        if (($user['account_status'] ?? 'active') !== 'active') {
            Response::error('This account is not active. Please contact operations support.', 403);
        }

        // NOTE: We intentionally do NOT block login based on KYC / is_approved status.
        // Users must be able to log in to complete their KYC submission.
        // KYC restrictions are enforced at the individual endpoint level.

        // Update last_login_at timestamp
        $db->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?")->execute([$user['id']]);

        $tokenPayload = [
            'id'            => (int)$user['id'],
            'email'         => $user['email'],
            'role'          => $user['role'],
            'full_name'     => $user['full_name'],
            'token_version' => (int)($user['token_version'] ?? 0),
        ];

        $refreshToken = RefreshSession::issue($db, $user);
        $token = JWTHelper::encode($tokenPayload);

        unset($user['password_hash']);
        $user['kyc_status'] = self::kycStatus($db, (int)$user['id'], (string)$user['role']);

        Response::json([
            'access_token' => $token,
            'expires_in' => 900,
            'token_type' => 'Bearer',
            'user' => $user
        ], 'Login successful.');
    }

    /** Rotate the HttpOnly refresh session and return a fresh short-lived access token. */
    public static function refresh(PDO $db): void
    {
        try {
            RefreshSession::assertBrowserRefreshRequest();
            $user = RefreshSession::rotate($db);
        } catch (RefreshRotationConflict $exception) {
            header('Retry-After: 1');
            Response::error('Session refresh is already completing. Retry shortly.', 409, ['retryable' => true]);
        } catch (Throwable $exception) {
            // Do not reveal whether a particular refresh token existed or why it
            // was invalidated. The cookie has already been cleared by the helper.
            Logger::warning('Refresh session rejected: ' . $exception->getMessage());
            Response::unauthorized('Your session has expired. Please sign in again.');
        }

        unset($user['refresh_token']);

        $token = JWTHelper::encode([
            'id' => (int)$user['id'],
            'email' => $user['email'],
            'role' => $user['role'],
            'full_name' => $user['full_name'],
            'token_version' => (int)$user['token_version'],
        ]);
        $user['kyc_status'] = self::kycStatus($db, (int)$user['id'], (string)$user['role']);

        Response::json([
            'access_token' => $token,
            'expires_in' => 900,
            'token_type' => 'Bearer',
            'user' => $user,
        ], 'Session refreshed.');
    }

    /**
     * Register a new client / merchant
     */
    public static function registerClient(PDO $db): void
    {
        $result = self::register($db, 'client');
        Response::json($result, 'Client registered successfully.', 201);
    }

    public static function registerDelivery(PDO $db): void
    {
        $result = self::register($db, 'delivery');
        Response::json($result + ['is_approved' => 0], 'Registration submitted successfully. Your account is pending administrative approval.', 201);
    }

    private static function register(PDO $db, string $role): array
    {
        try {
            $fields = AccountRegistration::validate(HttpInput::readObject(), $role);
            if ($fields['address'] !== '') KanoServiceArea::assertAddress($fields['address'], $role === 'client' ? 'Default address' : 'Operating base');
            $userId = AccountRegistration::create($db, $fields, $role);
            return ['user_id' => $userId, 'role' => $role, 'full_name' => $fields['full_name'], 'email' => $fields['email']];
        } catch (TransactionBusinessException $e) {
            Response::error($e->getMessage(), $e->getStatusCode());
        }
    }

    /**
     * Get current authenticated user profile
     */
    public static function me(PDO $db, array $user): void
    {
        Response::json($user, 'Profile retrieved.');
    }

    /**
     * Server-side logout invalidates every access JWT and refresh session for the
     * account, then removes the browser's HttpOnly refresh cookie.
     */
    public static function logout(PDO $db, array $user): void
    {
        $db->prepare("UPDATE users SET token_version = token_version + 1 WHERE id = ?")
           ->execute([$user['id']]);
        RefreshSession::revokeAllForUser($db, (int)$user['id'], 'logout');

        Response::json([], 'Logged out successfully. All active sessions have been invalidated.');
    }

    /**
     * Authenticated password change: validates old password then updates hash and
     * increments token_version so all other active sessions are immediately invalidated.
     */
    public static function changePassword(PDO $db, array $user): void
    {
        $data = (object)HttpInput::readObject();
        try {
            $data->current_password = HttpInput::text((array)$data, 'current_password', 1024, true, false);
            $data->new_password = HttpInput::text((array)$data, 'new_password', 72, true, false);
        } catch (TransactionBusinessException $e) {
            Response::error($e->getMessage(), $e->getStatusCode());
        }

        PasswordPolicy::validate($data->new_password);

        // Fetch current hash
        $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->execute([$user['id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || !password_verify($data->current_password, $row['password_hash'])) {
            Response::error('Current password is incorrect.', 403);
        }

        $newHash = password_hash($data->new_password, PASSWORD_DEFAULT);

        // Update password AND rotate token_version atomically to invalidate all sessions
        DatabaseTransaction::run($db, static function (PDO $db) use ($newHash, $user, $row): void {
            $update = $db->prepare("UPDATE users SET password_hash = ?, token_version = token_version + 1 WHERE id = ? AND password_hash = ?");
            $update->execute([$newHash, $user['id'], $row['password_hash']]);
            if ($update->rowCount() !== 1) DatabaseTransaction::fail('Your password changed during this request. Sign in again.', 409);
            $db->prepare("UPDATE auth_refresh_sessions SET revoked_at = UTC_TIMESTAMP(), revoked_reason = 'password_changed' WHERE user_id = ? AND revoked_at IS NULL")->execute([$user['id']]);
        });
        RefreshSession::clearCookie();

        Response::json([], 'Password changed successfully. All other active sessions have been signed out.');
    }

    private static function kycStatus(PDO $db, int $userId, string $role): ?string
    {
        if ($role === 'client') {
            $statement = $db->prepare('SELECT kyc_status FROM clients WHERE user_id = ? LIMIT 1');
        } elseif ($role === 'delivery') {
            $statement = $db->prepare('SELECT kyc_status FROM drivers WHERE user_id = ? LIMIT 1');
        } else {
            return null;
        }
        $statement->execute([$userId]);
        return $statement->fetchColumn() ?: 'not_submitted';
    }
}
