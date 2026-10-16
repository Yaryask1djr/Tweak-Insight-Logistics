<?php

require_once __DIR__ . '/../helpers/logger.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/security_config.php';

class Database
{
    private string $host;
    private string $db_name;
    private string $username;
    private string $password;
    private int $port;
    private ?PDO $conn = null;

    public function __construct()
    {
        $this->host = getenv('DB_HOST') ?: '127.0.0.1';
        $this->db_name = getenv('DB_NAME') ?: 'tweak_insight_logistics_new';
        $this->username = trim((string) (getenv('DB_USER') ?: ''));
        $this->password = (string) (getenv('DB_PASS') ?: '');
        $this->port = (int) (getenv('DB_PORT') ?: 3306);

        $configurationError = SecurityConfig::databaseCredentialsError(
            $this->username,
            $this->password,
            SecurityConfig::isProductionLike()
        );

        if ($configurationError !== null) {
            throw new RuntimeException($configurationError);
        }
    }

    public function getConnection(): PDO
    {
        if ($this->conn instanceof PDO) {
            try {
                $this->conn->query('SELECT 1');
                return $this->conn;
            } catch (Throwable $e) {
                $this->conn = null;
            }
        }
        return $this->connect();
    }

    public function connect(): PDO
    {
        if ($this->conn instanceof PDO) {
            return $this->conn;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $this->host,
            $this->port,
            $this->db_name
        );

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => false,
            PDO::ATTR_TIMEOUT => 5,
        ];
        if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
            $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+00:00'";
        }

        $maxAttempts = 3;
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $this->conn = new PDO($dsn, $this->username, $this->password, $options);
                $this->conn->exec("SET time_zone = '+00:00'");
                return $this->conn;
            } catch (PDOException $e) {
                $lastException = $e;
                if ($attempt < $maxAttempts) {
                    usleep(100000); // 100ms backoff before retry
                    continue;
                }
            }
        }

        Logger::exception($lastException, 'Database connection failed');
        throw new RuntimeException('Database service temporarily unavailable.', 0, $lastException);
    }

    /**
     * Inspect live database connectivity, latency, and server version.
     */
    public static function checkHealth(): array
    {
        $start = microtime(true);
        try {
            $instance = new self();
            $db = $instance->getConnection();
            $db->query('SELECT 1');
            $latencyMs = round((microtime(true) - $start) * 1000, 2);
            $version = $db->getAttribute(PDO::ATTR_SERVER_VERSION);
            return [
                'ok' => true,
                'latency_ms' => $latencyMs,
                'database' => $instance->db_name,
                'server_version' => $version,
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'error' => $e->getMessage(),
                'latency_ms' => round((microtime(true) - $start) * 1000, 2),
            ];
        }
    }
}
