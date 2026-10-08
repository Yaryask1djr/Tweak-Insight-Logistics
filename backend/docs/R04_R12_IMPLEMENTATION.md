# R04–R12 implementation and rollout

Updated 20 September 2026. Baseline: `27d62af093daf957596ecf581b958be37660c123`.
Changes are local on `fix/audit-reliability-20260919`. No remote push, live migration or deployment was performed.

This document records the R04–R12 stage. The subsequent [pickup payment guard](DRIVER_PICKUP_GUARD.md) adds atomic driver milestones and rejects non-paid pickup requests; actual provider verification remains open.

## Implementation status

The bounded reliability changes below are implemented in source. They are ready for review and staging verification; they do not certify the whole application as production-ready. Earlier R01–R03 fixes are preserved.

| Finding | Result and repository evidence | Remaining production gate |
|---|---|---|
| R04 — atomic booking and idempotency | `helpers/delivery_booking.php`, `helpers/booking_outbox.php`, `controllers/DeliveryController.php`, `database/schema.sql`, `scripts/migrate_atomic_booking.php`; frontend `utils/bookingRequest.js` and `components/client/BookDeliveryTab.js`. A transaction persists the request reservation, delivery, item, quote, immutable snapshot, history, audit and outbox event. Replays return the original delivery and quote. | Run the native MySQL contention/rollback test and real concurrent HTTP requests. External notification delivery has no provider implementation. |
| R05 — pricing and input bounds | `helpers/booking_input.php`, `helpers/booking_quote_inputs.php`, `DeliveryController.php`, `OperationsManagementController.php`. Typed bounds, scheduling checks, bounded rate-card inputs, no client-supplied distance override or fixed-distance fallback. Coordinate/landmark prices are explicitly provisional and require operations review. | Verify operations accepts the final fare before payment authorization. Authoritative road routing, payment reconciliation and a verified-payment-before-pickup gate remain separate work. |
| R06 — readiness | `controllers/HealthController.php`, `helpers/job_queue.php`, shared Redis connection in cache/rate limiter, `api/index.php` and host routers. Public process liveness; protected dependency readiness with DB/schema, queue, storage and worker checks. | Exercise real DB/Redis/storage outages and worker restarts on the deployed topology. |
| R07 — refresh races | `helpers/refresh_session.php`, `controllers/AuthController.php`, frontend `api/client.js`. Cross-tab Web Locks where supported; bounded conflict/retry behavior, cookie set after commit, UTC expiry checks. | Concurrent native MySQL and real-browser cookie tests, including browsers without Web Locks. |
| R08 — authorized exports | `helpers/report_export.php`, `controllers/ExportController.php`, `scripts/migrate_report_exports.php`, `scripts/expire_report_exports.php`, frontend `components/common/ReportExportButton.js`. Durable owner-scoped jobs, bounded keyset reads, fenced leases, private files and expiring authenticated downloads. | Shared private storage, worker interruption and real HTTP authorization checks; daily retention job. |
| R09 — server-side listing controls | `helpers/listing_query.php`, `controllers/ReportingController.php`, delivery/admin/operations controllers; admin Deliveries, Reports, Exceptions, Operations and client Payments views. Search/filter/sort/pagination run on the server for these lists; aggregates cover their defined scope instead of the displayed sample. | MySQL query plans and load tests at realistic volume. This is not a claim that every legacy listing has been redesigned or load-tested. |
| R10 — offer/tracking privacy | `controllers/DeliveryPersonController.php`, `helpers/tracker_helper.php`, frontend `components/driver/AvailableJobsTab.js`. Offer previews expose city-level routing and package category; exact addresses, contacts, notes and tokens are withheld until assignment. Legacy short public tracking references are rejected. | Test each role through the HTTP routes. Existing users with legacy references need their high-entropy tracking link. |
| R11 — webhook security | `helpers/webhook_dispatch.php`, `scripts/queue_worker.php`. Fixed server-configured HTTPS destinations, global-address checks, pinned DNS, HMAC signatures, bounded request/response sizes and timeouts; queue retries retain attempts. | Receiver signature/freshness/deduplication tests and an approved destination in staging. Empty configuration disables integrations. |
| R12 — atomic frontend release | Frontend `scripts/deploy-backend-build.js`; `helpers/frontend_release.php`, PHP entry points and `public/.htaccess`. Immutable release directories, integrity verification, separate preparation/activation, atomic pointer switch and retained rollback assets. | Apache/Windows filesystem behavior, CDN caching and old browser tabs. Backend deployment and migrations still require coordinated operator execution. |

