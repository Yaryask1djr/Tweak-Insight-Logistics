# Final fares and Paystack payment verification

Completed locally, 28 September 2026, against `27d62af093daf957596ecf581b958be37660c123` plus the earlier audit and pickup fixes. No GitHub push, live migration, deployment or real charge was performed.

## Delivered behavior

Operations approves a final NGN fare after the booking enters review and before pickup. Each approval has a version, exact integer kobo amount, shipment fingerprint, actor, reason and timestamp. The original booking quote and item snapshot remain intact. A stale approval returns `409`; an identical lost-response retry returns the original approval. Approval and its required audit commit together.

The owning client accepts the current fare version and requests hosted Paystack checkout. The server supplies the amount, currency, payer email, callback URL and shipment metadata. A transaction reserves one reference per fare/environment before contacting Paystack. Concurrent initialization is leased; retries reuse that reference and any stored checkout URL. A timeout never creates a replacement reference or records payment. Once checkout starts, fare changes require reconciliation rather than silently charging a revised amount.

The server calls Paystack's **Verify Transaction API**. Only `data.status = success`, with matching reference, environment, amount, NGN currency, payer email, four metadata bindings, valid transaction ID and payment time, can create a receipt. The top-level API status, browser query parameters and webhook success claims cannot mark a booking paid. Receipt insertion, the recorded paid flag and mandatory audit are atomic. Unique receipt/reference/provider-transaction constraints prevent duplicate financial records.

Pickup requires the paid flag **and** an immutable receipt matching the current approved fare, unchanged shipment, owner, delivery, environment and a verified, unheld attempt. This check runs under the pickup transaction. The driver listing exposes only a clearance boolean; it does not expose checkout URLs, provider references or payer details. Missing evidence, legacy flags and production test receipts all fail closed. Existing custody milestones can continue even if a later financial hold appears.

## Webhooks and recovery

`POST /api/payments/paystack/webhook` validates the exact raw request body using Paystack's HMAC-SHA512 signature before parsing or writing. Bodies are limited to 256 KiB. A valid charge-success event is acknowledged only after its deduplicated inbox record commits. The default queue worker then calls the Verify API outside a database transaction.

Signed refund/dispute events immediately commit a payment hold with the inbox record and required audit, serializing against pickup on the delivery lock. Refund events use `transaction_reference`; dispute events can use `transaction.reference`. A provider transaction ID can resolve an existing receipt. Unresolved relevant events return `503` for provider retry; authenticated references belonging to other applications are ignored. Neither a success replay nor a refund-failed/dispute-resolved event automatically removes a hold.

Workers use 60-second fenced reservations, expired-lease recovery, capped exponential retry delays and a maximum of ten attempts. Exhausted events become `failed`; failed or over-15-minute pending/processing events make readiness unhealthy. Unknown initialization outcomes remain pending against the same reference. Customers can use **Check payment with Paystack** to recover missing callbacks. An operator can also run a verification-only command:

```text
php backend/scripts/verify_payment.php --reference=TILPAY-<32-hex-characters>
```

That command calls Verify, records valid evidence or a hold, and never initializes checkout. It does not clear a hold or remove failed inbox records. For exhausted events, investigate the configured environment/provider/DB error first. A controlled operator transaction may requeue the **specific reviewed failed event** by setting `status = 'pending'`, `attempts = 0`, `available_at = UTC_TIMESTAMP()` and clearing its reservation/error fields; retain an operator audit and do not mass-reset events. Do not edit fare/receipt facts or switch paid flags to bypass pickup.

## Main implementation

