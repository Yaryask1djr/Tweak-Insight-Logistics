<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function provisioningFailure(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function environmentValue(string $name, ?string $default = null): ?string
{
    $value = getenv($name);

    if ($value === false) {
        return $default;
    }

    $value = trim($value);

    return $value === '' ? $default : $value;
}

function databaseIdentifier(string $name): string
{
    if (!preg_match('/^[A-Za-z0-9$_]+$/', $name)) {
        provisioningFailure('DB_NAME contains unsupported characters.');
    }

    return chr(96) . $name . chr(96);
}

function mysqlAccount(PDO $connection, string $username, string $host): string
{
    $quotedUsername = $connection->quote($username);
    $quotedHost = $connection->quote($host);

    if ($quotedUsername === false || $quotedHost === false) {
        provisioningFailure('Unable to quote the MySQL account name.');
    }

    return $quotedUsername . '@' . $quotedHost;
}

$appUsername = environmentValue('DB_USER');
$appPassword = getenv('DB_PASS');
$appPassword = is_string($appPassword) ? $appPassword : null;
$appDatabase = environmentValue('DB_NAME');
$appCredentialError = SecurityConfig::databaseCredentialsError($appUsername, $appPassword, true);

if ($appCredentialError !== null) {
    provisioningFailure($appCredentialError);
}

if ($appDatabase === null) {
    provisioningFailure('DB_NAME must be configured.');
}

$adminUsername = environmentValue('TIL_DB_ADMIN_USER');
$adminPassword = getenv('TIL_DB_ADMIN_PASS');
$adminHost = environmentValue('TIL_DB_ADMIN_HOST', environmentValue('DB_HOST', '127.0.0.1'));
$adminPort = (int) (environmentValue('TIL_DB_ADMIN_PORT', environmentValue('DB_PORT', '3306')) ?: 0);

if ($adminUsername === null || $adminPassword === false || $adminPassword === '') {
    provisioningFailure('Set TIL_DB_ADMIN_USER and TIL_DB_ADMIN_PASS only for this provisioning command.');
}

if ($adminHost === null || str_contains($adminHost, ';') || $adminPort < 1 || $adminPort > 65535) {
    provisioningFailure('TIL_DB_ADMIN_HOST or TIL_DB_ADMIN_PORT is invalid.');
}

$accountHosts = array_values(array_unique(array_filter(
    array_map('trim', explode(',', environmentValue('TIL_DB_APP_HOSTS', 'localhost,127.0.0.1') ?: ''))
)));

if ($accountHosts === []) {
    provisioningFailure('Set TIL_DB_APP_HOSTS to the explicit application host addresses.');
}

foreach ($accountHosts as $accountHost) {
    if (str_contains($accountHost, '%') || str_contains($accountHost, '_')) {
        provisioningFailure('TIL_DB_APP_HOSTS must not contain MySQL wildcard hosts.');
    }
}

try {
    $adminConnection = new PDO(
        sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $adminHost, $adminPort),
        $adminUsername,
        $adminPassword,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $quotedPassword = $adminConnection->quote((string) $appPassword);

    if ($quotedPassword === false) {
        provisioningFailure('Unable to quote the application database password.');
    }

    $schema = databaseIdentifier($appDatabase);

    foreach ($accountHosts as $accountHost) {
        $account = mysqlAccount($adminConnection, (string) $appUsername, $accountHost);

        $adminConnection->exec("CREATE USER IF NOT EXISTS {$account} IDENTIFIED BY {$quotedPassword}");
        $adminConnection->exec("ALTER USER {$account} IDENTIFIED BY {$quotedPassword}");
        $adminConnection->exec("REVOKE ALL PRIVILEGES, GRANT OPTION FROM {$account}");
        $adminConnection->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON {$schema}.* TO {$account}");

        echo "Provisioned least-privilege database access for {$appUsername}@{$accountHost}." . PHP_EOL;
    }
} catch (Throwable $exception) {
    provisioningFailure('Application database account provisioning failed. Verify temporary administrator access and database host settings.');
}
