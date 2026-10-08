# Cancellation consistency — fix stage 2

Local changes, 20 September 2026. Production data has not been changed.

Cancellation now locks the delivery and performs the status update, open-offer withdrawal, current-assignment closure, eligible driver release, status history and audit insert in one transaction. A mandatory history or audit failure rolls back all those changes. Notifications are sent after commit; their delivery still depends on the separate outbox/provider work.

The driver association on the cancelled delivery remains as historical evidence. Assignment rows are marked cancelled and no longer current. Previously accepted offers retain their acceptance history; open offers are withdrawn. Payment status is preserved: cancellation is not proof of a refund.

Drivers become available only when previously busy, eligible for dispatch and free of other unresolved work. Offline/paused states are preserved. Suspended or unverified drivers are moved from busy to offline. Another delivery with a recorded pickup and no completed handover prevents release even if its legacy status says failed or cancelled. Claims/manual assignments and driver availability changes also check for unresolved parcel custody.

Cancellation is rejected after pickup, including inconsistent legacy rows with a pickup timestamp but a pre-pickup status. The existing assignment-release endpoint also checks that timestamp. A return/custody-resolution workflow is still required before operations can safely close these exceptions; this stage does not invent a return event, erase proof, or mark the delivery successful.

The admin exception screen previously sent `{delivery_id, reason}` to an endpoint requiring `{delivery_id, status, status_reason}`. It now explicitly offers **Cancel before pickup**, sends the correct contract, requires a reason of at most 500 characters, and removes cancellation controls from custody/closed states. Delivery-by-phone notes cannot stand in for proof of delivery.

## Verification

```sh
php backend/tests/verify_delivery_resolution.php
cd frontend
npm test -- --watchAll=false --runInBand
npm run lint
npm run build
```

The backend test executes the actual service against in-memory SQLite and removes only MySQL's `FOR UPDATE` clause. It checks cancellation effects, financial-field preservation, repeat requests, unsafe custody states, driver eligibility and complete rollback after injected history/audit database failures. **It does not simulate MySQL locks.**

Workspace results: 48 backend cancellation/rollback checks passed using PHP 8.2.33 via WebAssembly; all 22 frontend tests passed, including the cancellation API contract/custody UI regression. Frontend lint reported no warnings and the production build compiled successfully.

Before release, test with MySQL using two connections: cancellation versus offer acceptance, cancellation versus pickup, and two cancellations involving the same driver. Verify the transaction wins completely or returns a conflict, history/audit rows commit with the state, and another parcel in custody keeps the driver busy. Also verify missing operational tables return the explicit migration-required response. No schema changes are needed for this stage if the existing operational migration is already applied.

Suggested commit:

```text
fix(deliveries): cancel atomically and preserve parcel custody

Close offers and assignments with cancellation and required audit records.
Release only eligible idle drivers and reject cancellation after pickup.
Correct the admin cancellation request and add rollback regression tests.
```
