<?php

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
        $data = json_decode(file_get_contents("php://input"));

        if (empty($data->email) || empty($data->password)) {
            Response::error('Email and password are required.');
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
        $data = json_decode(file_get_contents("php://input"));

        $required = ['full_name', 'email', 'password', 'phone'];
        foreach ($required as $field) {
            if (empty($data->$field)) {
                Response::error("The field '{$field}' is required.");
            }
        }

        if (!filter_var($data->email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Please provide a valid email address.');
        }

        // Check uniqueness
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([trim($data->email)]);
        if ($stmt->fetch()) {
            Response::error('An account with this email already exists.', 409);
        }

        $hashedPassword = password_hash($data->password, PASSWORD_DEFAULT);
        $address = trim($data->address ?? '');
        if ($address !== '') {
            KanoServiceArea::assertAddress($address, 'Default address');
        }

        $insert = $db->prepare("INSERT INTO users (role, full_name, email, phone, password_hash, address, is_approved) VALUES ('client', ?, ?, ?, ?, ?, 1)");
        $insert->execute([
            trim($data->full_name),
            trim($data->email),
            trim($data->phone),
            $hashedPassword,
            $address
        ]);

        $userId = (int)$db->lastInsertId();
        OperationsSchema::ensureClientProfile($db, $userId);

        Response::json([
            'user_id' => $userId,
            'role' => 'client',
            'full_name' => $data->full_name,
            'email' => $data->email
        ], 'Client registered successfully.', 201);
    }

    /**
     * Register a new delivery partner (pending KYC approval)
     */
    public static function registerDelivery(PDO $db): void
    {
        $data = json_decode(file_get_contents("php://input"));

        $required = ['full_name', 'email', 'password', 'phone', 'address'];
        foreach ($required as $field) {
            if (empty($data->$field)) {
                Response::error("The field '{$field}' is required.");
            }
        }

        if (!filter_var($data->email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Please provide a valid email address.');
        }

        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([trim($data->email)]);
        if ($stmt->fetch()) {
            Response::error('An account with this email already exists.', 409);
        }

        $hashedPassword = password_hash($data->password, PASSWORD_DEFAULT);
        $address = trim($data->address ?? '');
        KanoServiceArea::assertAddress($address, 'Operating base');

        $insert = $db->prepare("INSERT INTO users (role, full_name, email, phone, password_hash, address, is_approved) VALUES ('delivery', ?, ?, ?, ?, ?, 0)");
        $insert->execute([
            trim($data->full_name),
            trim($data->email),
            trim($data->phone),
            $hashedPassword,
            $address
        ]);

        $userId = (int)$db->lastInsertId();
        OperationsSchema::ensureDriverProfile($db, $userId);

        Response::json([
            'user_id' => $userId,
            'role' => 'delivery',
            'is_approved' => 0
        ], 'Registration submitted successfully. Your account is pending administrative approval.', 201);
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
        $data = json_decode(file_get_contents('php://input'));

        if (empty($data->current_password) || empty($data->new_password)) {
            Response::error('Both current_password and new_password are required.');
        }

        if (strlen($data->new_password) < 8) {
            Response::error('New password must be at least 8 characters long.');
        }

        // Fetch current hash
        $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->execute([$user['id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || !password_verify($data->current_password, $row['password_hash'])) {
            Response::error('Current password is incorrect.', 403);
        }

        $newHash = password_hash($data->new_password, PASSWORD_DEFAULT);

        // Update password AND rotate token_version atomically to invalidate all sessions
        $db->prepare("UPDATE users SET password_hash = ?, token_version = token_version + 1 WHERE id = ?")
           ->execute([$newHash, $user['id']]);
        RefreshSession::revokeAllForUser($db, (int)$user['id'], 'password_changed');

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
