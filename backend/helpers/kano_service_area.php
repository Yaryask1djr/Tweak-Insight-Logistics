<?php

require_once __DIR__ . '/response.php';

/**
 * Single source of truth for the platform's operating geography.
 * Tweak Insight Logistics accepts and fulfils deliveries only within Kano.
 */
final class KanoServiceArea
{
    private const CITY = 'Kano';

    /** Common external-city references rejected before dispatch or pricing. */
    private const OUT_OF_AREA_TERMS = [
        'abuja', 'lagos', 'ibadan', 'kaduna', 'port harcourt', 'enugu',
        'benin', 'ilorin', 'jos', 'maiduguri', 'sokoto', 'zaria', 'interstate',
        'calabar', 'owerri', 'warri', 'asaba', 'aba', 'ondo', 'abeokuta', 'kano state outside'
    ];

    /** Known Kano commercial hubs, districts, LGAs, and trade markets. */
    public const KANO_DISTRICTS = [
        'kano', 'tarauni', 'nassarawa', 'nasarawa', 'fagge', 'gwale', 'dala', 'sabon gari',
        'bompai', 'kumbotso', 'ungogo', 'farm centre', 'zoo road', 'kantin kwari', 'kurmi',
        'kofar ruwa', 'sharada', 'challawa', 'gyadi gyadi', 'kofar nassarawa', 'kofar mata',
        'kofar na\'isa', 'kofar mazugal', 'hotoro', 'mariri', 'kurna', 'dorayi', 'kabuga',
        'rijiyar zaki', 'rijiyar lemo', 'dan agundi', 'giginyu', 'dakata', 'kaura goje',
        'panisau', 'janguza', 'gadon kaya', 'badawa', 'tudun wada', 'tudun murtala', 'goron dutse',
        'yakasai', 'zango', 'chigari', 'koki', 'shahuci', 'mandawari', 'kwalli', 'sheshe', 'hausawa',
        'state road', 'airport road', 'murtala muhammed way', 'hadejia road', 'katsina road',
        'silver jubilee', 'post office', 'audu bako'
    ];

    public static function assertCity(mixed $city, string $field): void
    {
        if (strcasecmp(trim((string)$city), self::CITY) !== 0) {
            Response::error("{$field} must be Kano. Tweak Insight Logistics currently serves Kano-only deliveries.", 422);
        }
    }

    /**
     * Validates that an address is within Kano without forcing the user to redundantly type "Kano"
     * if a valid Kano district/hub/street is provided.
     */
    public static function assertAddress(string &$address, string $field): void
    {
        $trimmed = trim($address);
        if (mb_strlen($trimmed) < 3) {
            Response::error("{$field} is too short. Please provide street/area details.", 422);
        }

        $normalised = strtolower($trimmed);

        // Reject explicit out-of-area / interstate destinations
        foreach (self::OUT_OF_AREA_TERMS as $term) {
            if (preg_match('/\\b' . preg_quote($term, '/') . '\\b/u', $normalised)) {
                Response::error("{$field} is outside the Kano service area. Tweak Insight Logistics operates in Kano State only.", 422);
            }
        }

        // Auto-normalize address to ensure Kano context if not already appended
        $address = self::normalizeAddress($trimmed);
    }

    /**
     * Automatically ensures address has canonical Kano context.
     * e.g., "14 Zoo Road, Tarauni" -> "14 Zoo Road, Tarauni, Kano"
     */
    public static function normalizeAddress(string $address): string
    {
        $trimmed = trim($address);
        if ($trimmed === '') {
            return '';
        }

        if (!preg_match('/\\bkano\\b/iu', $trimmed)) {
            return rtrim($trimmed, ', ') . ', Kano';
        }

        return $trimmed;
    }

    public static function assertDelivery(mixed $pickupCity, mixed $deliveryCity, string &$pickupAddress, string &$deliveryAddress): void
    {
        self::assertCity($pickupCity, 'Pickup city');
        self::assertCity($deliveryCity, 'Delivery city');
        self::assertAddress($pickupAddress, 'Pickup address');
        self::assertAddress($deliveryAddress, 'Delivery address');
    }

    public static function city(): string
    {
        return self::CITY;
    }
}
