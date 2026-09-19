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
        $this->db_name = getenv('DB_NAME') ?: 'tweak_insight_logistics';
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

        try {
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false,
            ];

            $this->conn = new PDO($dsn, $this->username, $this->password, $options);
            return $this->conn;
        } catch (PDOException $e) {
            Logger::exception($e, 'Database connection failed');
            
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json; charset=UTF-8');
            }
            echo json_encode([
                'status' => 'error',
                'code' => 500,
                'message' => 'Database service temporarily unavailable. Please try again later.'
            ], JSON_UNESCAPED_SLASHES);
            exit(1);
        }
    }
}
