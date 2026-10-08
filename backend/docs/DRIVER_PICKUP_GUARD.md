# Pickup payment guard and atomic driver milestones

Local follow-up to the R04–R12 patch, 20 September 2026. No push, deployment or live database change was performed.

**Historical stage:** the flag-only limit described below was superseded on 28 September by [final-fare approval and provider verification](FARE_AND_PAYMENT_VERIFICATION.md). Current code requires matching receipt evidence, and the expanded milestone suite has 135 assertions. The original stage record below is retained to explain its incremental patch.

## Fix completed

The driver status endpoint allowed `driver_en_route → picked_up` regardless of payment status. It also committed the status update before its history/audit writes, so required records could be missing after an apparent success.

`helpers/driver_delivery_progress.php` now locks the assigned delivery and driver profile in one transaction. Pickup requires the **stored** `payment_status` to be exactly `paid`. Unpaid, pending, refunded, waived, missing and unknown states are rejected with `409`; request fields cannot mark a parcel paid or choose its pickup time. The controller delegates both `/api/delivery-person/update-status` and its `/api/delivery/update-status` alias to this service.

Status, UTC pickup time, any eligible availability release, mandatory history and audit records commit together. Required-record failures roll back the whole change. The service requires the existing workflow tables to use InnoDB. Notification calls run after commit; notification errors are logged without reporting that the committed milestone failed.

Driver assignment and active/approved/KYC eligibility are checked again under lock. Unsupported/skipped/repeated transitions remain rejected. Delivery completion still requires the separate recipient-confirmation endpoint. A pre-pickup failure can release an otherwise idle driver; a driver with parcel custody or other active work remains busy. Inconsistent legacy custody without a pickup timestamp requires operations review.

The driver view disables pickup until the displayed payment state is `paid`, explains the block and offers a refresh button. Server rejection messages are shown and stale assignment data is refreshed. The API remains authoritative if the UI displayed an older paid state.

## Important limit and next fix

**This is a recorded-payment guard, not payment verification.** The current repository has no payment ledger, payment-verification endpoint or completed provider integration. This change does not create one, validate an actual transfer, approve a final fare, or claim that an existing `paid` flag proves funds were received.

New bookings begin as `unpaid`, so they will remain blocked at pickup until a legitimate payment flow records payment. Review this change in staging together with that deployment constraint. Do not treat changing database flags as payment verification or a production rollout procedure.

The next bounded implementation is versioned final-fare approval and server-verified payment recording: verify amount, currency, reference and delivery ownership; reconcile callbacks idempotently; persist an auditable payment record; and extend this pickup guard to require that evidence in the same transaction. Provider credentials, native MySQL tests and live integration verification remain required before production activation.

Starting the route to pickup remains possible before payment. Later payment changes do not block a parcel already in custody from progressing toward its destination. Refund authorization, COD, settlement, full failure/return resolution and durable notifications for every milestone remain separate work. This patch does not certify production readiness.

## Evidence

| File | Change |
|---|---|
| `backend/helpers/driver_delivery_progress.php` | Bounded input contract, locked payment/assignment checks, atomic milestone and required records. |
| `backend/controllers/DeliveryPersonController.php` | Calls the transactional service; sends notifications after commit; removes the old standalone availability helper. |
| `frontend/src/components/driver/ActiveDeliveriesTab.js` | Payment block, refresh action and server error feedback. |
| `backend/tests/verify_driver_delivery_progress.php` | Executes the actual service against SQLite with only `FOR UPDATE` omitted. |
| `frontend/src/components/driver/ActiveDeliveriesTab.test.js` | Driver interaction and outgoing-request contract tests. |

## Verification

- 126 backend assertions passed: unpaid/invalid payment rejection, valid pickup, forged client fields, ownership/eligibility, duplicate/skipped status rejection, custody preservation, timestamp integrity, bounded input, and rollback after injected history/audit/availability failures.
- Four new driver-view tests passed. The combined frontend suite passed all 32 tests in nine suites.
- Frontend lint and production build passed; the new/changed PHP helper, controller and test passed syntax checks.
- The incremental patch was checked against the previously delivered R04–R12 state, applied to a temporary checkout, and the reconstructed tracked files were compared with the working tree.

PHP domain checks ran through PHP 8.2 WebAssembly and SQLite. They do not establish native MySQL lock behavior or authenticated HTTP integration. Native staging must test competing pickup requests, assignment release/cancellation versus pickup, payment changes versus pickup, and permission failures through both route aliases. In particular, whichever transaction obtains the delivery lock first determines whether payment is recorded as paid at pickup.

Run from the repository root on an installed native PHP environment:

```text
php backend/tests/verify_driver_delivery_progress.php
```

Run from `frontend/`:

```text
npm run lint
npm test -- --watchAll=false --runInBand
npm run build
```

## Patch and rollback

`TIL_Fix_03_Pickup_Payment_Guard.patch` is incremental on `TIL_All_Local_Fixes_R01-R12_2026-09-20.patch` (SHA-256 `dc97a76341d2abdbd6afa21333c5d45882f7d3ef385b3db85842f9f52c671954`), or the equivalent earlier incremental sequence. It does not include the preceding changes again. Review local changes and run `git apply --check` before applying it. A pre-change checkpoint was preserved as `til-before-pickup-guard.patch`.

No schema migration is introduced. API and driver-view code belong to the same release. Keep the previous release for rollback; do not reverse recorded delivery history or alter payment flags as part of a code rollback. Follow the existing immutable-release workflow in [R04_R12_IMPLEMENTATION.md](R04_R12_IMPLEMENTATION.md).

Suggested commit:

```text
fix: block unpaid pickups and persist driver milestones atomically
```
