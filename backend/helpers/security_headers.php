<?php

/**
 * Common response security headers — emitted by PHP for the dev server and for
 * every API JSON response.  The directives below MUST stay byte-synchronized
 * with backend/.htaccess "Security Headers" block so that non-PHP static assets
 * and PHP endpoints return identical policy.
 */
class SecurityHeaders
{
    public static function send(): void
    {
        if (headers_sent()) {
            return;
        }

        self::enforceHttpsRedirect();

        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload', true);
        header('X-Content-Type-Options: nosniff', true);
        header('X-Frame-Options: DENY', true);
        header('X-XSS-Protection: 1; mode=block', true);
        header('Referrer-Policy: strict-origin-when-cross-origin', true);
        header('Permissions-Policy: geolocation=(self), camera=(self), microphone=()', true);
        header('X-Permitted-Cross-Domain-Policies: none', true);
        header('Content-Security-Policy: ' . self::contentSecurityPolicy(), true);
    }

    /**
     * In production, when TLS is not terminated at a proxy/CDN we still need to
     * send a HTTP->HTTPS redirect.  Proxies that terminate TLS (Cloudflare,
     * ALBs) typically add X-Forwarded-Proto; we trust that header only when the
     * header itself is set by a trusted upstream.
     */
    private static function enforceHttpsRedirect(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        if (strtolower((string)getenv('APP_ENV')) !== 'production') {
            return;
        }

        $forwardedProto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        $isHttps =
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443)
            || $forwardedProto === 'https';

        if (!$isHttps) {
            $host = $_SERVER['HTTP_HOST'] ?? '';
            $uri  = $_SERVER['REQUEST_URI'] ?? '/';
            header('Location: https://' . $host . $uri, true, 301);
            exit;
        }
    }

    /**
     * Single source of truth for Content-Security-Policy.
     * Any change here must be repeated in backend/.htaccess (Lines 60 CSP line).
     */
    private static function contentSecurityPolicy(): string
    {
        $policy = [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "form-action 'self'",
            "script-src 'self'",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "font-src 'self' data: https://fonts.gstatic.com",
            "img-src 'self' data: blob: https://*.tile.openstreetmap.org",
            "connect-src 'self'",
            "worker-src 'self' blob:",
            "manifest-src 'self'",
        ];

        // Upgrade insecure requests is always active in production, active in
        // non-production only when running behind a HTTPS proxy so devs can
        // test mixed-content warnings locally if desired.
        $isProd = strtolower((string)getenv('APP_ENV')) === 'production';
        $behindTlsProxy = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        if ($isProd || $behindTlsProxy) {
            $policy[] = 'upgrade-insecure-requests';
        }

        return implode('; ', $policy);
    }
}
