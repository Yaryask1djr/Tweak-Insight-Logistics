<?php

require_once __DIR__ . '/client_ip.php';

/**
 * Logger — Centralized File-based Logging and Error Handler.
 *
 * Directs all PHP warnings, notices, errors, and uncaught exceptions
 * to structured daily log files in backend/storage/logs/
 * while suppressing sensitive stack traces from client responses in production.
 */
class Logger
{
    private static string $logDir = __DIR__ . '/../storage/logs';

    /**
     * Initialize error handlers and directory.
     */
    public static function init(): void
    {
        if (!is_dir(self::$logDir)) {
            @mkdir(self::$logDir, 0755, true);
        }

        $isProduction = (getenv('APP_ENV') === 'production');

        // Configure PHP error reporting
        error_reporting(E_ALL);

        if ($isProduction) {
            ini_set('display_errors', '0');
            ini_set('display_startup_errors', '0');
        } else {
            ini_set('display_errors', '1');
            ini_set('display_startup_errors', '1');
        }

        ini_set('log_errors', '1');
        ini_set('error_log', self::getLogFilePath());

        // Register custom handlers
        set_error_handler([self::class, 'handlePhpError']);
        set_exception_handler([self::class, 'handleUncaughtException']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    /**
     * Get path for current day's log file.
     */
    public static function getLogFilePath(): string
    {
        if (!is_dir(self::$logDir)) {
            @mkdir(self::$logDir, 0755, true);
        }
        return self::$logDir . '/app-' . date('Y-m-d') . '.log';
    }

    /**
     * Log an informational message.
     */
    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    /**
     * Log a warning.
     */
    public static function warning(string $message, array $context = []): void
    {
        self::write('WARNING', $message, $context);
    }

    /**
     * Log an error.
     */
    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    /**
     * Log an exception with full trace.
     */
    public static function exception(Throwable $e, string $customMessage = ''): void
    {
        $message = $customMessage !== '' ? $customMessage . ': ' . $e->getMessage() : $e->getMessage();
        $context = [
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'code' => $e->getCode(),
            'trace' => $e->getTraceAsString(),
        ];
        self::write('CRITICAL', $message, $context);
    }

    /**
     * Internal writer outputting structured JSON to stdout/stderr and/or log file.
     */
    private static function write(string $level, string $message, array $context = []): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $isoTimestamp = $now->format('Y-m-d\TH:i:s.v\Z');
        $ip = self::getClientIp();
        $method = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
        $uri = $_SERVER['REQUEST_URI'] ?? '-';
        $requestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? null;

        $logEntry = [
            'timestamp'  => $isoTimestamp,
            'level'      => $level,
            'message'    => $message,
            'method'     => $method,
            'uri'        => $uri,
            'client_ip'  => $ip,
            'pid'        => getmypid() ?: 0,
        ];

        if ($requestId !== null) {
            $logEntry['request_id'] = $requestId;
        }

        if (!empty($context)) {
            $logEntry['context'] = $context;
        }

        $jsonLine = json_encode($logEntry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

        // Determine output channel: stdout, stderr, file, or dual (default)
        $channel = strtolower((string)(getenv('LOG_CHANNEL') ?: 'dual'));

        if ($channel === 'stdout' || $channel === 'dual') {
            @file_put_contents('php://stdout', $jsonLine);
        } elseif ($channel === 'stderr') {
            @file_put_contents('php://stderr', $jsonLine);
        }

        // File persistence (fallback or when file / dual logging enabled)
        if ($channel === 'file' || $channel === 'dual') {
            $textSummary = sprintf("[%s] [%s] [%s] [%s %s] %s%s\n",
                $isoTimestamp,
                $level,
                $ip,
                $method,
                $uri,
                $message,
                !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES) : ''
            );
            @file_put_contents(self::getLogFilePath(), $textSummary, FILE_APPEND | LOCK_EX);
        }
    }

    /**
     * PHP Error Handler.
     */
    public static function handlePhpError(int $errno, string $errstr, string $errfile, int $errline): bool
    {
        // Don't log if error reporting was suppressed with @
        if (!(error_reporting() & $errno)) {
            return false;
        }

        $level = match ($errno) {
            E_USER_ERROR, E_RECOVERABLE_ERROR => 'ERROR',
            E_WARNING, E_USER_WARNING => 'WARNING',
            E_NOTICE, E_USER_NOTICE => 'NOTICE',
            default => 'INFO',
        };

        self::write($level, $errstr, ['file' => $errfile, 'line' => $errline]);

        // If error is fatal/critical, terminate safely
        if ($errno === E_USER_ERROR || $errno === E_RECOVERABLE_ERROR) {
            self::respondFatal();
        }

        return true;
    }

    /**
     * Uncaught Exception Handler.
     */
    public static function handleUncaughtException(Throwable $e): void
    {
        self::exception($e, 'Uncaught Exception');
        self::respondFatal();
    }

    /**
     * Shutdown Handler for fatal errors.
     */
    public static function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
            self::write('FATAL', $error['message'], ['file' => $error['file'], 'line' => $error['line']]);
            self::respondFatal();
        }
    }

    /**
     * Safely respond to client on fatal failures.
     */
    private static function respondFatal(): void
    {
        if (headers_sent()) {
            exit;
        }

        http_response_code(500);
        $isApi = (isset($_SERVER['REQUEST_URI']) && (str_contains($_SERVER['REQUEST_URI'], '/api') || str_contains($_SERVER['REQUEST_URI'], '/auth')));

        if ($isApi) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'status' => 'error',
                'code' => 500,
                'message' => 'An unexpected server error occurred. Please try again later.'
            ], JSON_UNESCAPED_SLASHES);
        } else {
            echo "<!DOCTYPE html><html><head><title>500 Server Error</title><style>body{font-family:sans-serif;padding:40px;text-align:center;color:#333;}</style></head><body><h1>Server Error</h1><p>We are experiencing technical difficulties. Our engineering team has been notified.</p></body></html>";
        }
        exit;
    }

    /** Resolves client IP using the shared trusted-proxy policy. */
    private static function getClientIp(): string
    {
        return ClientIp::resolve();
    }
}