Paths in this table are relative to `backend/` unless explicitly prefixed with frontend.

## Booking contract

Send `Idempotency-Key` with every `POST /api/deliveries/create`. The key must contain 16–128 ASCII letters, digits, hyphens or underscores. The frontend generates a random 128-bit key and retains it in session storage, scoped to the signed-in user and canonical request payload, until a successful response. Storage failure prevents submission; it does not silently generate a new key for each retry.

- Scope is `(client_id, delivery.create.v1, SHA-256(key))`; another client may use the same key independently.
- The server hashes the submitted JSON object. Object field order is ignored; array order, values and JSON types are significant. Resend the same payload when the first response is lost.
- An identical committed request returns the original response data with HTTP `201` and `Idempotency-Replayed: true`. Its price is not recalculated, and schedule freshness is not rechecked on replay.
- A key reused with different data returns `409`. Invalid input or key returns `422`; oversized JSON returns `413`. A failed transaction leaves no partial booking and may safely be retried.
- Requests and original snapshots are retained; no automatic idempotency-key expiry is introduced. Do not purge them without a separate retention and retry contract.
- MySQL tables participating in booking and inbox projection must be InnoDB. The migration installs snapshot UPDATE/DELETE rejection triggers, including on fresh installations. Application code checks the table engines before writing.

The browser retains unresolved keys within that tab's session; closing the tab or clearing its storage loses that local retry record. After a lost response in that situation, inspect existing bookings before creating another. Server idempotency protects retries that actually reuse their key, not arbitrary duplicate requests with different keys.

The default worker processes committed `booking_outbox` events in a second transaction, inserting client and active-admin inbox messages with their processed marker. No notification network call runs inside the booking transaction. Failed projections roll back, retry with a delay and remain recorded after ten attempts.

`booking_outbox.status = processed` means the **in-app inbox projection** completed. `external_delivery_status = unconfigured` explicitly records that SMS, WhatsApp, email and push were not delivered. The old external-dispatch stub now fails visibly instead of claiming delivery. Existing queued external jobs may therefore exhaust retries until a real provider adapter is added.

## Input and quote behavior

Requests are limited to 64 KiB JSON objects. Quantity is an integer from 1–50; explicit weight is numeric from 0.1–1,000 kg; text fields and rate-card amounts are bounded. Coordinates must be supplied in pairs and within global numeric bounds. Scheduled pickup must be future-dated within 30 days, with delivery after pickup. Offset-free form times use Africa/Lagos; persisted schedule and database-session times use UTC.

Distance uses known landmark coordinates or supplied pins and remains an approximation. Unresolved routes return a validation error instead of accepting a supplied distance or inventing 6.5 km. Package-size weight defaults remain estimates. Pin validation is not authoritative geofencing or route validation. The immutable original quote is provisional; later approved pricing should be a separate version, never an edit to that original snapshot.

Before changing a production database session to UTC, confirm how historical `DATETIME` values were written and displayed. This patch does not rewrite historical timestamps.

## Health, workers and integrations

- `GET /api/health` and `/api/health/live`: process liveness only.
- `GET /api/health/ready`: send a configured `X-Health-Token` matching `HEALTH_CHECK_TOKEN` (at least 32 characters; placeholders rejected). Returns `200` when required checks pass, `503` otherwise; unauthorized probes return `403`.
- Readiness checks the selected queue backend directly. Redis failure does not become a healthy fallback queue. It also checks snapshot triggers, export columns, failed booking events, a local storage round trip, configured S3 probe existence and a recent default-worker heartbeat.
- At least one supervised worker must process `--queue=default` for outbox events and report jobs. The current heartbeat is a shared local JSON file. Validate placement/visibility for API and worker hosts; per-worker fleet health aggregation is not implemented here.
- Configure `HEALTH_STORAGE_PROBE_KEY` to an existing private object when using S3. Export artifacts still use private filesystem storage, so API and workers need the same storage mount and compatible permissions.
- `WEBHOOK_ENDPOINTS_JSON` maps endpoint IDs to fixed HTTPS URLs and independent signing secrets of at least 32 bytes. Jobs supply `endpoint_id`, stable `event_id`, and `data`; arbitrary job URLs are ignored. Requests sign `timestamp + "\n" + event_id + "\n" + body` with HMAC-SHA256. Receivers must verify the signature and timestamp and deduplicate event IDs. Production delivery is at least once.

