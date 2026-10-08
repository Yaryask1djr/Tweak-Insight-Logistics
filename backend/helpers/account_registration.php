<?php
declare(strict_types=1);
require_once __DIR__ . '/http_input.php';
require_once __DIR__ . '/transactional_schema.php';
require_once __DIR__ . '/operational_records.php';

final class AccountRegistration
{
    public static function validate(array $data, string $role): array
    {
        if (!in_array($role, ['client', 'delivery'], true)) throw new LogicException('Unsupported registration role.');
        $fields = [
            'full_name' => HttpInput::text($data, 'full_name', 150),
            'email' => HttpInput::text($data, 'email', 191),
            'phone' => HttpInput::text($data, 'phone', 32),
            'address' => HttpInput::text($data, 'address', 2000, $role === 'delivery'),
            'password' => HttpInput::text($data, 'password', 72, true, false),
        ];
        if (!filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) throw new TransactionBusinessException('Please provide a valid email address.', 422);
        if (strlen($fields['password']) < 8 || strlen($fields['password']) > 72) throw new TransactionBusinessException('Password must contain between 8 and 72 bytes.', 422);
        return $fields;
    }

    public static function create(PDO $db, array $fields, string $role): int
    {
        $fields = self::validate($fields, $role);
        $tables = $role === 'client' ? ['users', 'clients', 'audit_logs'] : ['users', 'drivers', 'driver_availability', 'audit_logs'];
        TransactionalSchema::requireInnoDB($db, $tables);
        $hash = password_hash($fields['password'], PASSWORD_DEFAULT);
        try {
            return DatabaseTransaction::run($db, static function (PDO $db) use ($fields, $role, $hash): int {
                $insert = $db->prepare('INSERT INTO users (role, full_name, email, phone, password_hash, address, is_approved) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $insert->execute([$role, $fields['full_name'], $fields['email'], $fields['phone'], $hash, $fields['address'], $role === 'client' ? 1 : 0]);
                $userId = (int)$db->lastInsertId();
                if ($role === 'client') {
                    $db->prepare("INSERT INTO clients (user_id, client_type) VALUES (?, 'individual')")->execute([$userId]);
                } else {
                    $db->prepare('INSERT INTO drivers (user_id) VALUES (?)')->execute([$userId]);
                    $driverId = (int)$db->lastInsertId();
                    $db->prepare("INSERT INTO driver_availability (driver_id, availability_status) VALUES (?, 'offline')")->execute([$driverId]);
                }
                OperationalRecords::audit($db, $userId, $role, 'account.registered', 'user', $userId, null, ['role' => $role], [], null, true);
                return $userId;
            }, 3, false);
        } catch (PDOException $e) {
            // Resolve the unique-email race after rollback; do not mask unrelated constraint failures.
            if ((string)$e->getCode() === '23000') {
                $existing = $db->prepare('SELECT id FROM users WHERE email = ?');
                $existing->execute([$fields['email']]);
                if ($existing->fetchColumn()) throw new TransactionBusinessException('An account with this email already exists.', 409);
            }
            throw $e;
        }
    }
}
