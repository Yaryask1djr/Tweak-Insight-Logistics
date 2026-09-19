# Secure document storage deployment

KYC documents are sensitive identity data. In production the application fails
closed unless its document bucket, encryption, malware scanner, and retention
policy are configured. `backend/storage/` is a development-only adapter and is
not permitted in a production-like environment. Its Apache deny rule is only
defence in depth; production must keep `backend/public/` as its document root.

## Required production configuration

Set these values in the deployment secret store, not in the repository:

```dotenv
APP_ENV=production
STORAGE_DRIVER=s3
S3_ENDPOINT=https://s3.eu-west-1.amazonaws.com
S3_BUCKET=tweak-insight-private-kyc-production
S3_REGION=eu-west-1
S3_KEY=...
S3_SECRET=...
S3_SERVER_SIDE_ENCRYPTION=aws:kms
S3_KMS_KEY_ID=arn:aws:kms:eu-west-1:123456789012:key/...
S3_URL_EXPIRES=300

UPLOAD_SCAN_DRIVER=clamav
CLAMAV_BINARY=/usr/bin/clamscan
KYC_DOCUMENT_RETENTION_DAYS=2555
```

`AES256` is also accepted for `S3_SERVER_SIDE_ENCRYPTION` where the provider
does not offer KMS. For a regulated production deployment, prefer a customer
managed KMS key, restrict who can decrypt it, and enable key-usage auditing.
The application clamps document URLs to 60–300 seconds even if a larger value
is supplied.

The storage adapter reads the `S3_*` variables shown above. Legacy
`AWS_*` variable names are not used and must be replaced before release.

Choose `KYC_DOCUMENT_RETENTION_DAYS` with your legal/compliance owner. It is
intentionally required in production; 2,555 days is only an example, not legal
advice. The value is stamped onto each document when submitted, so a later
policy change does not silently rewrite existing retention dates.

## Bucket and network policy

- Block all public access; never enable public ACLs or a public bucket policy.
- Give the application identity only `GetObject`, `PutObject`, `DeleteObject`,
  and (if needed) `HeadObject` for the `client-kyc/` and `driver-documents/`
  prefixes. Do not grant bucket administration or list access.
- Require TLS for bucket requests and deny unencrypted `PutObject` requests at
  the bucket policy layer as defence in depth.
- Configure a bucket lifecycle expiration for both prefixes at the approved
  retention period. This is a backstop for orphaned objects (for example, an
  object created just before a database outage); it does not replace the app's
  scheduled purge job.
- If the browser follows signed object redirects, configure object-store CORS
  only for the exact application origins in `ALLOWED_ORIGINS`, with `GET` and
  no wildcard origin or credentials. The bucket remains private.

The API checks document ownership or an operations permission before it creates
an object-store redirect. Each URL is signed, expires in 60–300 seconds, forces
an attachment download, disables caching, and sends a no-referrer policy. Do
not add a public object URL or a generic `?key=` download
endpoint.

S3/R2/MinIO compatibility varies. Test `PUT`, `HEAD`, `GET` signed URLs and
`DELETE` with the intended provider before accepting real documents.

## Upload controls

The server uses the actual file bytes rather than filename or browser MIME:

- allowed types: PDF, JPEG, PNG, and WebP;
- MIME is sniffed from file bytes with `finfo`, checked against format magic
  bytes, and image dimensions are parsed server-side; browser MIME and the
  original filename are never trusted;
- each stored filename is a server-generated UUIDv4 plus the trusted extension;
- PDF structure and embedded scripting markers are checked before storage;
- client maximum: 10 MB; driver maximum: 5 MB;
- image dimension maximum: 40 million pixels;
- an adapter repeats type and size checks immediately before persistence;
- production runs ClamAV before storage and rejects an unavailable scanner.

Local downloads are streamed only after the same authorization checks and are
always `application/octet-stream` attachments with `nosniff` and no-store
headers. S3 uploads store equivalent attachment/no-cache metadata and signed
URLs repeat those response constraints, so KYC documents are never rendered
from an executable upload path.

Set PHP/web-server limits high enough to receive, but never exceed, the app
limit. For the 10 MB client limit, use at least:

```ini
upload_max_filesize = 10M
post_max_size = 11M
max_file_uploads = 5
```

The API reports a specific 413 error for PHP/server size failures. The web UI
also shows the server message rather than masking it as “could not upload”.

## Migration and scheduled deletion

Before deploying the updated upload code to an existing database:

```bash
cd backend
php scripts/migrate_document_security.php
```

This adds `retention_until` and backfills existing records from their creation
date using the configured policy. The accompanying SQL reference is
`database/migrations/legacy/document_security_migration.sql`; use the script rather than applying
the SQL file directly because the script is idempotent and performs the safe
backfill.

Run the purge worker at least daily with the production environment loaded:

```cron
20 3 * * * cd /var/www/tweak-insight/backend && /usr/bin/php scripts/purge_expired_kyc_documents.php
```

The worker deletes the private object first, then deletes the metadata row only
if storage deletion succeeds. Re-uploaded client documents are removed as soon
as the replacement transaction commits; failed database writes attempt to
remove their newly stored object. Monitor any non-zero purge exit as a security
operations incident.

## Incident and operational handling

- Treat scanner outages, bucket misconfiguration, failed purge jobs, and
  unexpected object-store access logs as incidents; do not turn on local disk
  as a production fallback.
- Retain only the minimum necessary document records; do not copy documents to
  logs, support tickets, analytics, or backups without encryption and a
  documented expiry process.
- Test restoration and deletion in a non-production bucket. Deleting an object
  does not necessarily erase immutable provider backups immediately; align
  backup retention with the same approved schedule.
