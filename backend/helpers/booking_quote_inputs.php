<?php
require_once __DIR__ . '/booking_input.php';
require_once __DIR__ . '/spatial_helper.php';

final class BookingQuoteInputs
{
    /**
     * Resolves geographic coordinates from payload or address landmark recognition.
     */
    public static function resolveCoordinates(mixed $data): array
    {
        $pLat = isset($data->pickup_latitude) ? (float)$data->pickup_latitude : (isset($data->pickup_lat) ? (float)$data->pickup_lat : null);
        $pLng = isset($data->pickup_longitude) ? (float)$data->pickup_longitude : (isset($data->pickup_lng) ? (float)$data->pickup_lng : null);
        $dLat = isset($data->delivery_latitude) ? (float)$data->delivery_latitude : (isset($data->delivery_lat) ? (float)$data->delivery_lat : null);
        $dLng = isset($data->delivery_longitude) ? (float)$data->delivery_longitude : (isset($data->delivery_lng) ? (float)$data->delivery_lng : null);

        $hubCoords = [
            'sabon gari'   => ['lat' => 12.0022, 'lng' => 8.5385],
            'kantin kwari' => ['lat' => 11.9961, 'lng' => 8.5274],
            'farm centre'  => ['lat' => 11.9752, 'lng' => 8.5492],
            'challawa'     => ['lat' => 11.8954, 'lng' => 8.4821],
            'bompai'       => ['lat' => 12.0150, 'lng' => 8.5550],
            'buk'          => ['lat' => 11.9780, 'lng' => 8.4230],
            'sharada'      => ['lat' => 11.9650, 'lng' => 8.4950],
            'dawanau'      => ['lat' => 12.0850, 'lng' => 8.4450],
            'nasarawa'     => ['lat' => 11.9890, 'lng' => 8.5520],
            'hotoro'       => ['lat' => 11.9610, 'lng' => 8.5830],
            'trade fair'   => ['lat' => 11.9950, 'lng' => 8.5450],
            'zoo road'     => ['lat' => 11.9730, 'lng' => 8.5250],
            'tarauni'      => ['lat' => 11.9680, 'lng' => 8.5420],
            'fagge'        => ['lat' => 12.0100, 'lng' => 8.5250],
        ];

        $pAddr = strtolower((string)($data->pickup_address ?? ''));
        $dAddr = strtolower((string)($data->delivery_address ?? ''));

        if (($pLat === null || $pLat == 0) && $pAddr !== '') {
            foreach ($hubCoords as $key => $coords) {
                if (str_contains($pAddr, $key)) {
                    $pLat = $coords['lat'];
                    $pLng = $coords['lng'];
                    break;
                }
            }
        }

        if (($dLat === null || $dLat == 0) && $dAddr !== '') {
            foreach ($hubCoords as $key => $coords) {
                if (str_contains($dAddr, $key)) {
                    $dLat = $coords['lat'];
                    $dLng = $coords['lng'];
                    break;
                }
            }
        }

        return [$pLat, $pLng, $dLat, $dLng];
    }

    /**
     * Estimates distance from valid pins/recognised landmarks; never accepts client distance or a fixed fallback.
     */
    public static function resolveDistance(mixed $data): float
    {
        [$pLat, $pLng, $dLat, $dLng] = self::resolveCoordinates($data);

        if ($pLat !== null && $pLng !== null && $dLat !== null && $dLng !== null && $pLat > 0 && $dLat > 0) {
            $meters = SpatialHelper::haversineDistanceMeters($pLat, $pLng, $dLat, $dLng);
            $km = ($meters * 1.25) / 1000.0;
            if ($km > 250) throw new TransactionBusinessException('Route is outside supported quotation bounds. Contact operations.', 422);
            return max(1.5, round($km, 1));
        }

        throw new TransactionBusinessException('Select a recognised Kano landmark or supply both location pins so the route can be estimated.', 422);
    }

    /**
     * Resolves package weight in kg without forcing client input.
     */
    public static function resolveWeight(mixed $data): float
    {
        if (!empty($data->weight_kg) && (float)$data->weight_kg > 0) {
            return BookingInput::number($data->weight_kg, 'weight_kg', 0.1, 1000);
        }
        if (!empty($data->item_weight) && (float)$data->item_weight > 0) {
            return BookingInput::number($data->item_weight, 'item_weight', 0.1, 1000);
        }

        $size = strtolower(trim((string)($data->package_size ?? $data->item_category ?? '')));
        if ($size === 'envelope' || str_contains($size, 'envelope') || str_contains($size, 'doc')) {
            return 0.5;
        }
        if ($size === 'large_package' || $size === 'heavy_cargo' || str_contains($size, 'large') || str_contains($size, 'heavy')) {
            return 10.0;
        }

        return 2.5;
    }
}
