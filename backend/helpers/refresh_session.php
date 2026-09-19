<?php

/**
 * RefreshSession keeps long-lived credentials out of JavaScript. The browser
 * receives only an opaque, HttpOnly cookie; the database retains only its hash.
 */
require_once __DIR__ . '/monitoring.php';

class RefreshSession
{
    private const COOKIE_NAME = 'TIL_REFRESH';
    private const LIFETIME_SECONDS = 2592000; // 30 days

    /** Create a new refresh-token family for a successful sign-in. Returns the raw token. */
    public static function issue(PDO $db, array $user): string
    {
        return self::create($db, (int)$user['id'], (int)($user['token_version'] ?? 0), bin2hex(random_bytes(16)));
    }

    /**
     * Rotate the supplied refresh token and return the current account record.
     * Re-use of any token that was already rotated or revoked is treated as theft
     * and triggers automatic compromise defense: every active session and access
     * JWT for that account is immediately invalidated.
     */
    public static function rotate(PDO $db): array
    {
        $token = self::readToken();
        if ($token === null) {
            self::clearCookie();
            throw new RuntimeException('Refresh session is missing or invalid.');
        }

        $tokenHash = hash('sha256', $token);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        try {
            $db->beginTransaction();
            $statement = $db->prepare(
                'SELECT rs.id, rs.user_id, rs.family_id, rs.token_version, rs.expires_at,
                        rs.revoked_at, rs.revoked_reason, u.role, u.full_name, u.email,
                        u.phone, u.is_approved, u.token_version AS current_token_version,
                        u.account_status
                 FROM auth_refresh_sessions rs
                 INNER JOIN users u ON u.id = rs.user_id
                 WHERE rs.token_hash = ?
                 LIMIT 1 FOR UPDATE'
            );
            $statement->execute([$tokenHash]);
            $session = $statement->fetch(PDO::FETCH_ASSOC);

            if (!$session) {
                $db->rollBack();
                self::clearCookie();
                throw new RuntimeException('Refresh session is invalid.');
            }

            // ── AUTOMATIC COMPROMISE DEFENSE: REUSE DETECTION ──
            // If a token that has ALREADY been revoked (e.g. from previous rotation or logout)
            // is presented again, an adversary has likely intercepted a previously used token.
            // Invalidate ALL active sessions for that user and increment token_version immediately.
            if ($session['revoked_at'] !== null) {
                self::invalidateAllSessionsInTransaction($db, (int)$session['user_id'], 'refresh_token_reuse');
                $db->commit();
                self::clearCookie();

                Monitoring::authAnomaly('refresh_token_reuse', [
                    'user_id' => (int)$session['user_id'],
                    'family_id' => $session['family_id'],
                ]);

                Logger::warning('Security Alert: Revoked refresh token reuse detected. All active sessions invalidated.', [
                    'user_id' => (int)$session['user_id'],
                    'family_id' => $session['family_id'],
                    'original_revocation_reason' => $session['revoked_reason'],
                ]);

                throw new RuntimeException('Refresh token reuse detected. All sessions invalidated for security.');
            }

            $expired = strtotime((string)$session['expires_at']) <= $now->getTimestamp();
            $versionChanged = (int)$session['token_version'] !== (int)$session['current_token_version'];
            $inactive = ($session['account_status'] ?? 'active') !== 'active';

            if ($expired || $versionChanged || $inactive) {
                // Invalidate this expired or stale token
                $db->prepare("UPDATE auth_refresh_sessions SET revoked_at = UTC_TIMESTAMP(), revoked_reason = 'expired' WHERE id = ? AND revoked_at IS NULL")
                   ->execute([(int)$session['id']]);
                $db->commit();
                self::clearCookie();
                throw new RuntimeException('Refresh session is no longer valid.');
            }

            // ── ATOMIC SINGLE-USE ROTATION ──
            // Revoke the presented refresh token immediately so it can never be used again.
            $replace = $db->prepare(
                "UPDATE auth_refresh_sessions
                 SET revoked_at = UTC_TIMESTAMP(), revoked_reason = 'rotated', last_used_at = UTC_TIMESTAMP()
                 WHERE id = ? AND revoked_at IS NULL"
            );
            $replace->execute([(int)$session['id']]);

            if ($replace->rowCount() !== 1) {
                // Concurrent race condition on rotation: treat as reuse and invalidate all sessions.
                self::invalidateAllSessionsInTransaction($db, (int)$session['user_id'], 'refresh_token_reuse');
                $db->commit();
                self::clearCookie();
                throw new RuntimeException('Refresh session rotation conflict detected.');
            }

            // Issue the new paired single-use token in the same token family
            $newRefreshToken = self::create(
                $db,
                (int)$session['user_id'],
                (int)$session['current_token_version'],
                (string)$session['family_id'],
                true
            );
            $db->commit();

            return [
                'id' => (int)$session['user_id'],
                'role' => $session['role'],
                'full_name' => $session['full_name'],
                'email' => $session['email'],
                'phone' => $session['phone'],
                'is_approved' => (int)$session['is_approved'],
                'token_version' => (int)$session['current_token_version'],
                'refresh_token' => $newRefreshToken,
            ];
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            self::clearCookie();
            throw $exception;
        }
    }

