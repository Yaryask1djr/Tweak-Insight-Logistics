<?php

require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/response.php';

/**
 * Custom exception used by transactional operations to cleanly abort
 * and deliver an error response after rolling back the transaction.
 */
class TransactionBusinessException extends RuntimeException
{
    private int $statusCode;

    public function __construct(string $message, int $statusCode = 400, ?Throwable $previous = null)
    {
        parent::__construct($message, $statusCode, $previous);
        $this->statusCode = $statusCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}

/**
 * DatabaseTransaction — Automatic deadlock and lock wait timeout retry manager.
 *
 * Wraps transactional business actions (job claims, wallet disbursements, fleet dispatches)
 * in a multi-attempt retry loop (default 3 attempts) to gracefully absorb transient MySQL
 * InnoDB lock contention (Error 1213: Deadlock, Error 1205: Lock wait timeout) during
 * peak dispatch hours.
 */
final class DatabaseTransaction
{
    public const DEFAULT_MAX_ATTEMPTS = 3;
    private const INITIAL_BACKOFF_US = 50000; // 50ms initial backoff

    /**
     * Abort transaction with an immediate rollback and API error response.
     */
    public static function fail(string $message, int $statusCode = 400): never
    {
        throw new TransactionBusinessException($message, $statusCode);
    }

    /**
     * Executes a transactional callback with automatic deadlock / lock-wait retry.
     *
     * @template T
     * @param PDO $db The active PDO database connection
     * @param callable(PDO): T $action Transactional work unit
     * @param int $maxAttempts Maximum number of attempts before throwing
     * @return T The value returned by $action
     * @throws Throwable
     */
    public static function run(PDO $db, callable $action, int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS): mixed
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                if (!$db->inTransaction()) {
                    $db->beginTransaction();
                }

                $result = $action($db);

                if ($db->inTransaction()) {
                    $db->commit();
                }

                return $result;
            } catch (TransactionBusinessException $businessException) {
                if ($db->inTransaction()) {
                    try {
                        $db->rollBack();
                    } catch (Throwable $rollbackException) {
                    }
                }
                Response::error($businessException->getMessage(), $businessException->getStatusCode());
            } catch (Throwable $exception) {
                if ($db->inTransaction()) {
                    try {
                        $db->rollBack();
                    } catch (Throwable $rollbackException) {
                        // Connection dropped or already rolled back
                    }
                }

                // If error is transient deadlock or lock timeout, retry with backoff + jitter
                if (self::isTransientLockFailure($exception) && $attempt < $maxAttempts) {
                    $jitter = random_int(10000, 50000); // 10-50ms jitter
                    $delayUs = (self::INITIAL_BACKOFF_US * (2 ** ($attempt - 1))) + $jitter;

                    Logger::warning("Database deadlock or lock wait timeout on attempt {$attempt}/{$maxAttempts}. Auto-retrying in " . round($delayUs / 1000, 2) . "ms...", [
                        'attempt' => $attempt,
                        'max_attempts' => $maxAttempts,
                        'error_code' => $exception->getCode(),
                        'error_message' => $exception->getMessage(),
                    ]);

                    usleep($delayUs);
                    continue;
                }

                if ($attempt >= $maxAttempts && self::isTransientLockFailure($exception)) {
                    Logger::error("Exhausted all {$maxAttempts} transaction attempts due to persistent InnoDB lock contention.", [
                        'error_code' => $exception->getCode(),
                        'error_message' => $exception->getMessage(),
                    ]);
                }

                throw $exception;
            }
        }
    }

    /**
     * Inspects whether an exception is an InnoDB deadlock or lock wait timeout.
     *
     * MySQL Error Codes:
     * - 1213: ER_LOCK_DEADLOCK (SQLSTATE 40001)
     * - 1205: ER_LOCK_WAIT_TIMEOUT (SQLSTATE HY000)
     */
    public static function isTransientLockFailure(Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());

        // 1. String pattern matching in exception message
        if (
            str_contains($message, 'deadlock') ||
            str_contains($message, 'lock wait timeout') ||
            str_contains($message, 'try restarting transaction')
        ) {
            return true;
        }

        // 2. SQLSTATE and driver code checking
        if ($exception instanceof PDOException) {
            $code = (string)$exception->getCode();
            if ($code === '40001') {
                return true; // Serialization failure (standard SQLSTATE for deadlock)
            }

            if (isset($exception->errorInfo[1])) {
                $driverCode = (int)$exception->errorInfo[1];
                if ($driverCode === 1213 || $driverCode === 1205) {
                    return true;
                }
            }
        }

        return false;
    }
}
