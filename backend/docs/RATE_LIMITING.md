# Rate limiting deployment

Authentication endpoints are protected before the controller runs:

- `POST /auth/login`: 5 requests per IP / 15 minutes
- `POST /auth/register-client` and `POST /auth/register-delivery`: 3 requests
  per IP / 30 minutes
- `GET /deliveries/track`: 30 requests per IP / 5 minutes
- `POST /deliveries/confirm-receipt`: 5 requests per IP / 15 minutes

## Production requirement: Redis

Production must set these environment values before deploying:

```dotenv
APP_ENV=production
RATE_LIMIT_DRIVER=redis
REDIS_HOST=redis.internal
REDIS_PORT=6379
REDIS_PASSWORD=replace-with-a-secret
REDIS_DATABASE=0
REDIS_PREFIX=til:
REDIS_TIMEOUT=0.5
# Exact CIDRs of the proxy/CDN addresses that connect directly to PHP.
# Do not list all private networks.
TRUSTED_PROXIES=203.0.113.8/32,2001:db8:abcd::/48
# Number of comma-separated X-Forwarded-For entries: client + proxy hops.
# Set this when the deployment topology has a fixed proxy chain.
TRUSTED_PROXY_HOPS=2
# Structured slow-query event threshold in milliseconds.
SLOW_QUERY_MS=500
```

The PHP Redis extension is also required. The limiter uses one Lua operation
that increments the counter and assigns its expiry atomically. It does not
fall back to a local file or database counter when Redis is unavailable in
production; it returns `503` with a short `Retry-After` header instead. This
fail-closed behaviour prevents a Redis outage from silently disabling login
protection.

Use a private Redis network endpoint, require authentication/TLS where the
provider supports it, and do not expose Redis to the public Internet.

## Trusted proxy IP addresses

The rate limiter and audit logger use the same `ClientIp` resolver. It ignores
forwarding headers unless `REMOTE_ADDR` belongs to `TRUSTED_PROXIES` and every
intermediary address in `X-Forwarded-For` also belongs to those CIDRs. Set
`TRUSTED_PROXY_HOPS` when the chain length is fixed; incomplete or unexpected
chains fall back to `REMOTE_ADDR`. Never list all RFC-1918 networks or trust
arbitrary client-sent forwarding headers.

## Development database fallback

Without `APP_ENV=production`, the default driver is `database`. It uses the
unique `rate_limit_buckets.rate_key` row and an InnoDB transaction with
`SELECT ... FOR UPDATE`, so concurrent PHP workers cannot exceed a limit.

Provision this table during deployment, never from an HTTP request:

```powershell
cd backend
php scripts/migrate_rate_limit_buckets.php
```

For manual database deployment, use the consolidated `backend/database/schema.sql`
(or historical patch `backend/database/migrations/legacy/rate_limit_buckets_migration.sql`). The legacy
`rate_limit_entries` table is no longer read or written; it may be removed in
a separately planned database-cleanup release after confirming no older
application version still uses it.

When using the database driver, schedule the non-request cleanup daily:

```text
0 3 * * * cd /var/www/tweak-insight/backend && /usr/bin/php scripts/purge_expired_rate_limit_buckets.php
```

## Operations checks

1. Run the migration as a deployment user with DDL permission.
2. Run the web application using a least-privilege database user with no
   `CREATE`, `ALTER`, or `DROP` permission.
3. Verify a sixth login request from the same address returns `429` and a
   `Retry-After` header.
4. In production, temporarily make Redis unavailable in a staging
   environment and verify the endpoint returns `503` rather than accepting
   unlimited requests.
