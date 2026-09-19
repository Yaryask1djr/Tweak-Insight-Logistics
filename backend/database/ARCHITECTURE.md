# Delivery operations data model

`users` is the identity and authentication table. Fixed application roles stay
on this table (`client`, `delivery`, `admin`) because that is the authorization
model the API currently enforces; a separate role table would not improve the
current workflow.

`clients` and `drivers` are role-specific profiles. A client can own a
`business_accounts` record and saved `client_addresses`. A driver owns KYC
`driver_documents` and one current `driver_availability` record. The current
availability row is deliberately not an event log: status changes are written
to `audit_logs`, and real-time movement belongs to delivery location events.

`deliveries` is the delivery request and the completed operational record. It
retains pickup and recipient snapshots so later address edits never rewrite
history. `delivery_items` adds support for multi-item jobs without breaking the
existing single-item request form. There is no duplicate `delivery_requests`
table.

The assignment path is:

```text
delivery (under_review)
  -> delivery_driver_offers (one per eligible driver)
  -> one current delivery_assignments row (locked after first valid acceptance)
  -> deliveries.delivery_person_id (current fast lookup)
```

Assignment records are retained if an operations exception releases a job. The
assignment transaction, plus the delivery's current driver/status, enforces
one current driver; `assignment_sequence` preserves the exception history.

Every allowed lifecycle transition creates a `delivery_status_history` row.
GPS samples go to `delivery_location_events`; the most recent sample is also
denormalized onto `deliveries` for quick client and operations tracking.

`rate_cards` and `rate_rules` hold future pricing policy. A
`delivery_price_quotes` row snapshots the rules and result applied to each
request, so quotes and invoices remain reproducible after a rate change.

`delivery_proofs` stores proof metadata and opaque storage keys, not binary
files. `notifications` records the notification lifecycle. `audit_logs` is the
append-only operational record for privileged changes, KYC decisions, rate
changes, assignment exceptions, and system actions.

Notification records are owned by one user and may point to one delivery. The
controllers emit in-app events after a successful status transition, while a
future queue can fan the same minimal payload out to email, SMS, WhatsApp, or
push without becoming part of the delivery transaction. See
`../docs/NOTIFICATIONS.md` for the event catalogue and inbox contract.

## Kano-only invariant

Every stored address, hub, zone, rate card, and delivery is scoped to Kano.
`service_zones` is the future authoritative polygon layer; the current
application's text and coordinate checks should migrate to this table before
relying on address validation for pricing or dispatch.

## Applying the design

- Use `schema.sql` only for a new database.
- Use `../scripts/migrate_operational_architecture.php` for an existing
  database. It only creates the new additive tables and records its migration;
  it does not delete or rewrite existing delivery data.
- Controller work should be introduced in this order: profile/KYC, offer and
  assignment transactions, status/audit history, pricing/rate cards, proof and
  notifications, then map-based tracking.
