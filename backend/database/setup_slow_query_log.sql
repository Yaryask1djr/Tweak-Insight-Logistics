-- ==========================================================================
--  Tweak Insight Logistics — MySQL 8 Slow Query & Performance Logging Setup
-- ==========================================================================
--  Apply as root (or SUPER user) to the production / staging DB:
--
--      mysql -u root -p < backend/database/setup_slow_query_log.sql
--
--  This script is IDEMPOTENT — running it multiple times is safe.
-- ==========================================================================

-- --------------------------------------------------------------------------
--  1. GENERAL ERROR LOG (on by default, but ensure it is pointed somewhere
--     the DevOps team actually monitors — /var/log/mysql/error.log)
-- --------------------------------------------------------------------------
SET GLOBAL log_error = '/var/log/mysql/error.log';

-- --------------------------------------------------------------------------
--  2. SLOW QUERY LOG (catch every scan / missing index)
--     "long_query_time = 1.0" seconds is a sensible starting value; tighten
--     to 0.5 once regressions are fixed.
-- --------------------------------------------------------------------------
SET GLOBAL slow_query_log      = 'ON';
SET GLOBAL long_query_time     = 1.0;
SET GLOBAL min_examined_row_limit = 500;     -- skip tiny lookups
SET GLOBAL log_queries_not_using_indexes = 'ON';
SET GLOBAL log_throttle_queries_not_using_indexes = 30;   -- don't spam log
SET GLOBAL slow_query_log_file = '/var/log/mysql/tweak-insight-slow.log';

-- --------------------------------------------------------------------------
--  3. BINARY LOG for point-in-time recovery (REQUIRED for backups)
--     MySQL 8 defaults to binlog_format=ROW which is safest.
--     7-day retention covers typical nightly backup + 1-week rollback window.
-- --------------------------------------------------------------------------
SET GLOBAL log_bin              = 'mysql-bin';
SET GLOBAL binlog_format        = 'ROW';
SET GLOBAL binlog_expire_logs_seconds = 604800;     -- 7 days
SET GLOBAL max_binlog_size      = 268435456;         -- 256 MB per file

-- --------------------------------------------------------------------------
--  4. GENERAL / PERFORMANCE_SCHEMA switches
--     enable events_statements_history_long so we can sample worst-queries
--     via the performance_schema tables, without a log-file dependency.
-- --------------------------------------------------------------------------
SET GLOBAL performance_schema = 'ON';
-- Confirmation SELECT — run to verify config "took":
--    SELECT @@GLOBAL.slow_query_log, @@GLOBAL.long_query_time,
--           @@GLOBAL.log_queries_not_using_indexes, @@GLOBAL.log_bin;

-- ==========================================================================
--  REMEMBER TO:
--  - Add the log files above to your logrotate configuration (daily, 30 day
--    retention, compress, delaycompress).
--  - For MariaDB users: the same variables work; use MariaDB-10.5+ columnstore
--    diagnostics / slow query analysis instead of P_S if needed.
-- ==========================================================================