| File | Responsibility |
|---|---|
| `helpers/payment_money.php` | Decimal-to-kobo conversion without floating-point money; NGN 1.00–10,000,000.00 application bounds. |
| `helpers/paystack_gateway.php` | Fixed HTTPS provider origin, strict hosted URL, secret/mode checks, TLS verification, bounded response and connection/request timeouts. |
| `helpers/delivery_payments.php` | Fare approval, checkout reservation, verification reconciliation, ownership, holds and pickup evidence. |
| `helpers/payment_webhooks.php` | Signed durable inbox, immediate risk holds and retrying worker. |
| `controllers/PaymentController.php` | Bounded typed requests and sanitized HTTP errors. |
| `scripts/migrate_delivery_payments.php` | Four InnoDB tables and four immutable-fare/receipt triggers; additive, rerunnable migration under an advisory lock. |
| `helpers/driver_delivery_progress.php` | Payment evidence inside the atomic pickup transaction. |
| `frontend/src/components/common/PaymentReviewPanel.js` | Operations approval and customer checkout/verification controls. |
| `frontend/src/components/PaymentReturn.js` | Authenticated verification after return from Paystack; query status is ignored. |

Backend paths above are relative to `backend/`. Frontend paths are repository-relative. Drivers receive `pickup_payment_verified` from their assignment listing. No new frontend package was added.

## API contract

All authenticated routes require the current database role and active account. Only the owning client can read/initiate/verify its payment; only administrators can approve fares. Mutations use authenticated bearer requests; the public webhook instead requires its provider signature.

| Method/path, after `/api` | Access | Input |
|---|---|---|
| `GET /deliveries/payment` | Owning client | Query `delivery_id` |
| `GET /admin/deliveries/payment` | Admin | Query `delivery_id` |
| `POST /admin/deliveries/approve-fare` | Admin | `delivery_id`, `expected_version` (0 initially), `amount` (decimal string), `reason` (1–500 characters) |
| `POST /deliveries/payments/initialize` | Owning client | `delivery_id`, `fare_version` |
| `POST /deliveries/payments/verify` | Owning client | `reference` |
| `POST /payments/paystack/webhook` | Signed provider | Exact provider body and `x-paystack-signature` |

Normal API bodies are capped at 8 KiB. Initialization is limited to ten requests/minute/IP; verification to thirty. Conflicts return `409`, invalid input `422`, unknown/foreign payments `404`, provider failures `502`, unavailable configuration/storage `503`. An HTTP `200` verification response can still describe a pending or held payment: clients inspect the receipt and attempt status. Payment records use the existing no-store API response policy.

## Configuration and rollout

1. Back up the existing database and review the incremental patch. Apply it to the state containing R01–R12 and `TIL_Fix_03_Pickup_Payment_Guard.patch`. Keep the application and workers stopped during migration and release switching; do not run old workers against the new financial workflow.
2. Use PHP 8.2 with PDO MySQL, cURL/TLS and mbstring. Run the existing required audit migrations first, then `php backend/scripts/migrate_delivery_payments.php` using migration credentials. This is required on fresh installs too, because `schema.sql` does not install the immutable triggers. Use a restricted application DB account afterward.
3. Start with a separate test database and Paystack test credentials. Configure privately on the backend:

```dotenv
PAYMENT_PROVIDER=paystack
PAYSTACK_MODE=test
PAYSTACK_SECRET_KEY=<your-test-secret>
PAYMENT_RETURN_URL=http://localhost:3000/payment/return
```

The localhost return URL is permitted only in test mode outside production. Use an actual HTTPS frontend `/payment/return` URL for remote staging. The secret never belongs in React or source control; hosted checkout does not need the existing public-key setting. Production requires `APP_ENV=production`, `PAYSTACK_MODE=live`, a matching live secret and HTTPS return URL. Never promote test payment records into the live database.

4. Configure the corresponding Paystack dashboard webhook to the deployed HTTPS `/api/payments/paystack/webhook` endpoint. It must be reachable by Paystack, exempt from browser login/CSRF redirects, and still pass the application signature check. Public localhost webhooks require a separately arranged staging endpoint; this patch does not create one. Test malformed/unsigned events and trailing-slash routing.
5. Build the frontend, prepare and activate the existing immutable release through the R12 deployment procedure, deploy the matching API, and restart the default queue worker. Readiness now requires payment configuration, payment tables, immutable triggers and a healthy inbox. It checks local configuration, not provider reachability or credential validity.
6. Complete the native and provider checks below before permitting live payments/pickups. Legacy `paid` records are deliberately not backfilled into provider receipts. Resolve them against actual provider evidence in a separately reviewed reconciliation; never manufacture receipts or invite an already-paid client to pay again.

