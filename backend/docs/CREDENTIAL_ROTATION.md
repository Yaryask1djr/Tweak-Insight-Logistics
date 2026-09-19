# Credential rotation and application database access

Treat the previously used JWT signing value and database configuration as
revoked. Do not reuse either value in any environment.

## Generate and store credentials

Generate independent, cryptographically random values of at least 32 bytes for
JWT_SECRET and DB_PASS. Store them only in the production secret manager, then
inject them into the service environment. Do not place them in Git, a SQL
script, shell history, tickets, or chat.

For a controlled terminal, PHP can generate one value:

~~~sh
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
~~~

Set the application configuration with a dedicated account:

~~~dotenv
DB_USER=til_app
DB_PASS=<new-app-password>
JWT_SECRET=<new-jwt-secret>
~~~

backend/.env is ignored for local development. Production should obtain these
values directly from its secret manager rather than an environment file
deployed with the application.

## Provision or rotate the database account

Run the provisioning script from a trusted application host after the new
DB_USER and DB_PASS are available to the process. Supply a temporary
database-administrator account only through the command environment; it is
never read from backend/.env.

~~~sh
export TIL_DB_ADMIN_USER=<temporary-admin-user>
export TIL_DB_ADMIN_PASS=<temporary-admin-password>
export TIL_DB_APP_HOSTS=localhost,127.0.0.1
php backend/scripts/provision_app_db_user.php
unset TIL_DB_ADMIN_PASS
~~~

For a remote database, set TIL_DB_APP_HOSTS to the explicit private IP address
or DNS name of each application host. Wildcard MySQL account hosts are
rejected. The script rotates the application password, removes existing
privileges from that application account, and grants only SELECT, INSERT,
UPDATE, and DELETE on the configured application schema.

Use backend/database/create_app_user.sql only when a PHP CLI is unavailable.
It is a manual fallback and deliberately contains no secret.

## Schema changes

Do not grant schema-change privileges to til_app. Run database migrations with a
separate, short-lived migration credential injected only into the migration
process, then remove it. The running API must continue to use the DML-only
application account.

## Release checks

1. Rotate the database administrator password through the database provider or
   administration console if it was exposed.
2. Remove the old JWT value from every secret store, deployment variable, and
   local configuration.
3. Restart every application instance after replacing JWT_SECRET; existing
   access tokens signed with the old key become invalid.
4. Confirm SHOW GRANTS FOR the application account exposes only the four
   application DML privileges on the application schema.
5. Verify startup fails for a root account, a blank or placeholder password, a
   short production password, or the revoked JWT value.
