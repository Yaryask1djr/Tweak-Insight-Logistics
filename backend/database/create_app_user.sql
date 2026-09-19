-- Manual fallback for provisioning the least-privilege application account.
-- Prefer php scripts/provision_app_db_user.php so the application password is
-- read from the ignored environment or secret manager instead of this file.
--
-- In a secure MySQL session, set the following before sourcing this file:
--   SET @til_db_user = 'til_app';
--   SET @til_db_pass = 'a-unique-32-byte-or-longer-secret';
--   SET @til_db_name = 'tweak_insight_logistics';
--   SET @til_db_host = '127.0.0.1';
--
-- Source this file once for every explicit application host. Do not use %
-- or _ host patterns in production.

SET @til_db_user = COALESCE(NULLIF(@til_db_user, ''), 'til_app');
SET @til_db_name = COALESCE(NULLIF(@til_db_name, ''), 'tweak_insight_logistics');
SET @til_db_host = COALESCE(NULLIF(@til_db_host, ''), '127.0.0.1');
SET @til_db_pass = IF(CHAR_LENGTH(@til_db_pass) >= 32, @til_db_pass, NULL);
SET @til_db_user = IF(LOWER(@til_db_user) = 'root', NULL, @til_db_user);

SET @til_account = CONCAT(QUOTE(@til_db_user), '@', QUOTE(@til_db_host));
SET @til_schema = CONCAT(CHAR(96), REPLACE(@til_db_name, CHAR(96), CONCAT(CHAR(96), CHAR(96))), CHAR(96));

SET @til_sql = CONCAT(
    'CREATE USER IF NOT EXISTS ',
    @til_account,
    ' IDENTIFIED BY ',
    QUOTE(@til_db_pass)
);
PREPARE til_statement FROM @til_sql;
EXECUTE til_statement;
DEALLOCATE PREPARE til_statement;

SET @til_sql = CONCAT(
    'ALTER USER ',
    @til_account,
    ' IDENTIFIED BY ',
    QUOTE(@til_db_pass)
);
PREPARE til_statement FROM @til_sql;
EXECUTE til_statement;
DEALLOCATE PREPARE til_statement;

SET @til_sql = CONCAT('REVOKE ALL PRIVILEGES, GRANT OPTION FROM ', @til_account);
PREPARE til_statement FROM @til_sql;
EXECUTE til_statement;
DEALLOCATE PREPARE til_statement;

SET @til_sql = CONCAT(
    'GRANT SELECT, INSERT, UPDATE, DELETE ON ',
    @til_schema,
    '.* TO ',
    @til_account
);
PREPARE til_statement FROM @til_sql;
EXECUTE til_statement;
DEALLOCATE PREPARE til_statement;
