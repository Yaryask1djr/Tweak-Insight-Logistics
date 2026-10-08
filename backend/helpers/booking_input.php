<?php

declare(strict_types=1);
require_once __DIR__ . '/database_transaction.php';

/** Shared quotation/booking bounds; derived prices and distance are never inputs. */
final class BookingInput
{
    public static function decode(string $raw): stdClass
    {
        if (strlen($raw) > 65536) throw new TransactionBusinessException('Request is too large.', 413);
        try { $data = json_decode($raw, false, 32, JSON_THROW_ON_ERROR); }
        catch (JsonException $e) { throw new TransactionBusinessException('A valid JSON object is required.', 422); }
        if (!$data instanceof stdClass) throw new TransactionBusinessException('A JSON object is required.', 422);
        return $data;
    }

    public static function normalize(stdClass $input, bool $booking, bool $checkSchedule = true): stdClass
    {
        $data = clone $input;
        $limits = ['pickup_address' => 1000, 'delivery_address' => 1000, 'pickup_contact_name' => 150, 'delivery_contact_name' => 150,
            'pickup_contact_phone' => 32, 'delivery_contact_phone' => 32, 'item_description' => 500, 'item_category' => 100,
            'item_dimensions' => 150, 'special_instructions' => 2000, 'pickup_city' => 80, 'delivery_city' => 80];
        $required = ['pickup_address', 'delivery_address'];
        if ($booking) $required = [...$required, 'pickup_contact_name', 'delivery_contact_name', 'pickup_contact_phone', 'delivery_contact_phone', 'item_description'];
        foreach ($limits as $field => $max) {
            $value = $data->$field ?? '';
            if (!is_string($value) || mb_strlen($value, 'UTF-8') > $max || str_contains($value, "\0")) self::reject($field . ' must be text of at most ' . $max . ' characters.');
            $data->$field = trim($value);
            if (in_array($field, $required, true) && $data->$field === '') self::reject($field . ' is required.');
        }
        foreach (['pickup_city', 'delivery_city'] as $field) {
            $data->$field = $data->$field ?: 'Kano';
            if (strcasecmp($data->$field, 'Kano') !== 0) self::reject('Both cities must be Kano.');
        }
        foreach (['pickup_contact_phone', 'delivery_contact_phone'] as $field) {
            if ($data->$field !== '' && !preg_match('/\A\+?[0-9 ()-]{7,32}\z/', $data->$field)) self::reject($field . ' is invalid.');
        }
        $data->service_type = $data->service_type ?? 'same_day';
        if (!in_array($data->service_type, ['same_day', 'scheduled', 'business'], true)) self::reject('Invalid service_type.');
        $data->package_size = $data->package_size ?? 'small_package';
        if (!in_array($data->package_size, ['envelope', 'small_package', 'large_package'], true)) self::reject('Select envelope, small_package or large_package.');
        $quantity = $data->item_quantity ?? $data->quantity ?? 1;
        if (!is_int($quantity) || $quantity < 1 || $quantity > 50) self::reject('Quantity must be an integer between 1 and 50.');
        $data->item_quantity = $quantity;
        foreach (['is_fragile', 'is_perishable'] as $field) {
            $value = $data->$field ?? false;
            if (!is_bool($value) && $value !== 0 && $value !== 1) self::reject($field . ' must be a boolean.');
            $data->$field = (bool)$value;
        }
        foreach (['weight_kg', 'item_weight'] as $field) {
            if (isset($data->$field)) self::number($data->$field, $field, 0.1, 1000);
        }
        if (isset($data->weight_kg, $data->item_weight) && (float)$data->weight_kg !== (float)$data->item_weight) self::reject('Conflicting package weights.');
        foreach (['pickup', 'delivery'] as $prefix) {
            foreach (['latitude' => 'lat', 'longitude' => 'lng'] as $suffix => $alias) {
                $field = $prefix . '_' . $suffix; $alternate = $prefix . '_' . $alias;
                if (isset($data->$field, $data->$alternate) && $data->$field !== $data->$alternate) self::reject('Conflicting coordinates.');
                $value = $data->$field ?? $data->$alternate ?? null;
                if ($value !== null) self::number($value, $field, $suffix === 'latitude' ? -90 : -180, $suffix === 'latitude' ? 90 : 180);
                $data->$field = $value;
            }
            $lat = $prefix . '_latitude'; $lng = $prefix . '_longitude';
            if (($data->$lat === null) !== ($data->$lng === null)) self::reject('Supply both latitude and longitude.');
        }
        if ($checkSchedule) self::schedule($data);
        return $data;
    }

    public static function schedule(stdClass $data): void
    {
        $parsed = [];
        foreach (['preferred_pickup_time', 'preferred_delivery_time'] as $field) {
            $value = $data->$field ?? null;
            if ($value === null || $value === '') { $data->$field = null; continue; }
            if (!is_string($value) || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:\d{2})?\z/', $value)) self::reject($field . ' must be an ISO date/time.');
            try { $date = new DateTimeImmutable($value, new DateTimeZone('Africa/Lagos')); }
            catch (Exception $e) { self::reject('Invalid schedule date.'); }
            $errors = DateTimeImmutable::getLastErrors();
            if ($errors && ($errors['warning_count'] || $errors['error_count'])) self::reject('Invalid calendar date.');
            $timestamp = $date->getTimestamp();
            if ($timestamp <= time() || $timestamp > time() + 30 * 86400) self::reject('Schedule must be in the future and within 30 days.');
            $parsed[$field] = $timestamp;
            $data->$field = $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
        if (($data->service_type ?? '') === 'scheduled' && empty($parsed['preferred_pickup_time'])) self::reject('Scheduled delivery requires a pickup time.');
        if (isset($parsed['preferred_delivery_time']) && $parsed['preferred_delivery_time'] <= ($parsed['preferred_pickup_time'] ?? time())) self::reject('Delivery time must follow pickup time.');
    }

    public static function number(mixed $value, string $field, float $min, float $max): float
    {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || $value < $min || $value > $max) self::reject($field . " must be a number between {$min} and {$max}.");
        return (float)$value;
    }
    private static function reject(string $message): never { throw new TransactionBusinessException($message, 422); }
}