Refresh overlaps from the same user agent receive `409` for at most five seconds after rotation, without a new token or cookie deletion. The account, token version and expiry must still match. A retry outside the bounded overlap, or other revoked-token reuse, retains the compromise-response behavior. This is a race mitigation, not proof that a user-agent string identifies a browser.

## Export and listing contract

Authenticated admins with `operations.audit.read` can create reports:

| Method and route (under `/api`) | Result |
|---|---|
| `POST /admin/audit-log/export` | Audit report job; `/admin/audit-logs/export` remains an alias. |
| `POST /admin/reports/deliveries/export` | Delivery report job. |
| `GET /admin/exports/status?id=…` | Owner-only state, row count, completion/expiry and safe error message. |
| `GET /admin/exports/download?id=…` | Owner-only CSV when ready. Other owners receive `404`; expired requests receive `410`; incomplete jobs receive `409`. |

Jobs expire 24 hours after request. The worker reads 500 rows per page, renews its lease and writes a private file; it never hands out a public storage URL. Audit action filters match the listing's substring search. CSV formula cells are escaped. Legacy synchronous CSV calls reject results above 10,000 rows with an explicit error directing the user to asynchronous export.

The export's maximum ID excludes rows inserted after the request. Mutable delivery values may change while later pages are read: this is **not** a transactionally frozen financial statement. Use a dedicated accounting ledger/snapshot design for that requirement. The job survives browser reloads, but the current button does not provide a persistent export-history screen.

Run `php scripts/expire_report_exports.php` daily from `backend/`. It expires metadata and removes eligible old files, including abandoned artifacts. Monitor failed export states and outbox rows rather than relying only on queue depth.

New summary/list endpoints include `/admin/reports/summary`, `/admin/exceptions` and owner-scoped `/deliveries/payment-summary`. Updated list controls enforce page/size bounds and allowlisted sorts. Expensive wildcard searches and whole-scope counts still need representative MySQL query-plan measurement; no throughput numbers are claimed.

## Staging and rollout sequence

These commands are instructions for the operator; they were **not run against a live environment**.

1. Review the diff, restore-test a database backup, and use an isolated staging copy without production credentials. Install the repository's locked Composer/npm dependencies and required native PHP extensions. The local verification runtime was PHP 8.2.33 via WebAssembly and Node 24.19; confirm compatibility on the target PHP 8.2/XAMPP runtime.
2. Establish the canonical existing schema and earlier migrations. With a dedicated migration account, run the additive scripts below from `backend/`. Runtime app credentials should not have DDL privileges. The booking migration must run even if `schema.sql` created its tables, because it installs the immutability triggers.

   ```text
   php scripts/migrate_job_queue_reservations.php
   php scripts/migrate_atomic_booking.php
   php scripts/migrate_report_exports.php
   ```

