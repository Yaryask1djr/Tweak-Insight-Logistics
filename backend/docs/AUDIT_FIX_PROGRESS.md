# Audit fix progress

Updated 28 September 2026. Baseline commit: `27d62af093daf957596ecf581b958be37660c123`.
All changes remain local on `fix/audit-reliability-20260919`; no push or deployment occurred.
This progress record updates the outstanding-fix list in the 19 September enterprise audit.

| Order | Finding | Local implementation | Verification / remaining gate |
|---|---|---|---|
| 1 | R01 — lost Redis jobs and wrong-backend acknowledgements | Atomic reservation, lease recovery, persisted retries, dead letters, stale-worker rejection, explicit backend selection, exact payload preservation. | 61 checks against Redis; 8 concurrent clients and 240 recovered jobs. Native worker/Redis persistence staging tests remain. Booking outbox is now implemented in R04; external notification providers and broader handler-specific idempotency remain separate work. |
| 2 | R02 — runtime queue table creation | Runtime DDL removed; read-only schema check and additive deployment migration provided. | PHP lint passed. MySQL migration and integration test require a disposable MySQL service; not executed here. |
| 3 | R03 — inconsistent cancellation and unsafe custody release | Transactional cancellation, offer/assignment cleanup, eligible availability update, required history/audit, post-pickup cancellation/release guards, corrected admin cancellation UI. | 48 rollback/domain checks on SQLite; frontend API-contract test passed. MySQL competing-transaction staging tests remain. Full return/custody-resolution workflow is still needed. |
| 4 | R04 — booking transaction and idempotency | Scoped key/request hash, all mandatory booking records in one transaction, protected original snapshot, durable post-commit inbox outbox. | 113 SQLite checks; native six-client MySQL test supplied but not run. External delivery stays explicitly unconfigured. |
| 5 | R05 — pricing/input bounds | Typed bounded input, scheduling windows, bounded rate cards, no client distance/fixed-distance fallback, provisional quote labels. | 66 combined runtime-boundary checks. Final-fare approval and verified-payment evidence are now implemented in follow-up 14; authoritative routing and native/provider staging gates remain. |
| 6 | R06 — readiness routing | Protected DB/schema/queue/storage/worker readiness; separate public liveness; shared Redis connection. | Direct Redis readiness checks passed; real multi-service outage and host-topology tests remain. |
| 7 | R07 — refresh races | Cross-tab locking, bounded conflict handling, post-commit cookies, UTC expiry. | Frontend race tests and actual SQLite rotation/late-replay/expiry checks passed. Native DB and browser-cookie concurrency remains. |
| 8 | R08 — authorized exports | Durable owner jobs, private expiring downloads, keyset generation, fenced leases, retention, no silently truncated large CSV. | 18 combined refresh/export checks, including 1,101 rows and matching audit filters. HTTP authorization and native worker recovery gates remain. |
| 9 | R09 — listing controls | Server search/filter/sort/page controls for targeted admin/client lists, bounded rate cards and drivers, scoped aggregate endpoints. | Frontend regression suite passed. Representative query-plan/load tests remain; not every legacy view was redesigned. |
| 10 | R10 — offer/tracking privacy | Minimal pre-assignment offers and rejection of guessable legacy public references. | Privacy helper checks passed; role-by-role HTTP smoke test remains. |
| 11 | R11 — webhook security | Configured HTTPS endpoints, public IP/DNS pinning, HMAC, size/time limits and durable queue retries. | Local IP/signature boundaries passed. Live receiver and deduplication integration remains. |
| 12 | R12 — atomic frontend release | Immutable assets, separate prepare/activate, integrity checks, atomic pointer and retained rollback releases. | Release-script checks and real build preparation passed in temporary storage. No activation; Apache/Windows/CDN staging remains. |
| 13 | Follow-up — unpaid pickup and non-atomic driver milestones | Locked driver eligibility, atomic status/pickup/availability/history/audit, driver payment block and refresh control. | Expanded to 135 backend assertions; the guard now requires the provider receipt implemented in follow-up 14. Native MySQL contention remains a release gate. |
| 14 | Follow-up — final-fare approval and provider verification | Versioned immutable fares, exact kobo, owned/reused checkout references, real Paystack Initialize/Verify adapter, immutable receipts, signed durable inbox, immediate refund/dispute holds, evidence-based pickup and admin/client controls. | 83 payment assertions, 39 frontend tests in 11 suites, lint and production build passed. Provider simulated in tests; native MySQL contention script supplied but not run. Credentials, actual sandbox checkout/webhook and authenticated staging acceptance remain. |

Detailed changes, rollout steps and suggested commits:

- [Queue reliability](QUEUE_RELIABILITY.md)
- [Cancellation consistency](CANCELLATION_CONSISTENCY.md)
- [R04–R12 implementation, contracts and rollout](R04_R12_IMPLEMENTATION.md)
- [Pickup guard and atomic driver milestones](DRIVER_PICKUP_GUARD.md)
- [Final fares, Paystack verification and rollout](FARE_AND_PAYMENT_VERIFICATION.md)

The earlier audit fixes are preserved. The revised frontend passes all 39 tests in 11 suites, lint and production build. Backend domain tests use PHP 8.2.33 via WebAssembly/SQLite; earlier Redis checks used a real Redis 7.2.10 server. Native MySQL locking, full authenticated HTTP flows and live providers are not certified by those results.

## Next bounded stage

Run the supplied native MySQL contention tests and the real Paystack test-mode checkout/webhook walkthrough, followed by authenticated staging rollout/recovery checks. The payment path now exists locally; deployment requires the additive migration, private provider configuration and matching worker/API/frontend release. Unpaid or unverified bookings remain blocked. After this gate, implement audited refund and hold resolution. COD, returns and external notifications remain separate features. The system is not certified production-ready.

## Patch order

`TIL_Fix_01_Queue_Reliability.patch` and `TIL_Fix_02_Cancellation_Consistency.patch` are incremental patches on top of the previously supplied `Tweak_Insight_Logistics_Audit_Fixes.patch`; apply queue first, then cancellation.

`Tweak_Insight_Logistics_All_Local_Fixes_2026-09-20.patch` is the earlier cumulative patch through R03. Add `TIL_R04-R12_Implementation_Fixes.patch` on that state to obtain this revision.

Alternatively, `TIL_All_Local_Fixes_R01-R12_2026-09-20.patch` contains the complete local change set against the baseline commit, including the initial audit corrections and R01–R12. Apply either that cumulative patch or the incremental sequence, never both. Patch application is checked on each intended starting state and reconstructed files are compared with the working tree.

The subsequent `TIL_Fix_03_Pickup_Payment_Guard.patch` applies on that R04–R12 state. It contains only this follow-up and is not already included in the earlier cumulative patch.

`TIL_Fix_04_Fare_Approval_Paystack_Verification.patch` applies next, on the pickup-guard state. Alternatively, `TIL_All_Local_Fixes_With_Payments_2026-09-28.patch` contains the full sequence against the original baseline. These are alternatives, not patches to apply together.
