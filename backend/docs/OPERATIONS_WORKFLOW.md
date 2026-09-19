# Admin and driver operations workflow

## Driver onboarding and KYC

1. A delivery-partner registration creates a driver profile and an offline
   availability row when the operational schema is present.
2. The driver submits a government ID and driver licence through the authenticated
   document-upload endpoint. Files are limited to genuine PDF/JPEG/PNG/WebP,
   5 MB, identified from their contents, scanned before storage, and kept in a
   private encrypted object bucket in production. Local disk is development-only.
3. An operations administrator verifies or rejects each pending document with
   an auditable decision. Rejections require a reason and create a driver
   notification.
4. Operations approves a driver only after a verified government ID and driver
   licence. Approval makes the driver KYC-verified and active, but initially
   offline. Rejection suspends the account instead of deleting its KYC history.

## Availability and dispatch

Only active, KYC-verified drivers can set their status to `available`. A driver
must be available before viewing or accepting offers. Successful atomic offer
acceptance locks the assignment and changes the driver's availability to `busy`.

## Assignment exception

Operations may release a current assignment only in `assigned` or
`driver_en_route`. Pickup and later states cannot be reassigned because the
goods are already in driver custody. A release requires a reason, updates the
current assignment record, restores the delivery to `broadcasted`, returns the
driver to available, writes operational history/audit records, and notifies the
driver and client.

## API surface

| Role | Endpoint | Purpose |
| --- | --- | --- |
| Driver | `GET /api/delivery-person/operations-profile` | KYC, availability, and own document state. |
| Driver | `POST /api/delivery-person/availability` | Set `offline`, `available`, or `paused`. |
| Driver | `POST /api/delivery-person/documents/upload` | Multipart document upload. |
| Admin | `GET /api/admin/pending-driver-documents` | Paginated KYC review queue. |
| Admin | `POST /api/admin/review-driver-document` | Verify/reject a document. |
| Admin | `POST /api/admin/release-assignment` | Pre-pickup reassignment exception. |
| Admin | `GET /api/admin/audit-log` | Paginated operational audit view. |

Every route is protected by server-side named permissions and controller-level
ownership checks; React visibility is not an authorization boundary.