3. Validate the configured queue, private storage, health token, UTC handling and secrets. Serve the application at the host root with Apache DocumentRoot set to `backend/public`; release URLs use `/releases/…`. Enable the repository's rewrite rules and keep source/storage outside the document root. Keep optional webhooks disabled until the receiver contract is tested. Existing snapshot/history and booking data are not backfilled by these migrations.
4. Deploy the matching API and frontend together through a controlled release window. Old booking clients do not send the required header and will receive `422`; require a browser reload. Restart supervised default workers after deploying their code. Reapply the runtime least-privilege account after migrations.
5. From `frontend/`, run `npm run build:backend` (`npm.cmd` on Windows if PowerShell blocks npm's script shim). This **prepares only** and prints a release ID. Review the prepared release and staging checks before a separate operator activation:

   ```text
   node scripts/deploy-backend-build.js --activate=RELEASE_ID
   ```

   The script verifies hashes and switches `.active-release.json` by same-filesystem rename. If replacement fails, it keeps the old pointer. No destructive delete-then-rename fallback is used.
6. Confirm liveness, authorized readiness and the critical flows below. Configure daily export retention and alerts on outbox failures, report failures, queue dead letters and stale workers.
7. To roll back the frontend, use `node scripts/deploy-backend-build.js --rollback=PREVIOUS_RELEASE_ID`. Keep the additive database tables and coordinate API compatibility; a frontend pointer switch does not roll back backend code or data. Old assets are retained. Optional `--prune` defaults to 30 days, refuses less than seven days and preserves current/previous releases. Set retention to cover the actual supported browser-session/cache window before pruning.

## Verification evidence

| Check | Local result | Limit |
|---|---|---|
| PHP syntax | 106 PHP files passed | PHP 8.2 WebAssembly runtime; not a native HTTP deployment. |
| Atomic booking/outbox | 113 checks passed | SQLite dialect adapter checks records, rollback at each write, replay, conflict, scope and post-commit projection. It does not simulate MySQL locks. |
| Runtime boundaries | 66 checks passed | Pricing/input, readiness heartbeat, refresh-race predicate, listing and webhook IP/signature boundaries. No live webhook request. |
| Refresh and exports | 18 checks passed | Actual refresh rotation/expiry/replay and private exports across 1,101 rows, filter parity, ownership, expiry and failed-owner handling on SQLite. |
| Cancellation | 48 checks passed | SQLite rollback/domain checks; native competing transactions remain a gate. |
| Pagination/CSV/tracking helpers | Passed | Isolated helper checks. |
| Redis queue | 61 checks passed; eight concurrent clients recovered 240 jobs without duplicates | Real Redis 7.2.10 via test RESP client. Native PHP Redis adapter/process-restart deployment still needs staging. |
| Redis readiness | Passed | Actual selected-queue round trip, queue metrics and outage behavior. |
| Frontend | 28 tests in eight suites passed; lint and production build passed | Browser visuals, real cookies and native end-to-end routes were not exercised. |
| Release script | Prepare/activate/rollback, integrity and lock checks passed; a 70-file production build was prepared and verified in a temporary directory | No live release was activated. Target Apache/Windows/CDN tests remain. |

Native booking concurrency test is supplied as `tests/verify_atomic_booking_mysql.php`. It requires native PHP with PDO MySQL and `proc_open`, plus an **empty disposable database whose name ends in `_booking_test`**. It creates fixtures, launches six concurrent PHP clients, checks one complete booking and identical responses, injects a history failure and tests snapshot triggers. It leaves the fixture database for inspection. This test has not executed here.

Example PowerShell setup after an operator creates the empty test database and a test-only account:

```powershell
$env:BOOKING_TEST_MYSQL_DSN = 'mysql:host=127.0.0.1;dbname=til_booking_test;charset=utf8mb4'
$env:BOOKING_TEST_MYSQL_USER = 'til_test'
# Set BOOKING_TEST_MYSQL_PASSWORD securely in this process before running.
php tests/verify_atomic_booking_mysql.php
```

Also run the earlier `tests/verify_job_queue_mysql.php` using the setup in [QUEUE_RELIABILITY.md](QUEUE_RELIABILITY.md). Inspect PASS output and the exit status; do not substitute the SQLite test for these gates.

Required staging scenarios: simultaneous identical/conflicting bookings; lost booking responses; killed worker during outbox/export work; expired export lease recovery; every role attempting another owner's export; two real browser tabs refreshing concurrently; DB/Redis/storage outage and recovery; legacy/public tracking and unassigned offer privacy; prepared-release activation/rollback with an already open old tab. Capture MySQL query plans and p95 latency at representative data size before setting performance targets.

## Next bounded production stage

First close the native integration and rollout gates above. Then implement final-fare approval and verified payment before pickup: persist versioned approval, reconcile provider callbacks idempotently, enforce the payment/custody transition under a transaction, and test replay, failure and refund paths. COD accounting, a complete return/custody-resolution workflow and real external notification providers remain open features. Neither payment flags nor configuration placeholders prove those capabilities exist.

Suggested commit message:

```text
fix: make bookings atomic and harden logistics operations

- persist scoped booking idempotency, immutable snapshots and an outbox
- bound pricing inputs and expose dependency readiness
- handle refresh races and authorize durable report exports
- move listing controls server-side and limit tracking/offer disclosure
- secure outbound webhooks and prepare atomic frontend releases
- add regression checks, migrations and rollout guidance
```

## Applying the delivered patches

- `TIL_All_Local_Fixes_R01-R12_2026-09-20.patch` is the cumulative patch for the exact baseline commit above, including the earlier audit corrections.
- `TIL_R04-R12_Implementation_Fixes.patch` is incremental on the previously supplied `Tweak_Insight_Logistics_All_Local_Fixes_2026-09-20.patch` (or the equivalent initial audit + queue + cancellation sequence).
- Apply the appropriate cumulative patch **or** the incremental sequence, not both. Review existing local changes and run `git apply --check PATH_TO_PATCH` on the intended base before `git apply PATH_TO_PATCH`. No patch automatically migrates or activates the application.