    /** Revoke every refresh session for an account, e.g. after logout or password change. */
    public static function revokeAllForUser(PDO $db, int $userId, string $reason): void
    {
        $statement = $db->prepare(
            'UPDATE auth_refresh_sessions
             SET revoked_at = UTC_TIMESTAMP(), revoked_reason = ?
             WHERE user_id = ? AND revoked_at IS NULL'
        );
        $statement->execute([$reason, $userId]);
        self::clearCookie();
    }

    /**
     * Refresh is cookie-authenticated, so require an allowed browser origin and
     * a non-simple XMLHttpRequest header. This prevents cross-site form posts
     * from silently rotating a session even if a deployment weakens SameSite.
     */
    public static function assertBrowserRefreshRequest(): void
    {
        // If an explicit non-cookie token is supplied in body/header, CSRF is not applicable.
        $cookieUsed = isset($_COOKIE[self::COOKIE_NAME]);
        if (!$cookieUsed) {
            return;
        }

        $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
        $requestedWith = strtolower(trim((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')));
        if ($requestedWith !== 'xmlhttprequest' && ($origin === '' || !in_array($origin, self::allowedOrigins(), true))) {
            throw new RuntimeException('Refresh request origin is not allowed.');
        }
    }

    private static function create(PDO $db, int $userId, int $tokenVersion, string $familyId, bool $insideTransaction = false): string
    {
        $token = self::newOpaqueToken();
        $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->add(new DateInterval('PT' . self::LIFETIME_SECONDS . 'S'))
            ->format('Y-m-d H:i:s');
        $statement = $db->prepare(
            'INSERT INTO auth_refresh_sessions
             (user_id, family_id, token_hash, token_version, expires_at, created_ip, user_agent_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $userId,
            $familyId,
            hash('sha256', $token),
            $tokenVersion,
            $expiresAt,
            self::clientIpBinary(),
            self::userAgentHash(),
        ]);
        self::setCookie($token);
        return $token;
    }

    private static function invalidateAllSessionsInTransaction(PDO $db, int $userId, string $reason): void
    {
        $db->prepare('UPDATE users SET token_version = token_version + 1 WHERE id = ?')->execute([$userId]);
        $db->prepare(
            'UPDATE auth_refresh_sessions
             SET revoked_at = UTC_TIMESTAMP(), revoked_reason = ?
             WHERE user_id = ? AND revoked_at IS NULL'
        )->execute([$reason, $userId]);
    }

    private static function newOpaqueToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    }

    public static function readToken(): ?string
    {
        // Refresh tokens are deliberately accepted only from the HttpOnly cookie.
        $value = $_COOKIE[self::COOKIE_NAME] ?? null;
        if (is_string($value) && preg_match('/^[A-Za-z0-9_-]{40,200}$/', $value)) {
            return $value;
        }

        return null;
    }

    private static function setCookie(string $token): void
    {
        setcookie(self::COOKIE_NAME, $token, self::cookieOptions(time() + self::LIFETIME_SECONDS));
    }

    public static function clearCookie(): void
    {
        setcookie(self::COOKIE_NAME, '', self::cookieOptions(time() - 3600));
        // Support the two paths used by the established direct and /api routes
        // during a deployment transition.
        foreach (array_unique(['/auth', '/api/auth']) as $path) {
            setcookie(self::COOKIE_NAME, '', self::cookieOptions(time() - 3600, $path));
        }
    }

    private static function cookieOptions(int $expires, ?string $path = null): array
    {
        $sameSite = strtolower((string)(getenv('AUTH_COOKIE_SAMESITE') ?: 'Strict'));
        $sameSite = in_array($sameSite, ['strict', 'lax', 'none'], true) ? ucfirst($sameSite) : 'Strict';
        $secure = self::isProduction() || filter_var(getenv('AUTH_COOKIE_SECURE'), FILTER_VALIDATE_BOOLEAN);
        if ($sameSite === 'None' && !$secure) {
            throw new RuntimeException('SameSite=None refresh cookies require HTTPS.');
        }

        return [
            'expires' => $expires,
            'path' => $path ?? self::cookiePath(),
            'secure' => $secure,
            'httponly' => true,
            'samesite' => $sameSite,
        ];
    }

    private static function cookiePath(): string
    {
        $configured = trim((string)getenv('AUTH_REFRESH_COOKIE_PATH'));
        if ($configured !== '' && str_starts_with($configured, '/')) {
            return $configured;
        }
        return str_starts_with((string)($_SERVER['REQUEST_URI'] ?? ''), '/api/') ? '/api/auth' : '/auth';
    }

    private static function allowedOrigins(): array
    {
        $defaults = 'http://localhost:3000,http://127.0.0.1:3000,http://localhost:8000,http://127.0.0.1:8000';
        return array_filter(array_map('trim', explode(',', (string)(getenv('ALLOWED_ORIGINS') ?: $defaults))));
    }

    private static function isProduction(): bool
    {
        return strtolower((string)getenv('APP_ENV')) === 'production';
    }

    private static function clientIpBinary(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        return filter_var($ip, FILTER_VALIDATE_IP) ? inet_pton($ip) : null;
    }

    private static function userAgentHash(): ?string
    {
        $agent = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        return $agent === '' ? null : hash('sha256', $agent);
    }
}
