<?php
declare(strict_types=1);
require_once __DIR__ . '/database_transaction.php';

final class PaymentMoney
{
    public static function minor(mixed $naira): int
    {
        if ((!is_string($naira) && !is_int($naira)) || !preg_match('/\A(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,2}))?\z/', (string)$naira, $match)) {
            throw new TransactionBusinessException('Fare must be a decimal NGN amount with at most two decimal places.', 422);
        }
        $minor = (int)$match[1] * 100 + (int)str_pad($match[2] ?? '', 2, '0');
        if ($minor < 100 || $minor > 1000000000) throw new TransactionBusinessException('Fare must be between NGN 1.00 and NGN 10,000,000.00.', 422);
        return $minor;
    }
    public static function decimal(int $minor): string
    {
        return intdiv($minor, 100) . '.' . str_pad((string)($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
