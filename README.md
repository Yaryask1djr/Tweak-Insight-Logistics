# Tweak Insight Logistics

## Standard project structure

- `frontend/` is the only user interface. It contains the landing page, client, driver, and operations dashboards.
- `backend/` is the PHP API, database access, authentication, workflow rules, and production web server.
- `backend/public/` receives compiled React files. The legacy PHP pages are retained temporarily only as a rollback reference and are not served once a React build is present.

This removes the former two-frontend setup: port 3000 is for React development only; port 8000 is the PHP API and, after a production build, the complete web application.

## Development

1. Configure the backend database settings in `backend/.env`.
2. From `frontend`, run `npm install` once.
3. Start both development services with `npm run dev`.
4. Open `http://localhost:3000`. The React application calls the API at `http://localhost:8000`.

## Standard local deployment

From `frontend`, run:

```powershell
npm run build:backend
cd ../backend
php -S localhost:8000 router.php
```

Open `http://localhost:8000`. The router serves the React app for all user-facing routes and keeps `/api/*` for PHP endpoints. Legacy PHP URLs redirect to their React equivalents.

## Production document-root requirement

The production web server **must** use `backend/public/` as its document root.
Do not point Apache, Nginx, a hosting-panel site root, or a CDN origin at
`backend/` or at the repository root. KYC documents, logs, configuration,
database scripts, and PHP source are deliberately outside `backend/public/`.

`backend/.htaccess` and `backend/router.php` also deny those paths as a
defence-in-depth fallback, but that is not a substitute for a correct document
root. Follow [the web-server security deployment guide](backend/docs/WEB_SERVER_SECURITY.md)
before deploying or after changing hosting providers.

Before production access is enabled, complete the
[credential rotation and application-account guide](backend/docs/CREDENTIAL_ROTATION.md).

## Database architecture

The clean-install workflow schema is [backend/database/schema.sql](backend/database/schema.sql). For an existing database, apply its safe additive migration after taking a database backup:

```powershell
cd backend
php scripts/migrate_operational_architecture.php
php scripts/migrate_performance_indexes.php
php scripts/migrate_rate_limit_buckets.php
php scripts/migrate_auth_refresh_sessions.php
php scripts/migrate_document_security.php
```

It adds client and driver profiles, document/KYC records, driver availability, Kano zones and hubs, business accounts, rate cards, offer and assignment records, status history, item and price snapshots, proof, notifications, and audit logs. It does not delete existing users or delivery records. See [backend/database/ARCHITECTURE.md](backend/database/ARCHITECTURE.md) for the workflow mapping.

`migrate_performance_indexes.php` is separately idempotent and adds only the
indexes used by delivery history, driver work queues, operations dashboards,
public tracking, GPS-event reads, and notification inbox queries.

`migrate_rate_limit_buckets.php` provisions the atomic database fallback for
development. Production authentication rate limiting requires Redis; see
[backend/docs/RATE_LIMITING.md](backend/docs/RATE_LIMITING.md) before release.

Production KYC documents require a private S3-compatible object-storage bucket,
server-side encryption, ClamAV scanning, an explicit retention period, and the
daily deletion task described in [the document-storage deployment guide](backend/docs/DOCUMENT_STORAGE.md).
Local disk storage is available only outside production.

Browser access tokens are short-lived and memory-only. A rotating opaque
refresh token is stored in an HttpOnly production-secure cookie; apply
`migrate_auth_refresh_sessions.php` before release and follow
[the browser-session deployment guide](backend/docs/AUTH_SESSIONS.md).

## Backend authorization

The API authorizes every protected operation from the current database role and
a named server-side permission—not from React navigation state. The three
supported roles are client, delivery partner, and admin. See
[backend/docs/AUTHORIZATION.md](backend/docs/AUTHORIZATION.md) for the exact
role matrix and the Laravel Sanctum/Spatie migration boundary.

## Admin and driver operations

The platform now includes KYC document storage and review, driver availability
controls, pre-pickup assignment release/rebroadcast rules, and an operational
audit feed. See [backend/docs/OPERATIONS_WORKFLOW.md](backend/docs/OPERATIONS_WORKFLOW.md)
for the lifecycle and secured API contract.

## In-app notifications

The delivery workflow now creates persisted, role-scoped in-app notifications
after approved request, assignment, and delivery-status changes. See
[backend/docs/NOTIFICATIONS.md](backend/docs/NOTIFICATIONS.md) for the event
catalogue, inbox endpoints, database-migration requirement, and safe future
email/SMS/push expansion path.

## Performance and CDN readiness

The React application uses route-level code splitting, WebP logo assets,
paginated dashboard APIs, skeleton loading states, and long-lived cache headers
for fingerprinted static files. Dynamic API and HTML responses are explicitly
not cacheable. See [backend/docs/CDN_DEPLOYMENT.md](backend/docs/CDN_DEPLOYMENT.md)
before connecting a Cloudflare account or another CDN.

## Known implementation gaps before production

- Confirm the final commercial rate schedule. One backend calculator now powers estimates and creation, but the earlier requested business, service-type, and weight modifiers need unambiguous signed-off values before changing the current configuration.
- Connect the new persisted KYC, business-account, rate-management, proof, and audit tables to their operations endpoints and dashboards. The notification inbox is connected to the current delivery lifecycle; its KYC/document trigger awaits document-review endpoints.
- Replace text-only Kano address checks with geocoding/service-zone validation and use the same zone rule for pricing and dispatch.
- Require authenticated confirmation for proof of delivery, add proof artefact storage, and protect tracking data with a public tracking token.
- Turn the GPS feed into a map view with retention, accuracy, and access-control rules.
