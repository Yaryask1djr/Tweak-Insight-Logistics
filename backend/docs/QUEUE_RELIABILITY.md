# Queue reliability — fix stage 1

Local implementation against baseline `27d62af093daf957596ecf581b958be37660c123`, 19 September 2026.
Nothing has been pushed, deployed, or migrated in a production database.

## What this stage fixes

| Defect | Result |
|---|---|
| Redis removed a job before its handler completed | Atomic Lua reservation retains the job in processing until acknowledged or recovered. |
| Worker converted Redis IDs to integers and updated MySQL | The complete reserved job identifies its backend and reservation; IDs remain strings. |
| A worker crash lost work or reset retries | Expired reservations return with the original ID and attempt count; the third unsuccessful attempt is retained as failed. |
| A stale worker could complete another worker's attempt | Completion, retry and renewal require the current, unexpired reservation. |
| Redis errors silently switched to MySQL | `QUEUE_DRIVER=redis` or `mysql` explicitly selects storage. An outage surfaces an error; no alternate store is used. |
| Runtime attempted DDL with a restricted DB account | Runtime checks required columns with SELECT. A separate additive deployment migration creates/upgrades the table. |
| Lua JSON transformations could round payload IDs | Payload JSON is preserved as opaque bytes, including legacy queued jobs. |
| Unknown handlers were logged as successful | Unknown job types fail and follow the retry policy. |

The worker separately handles handler failure and acknowledgement failure. It does not claim successful completion when persistence fails. Long audit exports periodically renew their reservation and stop if ownership is lost. A broken acknowledgement path exits nonzero so the supervisor can restart the process. Database reconnection replaces the cached connection wrapper as well as the PDO handle.

## Configuration and guarantees

- `QUEUE_DRIVER`: `mysql` (default when absent) or `redis`. Set it explicitly for **every producer and worker**. Cache and rate-limit configuration are separate.
- `QUEUE_VISIBILITY_TIMEOUT`: 30–86400 seconds, default 300. Keep every blocking DB, storage or HTTP operation below the lease; long handlers must renew before expiry.
- Redis: one primary, `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD` (legacy `REDIS_AUTH` supported), `REDIS_DB_INDEX` (legacy `REDIS_DB` supported), and `REDIS_SCHEME=tcp|tls`. Redis Cluster is not supported by these keys/scripts.
- Queue names: 1–60 letters, digits, underscores or hyphens. Colons are rejected to prevent internal namespace collisions.
- At-least-once processing: retries can repeat a handler's external side effect if it crashes after that side effect but before acknowledgement. These reservations prevent stale **queue state mutations**; they cannot undo an already sent external request. Business handlers still need stable idempotency keys or a transactional outbox.
- Redis persistence is an operational dependency. Configure an appropriate AOF/replication/backup policy and `maxmemory-policy noeviction`; isolate queue data from disposable cache data. Process-crash recovery tests do not establish durability during Redis host loss.
- Retry backoff is 30s then 120s for the default three attempts. Repeated worker crashes also consume attempts. Inspect dead-letter records; do not blindly replay a side-effecting job.

Redis uses the existing keys `queue:<name>` and `queue:delayed:<name>`, plus sorted sets `queue:processing:<name>` and `queue:failed:<name>`. A processing member contains a reservation token; its score is the authoritative lease expiry. Completed Redis jobs are removed. MySQL keeps completed/failed rows. Retention and alerting still need an operational policy.

## Safe rollout order

1. Back up queue data and stop old producers/workers during cutover. Old workers cannot safely share reservations with this version. Let active handlers finish where possible.
2. Inventory **both** stores: old automatic fallback may have left MySQL jobs even when Redis was configured. Reconcile existing completed/failed records and effects before replay; this fix cannot reconstruct Redis jobs previously removed and lost.
3. For MySQL, run `php backend/scripts/migrate_job_queue_reservations.php` using a separate migration account with CREATE/ALTER permissions. It is CLI-only, locks concurrent migration attempts, adds nullable columns/index, and is safe to rerun after interruption. It does not delete or reset existing jobs. Rehearse lock duration against a representative queue table first.
4. Return to the restricted application account. Verify the schema, run the isolated tests below, and test a real worker process interruption in staging. No production migration was executed during this implementation.
5. Set the same explicit driver and Redis database on all producers and workers; restart the worker supervisor. If old jobs remain in the other store, drain them with a separately configured worker after reconciling possible duplicate effects. Keep old workers stopped.
6. Confirm queue age, retry/dead-letter counts and worker error logs. Roll back the code only after stopping workers and accounting for processing/delayed jobs; leaving the additive schema columns is safe. Do not run the old Redis worker against new reservations.

## Verification

Run against disposable infrastructure, never the live application queue:

```sh
QUEUE_TEST_REDIS_PORT=16379 php backend/tests/verify_job_queue.php
QUEUE_TEST_REDIS_PORT=16379 python3 backend/tests/verify_redis_queue_concurrency.py

# Requires a disposable MySQL schema named, for example, til_queue_test:
QUEUE_TEST_MYSQL_DSN='mysql:host=127.0.0.1;port=3306;dbname=til_queue_test;charset=utf8mb4' \
QUEUE_TEST_MYSQL_USER='<test-user>' QUEUE_TEST_MYSQL_PASSWORD='<test-password>' \
php backend/tests/verify_job_queue_mysql.php
```

Redis tests use randomly named queues and delete only those keys; no FLUSHDB is used. The MySQL test creates a connection-local temporary queue table and refuses a schema name without the `_queue_test` suffix. It does not load application environment files. Native PHP plus phpredis worker acceptance remains a staging gate; the Redis tests use a small RESP adapter to exercise the same PHP queue class and actual Lua scripts.

Verified in this workspace:

- 61 Redis integration checks passed using PHP 8.2.33 via WebAssembly and an isolated Redis 7.2.10 built from the official source archive.
- Eight competing Redis clients reserved and recovered 240 jobs with stable IDs, no lost/duplicate reservations, and rejected stale acknowledgements.
- The earlier isolated pagination/CSV/public-tracking helper checks also executed successfully.

The MySQL integration test and deployment migration have **not** been executed: no MySQL service is available in this workspace. Redis restart/persistence recovery, native phpredis/TLS, and an end-to-end supervised worker kill/restart also require staging validation. Redis 7.2.10 here is a disposable test engine, not a production version recommendation.

## Remaining work

This closes the queue transport/reservation defects from R01 and the queue schema-provisioning defect R02 in local code. It does not claim exactly-once business effects or full production readiness. `handleExternalNotification` remains a provider integration stub: its log entry is not evidence of SMS, email or WhatsApp delivery. Notification enqueue/outbox atomicity, webhook destination/signature controls, authorized export downloads, and full readiness routing remain separate audited work.

Next bounded fix: R03 cancellation consistency — one transaction for cancellation, open offers, assignment state and driver availability, with a separate custody policy once a parcel has been picked up.

Suggested commit for this stage:

```text
fix(queue): preserve jobs across worker failures and fence acknowledgements

Reserve Redis jobs atomically and recover expired leases without resetting retries.
Keep acknowledgements on their original backend and reject stale reservations.
Add explicit queue drivers, deployment-only schema migration and recovery tests.
```
