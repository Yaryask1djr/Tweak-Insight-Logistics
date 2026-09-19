# Notifications

The first notification channel is a persisted, role-scoped **in-app inbox**.
It is not a frontend-only toast and it does not trust a browser-provided event:
controllers publish events only after the backend has validated ownership and a
permitted delivery-status transition.

## Event catalogue

| Audience | Event | Trigger |
| --- | --- | --- |
| Client | `client.request_submitted` | The client request is created. |
| Client | `client.request_reviewed` | Operations moves it to `under_review`. |
| Client | `client.driver_assigned` | The first eligible driver accepts the broadcast offer. |
| Client | `client.pickup_completed` | The assigned driver records `picked_up`. |
| Client | `client.delivery_in_progress` | The assigned driver records `in_transit`. |
| Client | `client.delivery_completed` | The assigned driver records verified proof of delivery. |
| Client | `client.request_cancelled` | A request is cancelled, rejected, or fails. |
| Driver | `driver.new_delivery_offer` | Operations broadcasts a Kano offer to approved active drivers. |
| Driver | `driver.assignment_confirmed` | The driver's first valid acceptance locks the assignment. |
| Driver | `driver.delivery_change` | An assigned job changes, completes, is cancelled, rejected, or fails. |
| Driver | `driver.document_issue` | KYC/document review needs driver action. |
| Admin | `admin.new_delivery_request` | A client creates a request. |
| Admin | `admin.driver_accepted` | A driver accepts and locks an offer. |
| Admin | `admin.delivery_completed` | Digital proof of delivery is recorded. |
| Admin | `admin.driver_document_issue` | A KYC/document review needs operations attention. |

`client.delivery_status_changed` is the compact client event used for the
driver-en-route and arrived milestones.

## Inbox API

All endpoints require a bearer token and the server-side
`notifications.read_own` permission. The authenticated user's ID is always
used in the database predicate; a request cannot read or mark another user's
notifications.

| Method | Endpoint | Purpose |
| --- | --- | --- |
| `GET` | `/api/notifications?page=1&limit=20&unread=true` | List own notifications. |
| `GET` | `/api/notifications/unread-count` | Count unread own notifications. |
| `POST` | `/api/notifications/read` | Mark one own notification read with `{ "notification_id": 123 }`. |
| `POST` | `/api/notifications/read-all` | Mark every own notification read. |

The endpoints return `503` until the operational database migration has
created the `notifications` table. Delivery operations continue to work before
that migration; the publisher safely no-ops rather than failing a shipment
transition.

## Channel expansion

Each row stores `channel`, `notification_type`, a minimal JSON `payload`, and
delivery/read lifecycle timestamps. The current synchronous in-app write is
stored as `channel = in_app`, `delivery_status = sent`.

Email, SMS, WhatsApp, and push should be added as an asynchronous dispatcher:

```text
validated delivery transition
  -> persisted in-app notification
  -> channel dispatcher/queue
  -> provider delivery result (sent or failed)
```

Do not put OTP values, credentials, authentication tokens, recipient contact
details, or delivery-address text into notification payloads. The current
payload contains only the delivery ID, tracking number, status, and previous
status where applicable.

## KYC/document events

The current code has no document submission/review endpoint yet. When that
workflow is introduced, its rejection, expiry, and remediation paths should
call `NotificationService::driverDocumentIssue()` after the KYC decision is
persisted. That method produces the driver and operations events above without
coupling KYC code to a particular messaging provider.