**Rollback:** keep the new financial tables, receipts and audit history. An older flag-only pickup release would bypass these protections, so do not re-enable pickup on that release. Pause checkout and pickup if rollback is needed, retain/recover webhook intake and reconciliation, and roll forward with the evidence guard preserved. Do not reverse the schema or restore a stale database over received payments.

## Verification and remaining release gates

Local checks passed:

- 83 fare/payment assertions using production service code, SQLite dialect adaptation and a simulated provider: exact amounts, version/ownership conflicts, initialization replay/lease, provider binding mismatches, unknown outcomes, duplicates, transaction reuse, holds, webhook signatures, retry exhaustion and required-audit rollback.
- 135 driver milestone/payment-gate assertions, including a paid flag without a receipt, changed fare and unverified attempt rejection.
- 113 booking/outbox assertions, 66 existing runtime-boundary assertions and authorization-policy checks.
- 39 frontend tests in 11 suites, lint and production build. Tests cover explicit fare-version acceptance, strict checkout links, legacy paid flags, hold precedence and forged callback status.
- All 116 PHP source/test/migration files checked in this review passed syntax validation.

These domain checks used PHP 8.2 WebAssembly and SQLite. No native MySQL service, authenticated end-to-end browser session or live Paystack transaction was exercised here. A fake gateway is injected only by tests; application routes instantiate the real HTTPS Paystack adapter. Run locally on native PHP:

```text
php backend/tests/verify_delivery_payments.php
php backend/tests/verify_driver_delivery_progress.php
```

The supplied `tests/verify_delivery_payments_mysql.php` creates and retains fixtures in an **empty disposable** database whose name ends `_payment_test`. Set `PAYMENT_TEST_MYSQL_DSN`, `PAYMENT_TEST_MYSQL_USER` and `PAYMENT_TEST_MYSQL_PASSWORD`; it never loads application environment files. It runs six separate PHP processes per approval/initialization/verification operation and checks canonical InnoDB constraints, pickup evidence and immutable triggers. This native test is supplied but was not run here.

Staging acceptance must additionally cover two browsers racing checkout, authenticated owner/role failures, repeated webhook delivery, provider timeout, worker death/restart, cancellation/refund versus pickup, migration rerun, production rejection of test receipts and mismatched API/frontend releases. Perform a full Paystack **test-mode** checkout through the real adapter and dashboard webhook, then inspect one receipt and one verification audit. Approve any live smoke charge separately.

This stage does not implement COD, refund initiation/approval, automatic hold release, settlement accounting, chargeback resolution, payment backfill, authoritative road-route pricing or actual external notification delivery. Existing notification stubs remain unconfigured. The next bounded stage is the native MySQL and Paystack staging acceptance gate; after it passes, add an audited refund/hold-resolution workflow. The system is not certified production-ready.

## Patch and suggested commit

`TIL_Fix_04_Fare_Approval_Paystack_Verification.patch` is incremental on the previous pickup-guard state. Its pre-change checkpoint is `til-before-payment-flow.patch` (SHA-256 `ca72ed41f9bb64864e276dc4ec061fa7f2635914400ffc832b908ff59d9cf542`). Run `git apply --check` before applying the incremental patch. A separate cumulative patch includes all local fixes against the baseline; apply one route, not both.

```text
feat: approve final fares and verify Paystack payments before pickup
```

Provider contract references: [Initialize/Verify transactions](https://paystack.com/docs/api/transaction/), [verification semantics](https://paystack.com/docs/payments/verify-payments/), [webhook signatures/retries](https://paystack.com/docs/payments/webhooks/), [refund notifications](https://paystack.com/docs/payments/refunds/).
