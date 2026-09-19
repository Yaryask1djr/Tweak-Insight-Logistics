<?php

require_once __DIR__ . '/response.php';

/** Authoritative delivery lifecycle and role permissions. */
final class DeliveryStatusPolicy
{
    public const ACTIVE = ['assigned', 'driver_en_route', 'picked_up', 'in_transit', 'arrived'];

    public static function driverTransition(string $from, string $to): bool
    {
        return in_array($to, [
            'assigned' => ['driver_en_route', 'failed'],
            'driver_en_route' => ['picked_up', 'failed'],
            'picked_up' => ['in_transit', 'failed'],
            'in_transit' => ['arrived', 'failed'],
            'arrived' => ['failed'],
        ][$from] ?? [], true);
    }

    public static function adminTransition(string $from, string $to): bool
    {
        return in_array($to, [
            'pending' => ['under_review', 'rejected', 'cancelled'],
            'under_review' => ['broadcasted', 'rejected', 'cancelled'],
            'broadcasted' => ['cancelled'],
            'assigned' => ['cancelled'],
            'driver_en_route' => ['cancelled'],
            'picked_up' => ['cancelled'],
            'in_transit' => ['cancelled'],
            'arrived' => ['cancelled'],
            'delivered' => ['completed'],
        ][$from] ?? [], true);
    }

    public static function label(string $status): string
    {
        return ucwords(str_replace('_', ' ', $status));
    }
}
