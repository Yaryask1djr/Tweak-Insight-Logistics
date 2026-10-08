<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers/security_config.php';

assert(SecurityConfig::jwtSecretError(str_repeat('a', 32)) === null, 'A 32-byte JWT secret should be accepted.');
assert(SecurityConfig::jwtSecretError('short') !== null, 'Short JWT secrets must be rejected.');
assert(SecurityConfig::jwtSecretError('REPLACE_WITH_64_CHAR_RANDOM_HEX') !== null, 'JWT placeholders must be rejected.');
assert(SecurityConfig::isPlaceholderValue('REPLACE_WITH_OBJECT_STORAGE_SECRET'), 'Secret-manager placeholders must be rejected.');
assert(
    SecurityConfig::databaseCredentialsError('root', str_repeat('a', 32), true) !== null,
    'The root database account must be rejected.'
);
assert(
    SecurityConfig::databaseCredentialsError('til_app', '', true) !== null,
    'Empty database passwords must be rejected.'
);
assert(
    SecurityConfig::databaseCredentialsError('til_app', str_repeat('a', 32), true) === null,
    'A dedicated account with a 32-byte password should be accepted.'
);

// Environment Hardening Tests
putenv('APP_ENV=production');
putenv('APP_DEBUG=true');
assert(SecurityConfig::productionEnvironmentError() !== null, 'APP_DEBUG=true in production must trigger an error.');

putenv('APP_DEBUG=false');
assert(SecurityConfig::productionEnvironmentError() === null, 'APP_DEBUG=false in production should be valid.');

// Reset to development for local suite
putenv('APP_ENV=development');
putenv('APP_DEBUG=true');
assert(SecurityConfig::productionEnvironmentError() === null, 'APP_DEBUG=true in development is permitted.');

echo "Credential configuration verification passed.\n";
