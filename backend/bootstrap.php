<?php

// Simple .env loader for local development
function load_dotenv(string $path): void
{
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $val) = array_map('trim', explode('=', $line, 2));
        // Remove surrounding quotes
        if (strlen($val) >= 2 && (($val[0] === '"' && $val[strlen($val)-1] === '"') || ($val[0] === "'" && $val[strlen($val)-1] === "'"))) {
            $val = substr($val, 1, -1);
        }
        // Only set if not already present in environment
        if (getenv($key) === false) {
            putenv("$key=$val");
            $_ENV[$key] = $val;
            $_SERVER[$key] = $val;
        }
    }
}

// Load backend/.env if present
$envPath = __DIR__ . '/.env';
load_dotenv($envPath);

require_once __DIR__ . '/helpers/security_config.php';

// Ensure ROOT_PATH and STORAGE_PATH constants are defined
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', __DIR__);
}

if (!defined('STORAGE_PATH')) {
    define('STORAGE_PATH', __DIR__ . '/storage');
}

// Initialize centralized error handling and file logging
require_once __DIR__ . '/helpers/logger.php';
Logger::init();

$isProductionEnv = SecurityConfig::isProductionLike();
$configuredJwt = (string)getenv('JWT_SECRET');
$configuredDbUser = (string)getenv('DB_USER');
$configuredDbPassword = (string)getenv('DB_PASS');
$configurationErrors = array_filter([
    SecurityConfig::jwtSecretError($configuredJwt),
    SecurityConfig::databaseCredentialsError($configuredDbUser, $configuredDbPassword, $isProductionEnv),
]);

if ($configurationErrors !== []) {
    Logger::critical('SECURITY: Credential configuration rejected.', [
        'app_env' => getenv('APP_ENV') ?: 'production',
        'errors' => array_values($configurationErrors),
    ]);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'success' => false,
        'message' => 'Server misconfiguration detected. Contact operations support.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit(1);
}

// Compute and define BASE_URL safely
if (!defined('BASE_URL')) {
    $envBaseUrl = getenv('BASE_URL');
    if ($envBaseUrl !== false && !empty($envBaseUrl)) {
        $baseUrl = rtrim($envBaseUrl, '/');
    } else {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
        $baseUrl = $protocol . $host;
    }
    define('BASE_URL', $baseUrl);
}

// Compute and define API_URL safely
if (!defined('API_URL')) {
    define('API_URL', BASE_URL . '/api');
}

// Define APP_NAME
if (!defined('APP_NAME')) {
    define('APP_NAME', getenv('APP_NAME') ?: 'Tweak Insight Logistics');
}

// Runtime error display — PRODUCTION MUST NEVER expose PHP stack traces
if (!defined('TIL_DEBUG')) {
    $debugFlag = filter_var(getenv('APP_DEBUG') ?: '0', FILTER_VALIDATE_BOOLEAN);
    define('TIL_DEBUG', $debugFlag && !$isProductionEnv);
}
ini_set('display_errors',  TIL_DEBUG ? '1' : '0');
ini_set('display_startup_errors', TIL_DEBUG ? '1' : '0');
error_reporting(TIL_DEBUG ? E_ALL : (E_ALL & ~E_DEPRECATED & ~E_STRICT));
