<?php
declare(strict_types=1);
require_once __DIR__ . '/database_transaction.php';

final class HttpInput
{
    public static function parseObject(string $raw, int $maxBytes = 16384): array
    {
        if (strlen($raw) > $maxBytes) throw new TransactionBusinessException('Request body is too large.', 413);
        try { $object = json_decode($raw, false, 32, JSON_THROW_ON_ERROR); }
        catch (JsonException $e) { throw new TransactionBusinessException('A valid JSON object is required.', 422); }
        if (!$object instanceof stdClass) throw new TransactionBusinessException('A JSON object is required.', 422);
        return (array)$object;
    }

    public static function readObject(int $maxBytes = 16384): array
    {
        try { return self::parseObject((string)file_get_contents('php://input', false, null, 0, $maxBytes + 1), $maxBytes); }
        catch (TransactionBusinessException $e) { Response::error($e->getMessage(), $e->getStatusCode()); }
    }

    public static function text(array $data, string $field, int $max, bool $required = true, bool $trim = true): string
    {
        $value = $data[$field] ?? '';
        if (!is_string($value) || str_contains($value, "\0")) throw new TransactionBusinessException($field . ' must be text without null characters.', 422);
        if ($trim) $value = trim($value);
        if (($required && $value === '') || mb_strlen($value, 'UTF-8') > $max) throw new TransactionBusinessException($field . ' is required and must be at most ' . $max . ' characters.', 422);
        return $value;
    }

    public static function positiveId(array $data, string $field): int
    {
        $value = $data[$field] ?? null;
        if ((!is_int($value) && !is_string($value)) || !preg_match('/\A[1-9][0-9]{0,9}\z/', (string)$value) || (int)$value > 4294967295) {
            throw new TransactionBusinessException($field . ' must be a positive identifier.', 422);
        }
        return (int)$value;
    }
}
