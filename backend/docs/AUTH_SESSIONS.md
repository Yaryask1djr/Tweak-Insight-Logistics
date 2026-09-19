# Browser authentication sessions

The browser authentication model keeps long-lived credentials out of
JavaScript-accessible storage.

- An access JWT has a 15-minute lifetime, `typ=access`, and is held only in the
  running JavaScript module. It is sent in the `Authorization` header.
- Login sets `TIL_REFRESH`, a 30-day opaque refresh value in an `HttpOnly`,
  `Secure` (production), `SameSite=Strict` cookie. The database stores only its
  SHA-256 hash.
- `POST /auth/refresh` requires the cookie and `X-Requested-With:
  XMLHttpRequest`, issues a new access JWT, and rotates the refresh value.
- A refresh value that was already rotated is considered token reuse. The
  application revokes all sessions and increments `users.token_version` for
  that account.
- Logout and password changes revoke all stored refresh sessions and advance
  `token_version`, which immediately invalidates issued access tokens.

## Deployment

Apply the additive migration before releasing the frontend:

```powershell
cd backend
php scripts/migrate_auth_refresh_sessions.php
```

Production needs HTTPS and the following explicit values:

```dotenv
APP_ENV=production
AUTH_COOKIE_SECURE=true
AUTH_COOKIE_SAMESITE=Strict
ALLOWED_ORIGINS=https://app.example.com
# Exact CIDRs of reverse proxies/CDN nodes which connect directly to PHP.
TRUSTED_PROXIES=203.0.113.8/32,2001:db8:abcd::/48
```

The normal deployment serves React and the API from one HTTPS origin. If the
frontend must call an API on a different *site*, set `AUTH_COOKIE_SAMESITE=None`
only together with HTTPS, keep `ALLOWED_ORIGINS` to an exact allow-list, and
update the CSP's `connect-src` to that exact API origin. Do not use wildcard
origins or `Access-Control-Allow-Origin: *` with credentials.

Schedule cleanup outside the request path:

```text
15 3 * * * cd /var/www/tweak-insight/backend && /usr/bin/php scripts/purge_expired_auth_refresh_sessions.php
```

## Content Security Policy

`backend/public/.htaccess` serves the production policy. It permits only the
application bundle, the existing Google font sources, OpenStreetMap tile images,
and same-origin API connections. It intentionally blocks inline scripts,
third-party scripts, object/embed content, and framing by other sites.

The inline theme bootstrap was removed from `frontend/public/index.html` so the
application can run without `script-src 'unsafe-inline'`.
