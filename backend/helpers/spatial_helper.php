<?php

declare(strict_types=1);

/**
 * SpatialHelper — Spatial calculations and geofencing for Kano logistics operations.
 *
 * Provides MySQL ST_Distance_Sphere query generators and pure-PHP Haversine formulas
 * for driver proximity matching, pickup geofencing, and delivery radius calculations.
 */
final class SpatialHelper
{
    /** Approximate Earth mean radius in meters. */
    public const EARTH_RADIUS_METERS = 6371000.0;

    /** Default dispatch proximity radius in Kano metropolis (15 km). */
    public const DEFAULT_DISPATCH_RADIUS_METERS = 15000.0;

    /** Known Kano commercial hub coordinates (lat, lng). */
    public const KANO_HUBS = [
        'sabon_gari'   => ['name' => 'Sabon Gari Market',        'lat' => 12.0022, 'lng' => 8.5385],
        'kantin_kwari' => ['name' => 'Kantin Kwari Market',      'lat' => 11.9961, 'lng' => 8.5274],
        'farm_centre'  => ['name' => 'Farm Centre GSM Village',  'lat' => 11.9752, 'lng' => 8.5492],
        'challawa'     => ['name' => 'Challawa Industrial Area', 'lat' => 11.8954, 'lng' => 8.4821],
        'bompai'       => ['name' => 'Bompai Industrial Area',   'lat' => 12.0150, 'lng' => 8.5550],
        'buk_new'      => ['name' => 'BUK New Campus',           'lat' => 11.9780, 'lng' => 8.4230],
        'sharada'      => ['name' => 'Sharada Industrial Area',  'lat' => 11.9650, 'lng' => 8.4950],
        'dawanau'      => ['name' => 'Dawanau Grain Market',     'lat' => 12.0850, 'lng' => 8.4450],
        'nasarawa_gra' => ['name' => 'Nasarawa GRA',             'lat' => 11.9890, 'lng' => 8.5520],
        'hotoro_gra'   => ['name' => 'Hotoro GRA',               'lat' => 11.9610, 'lng' => 8.5830],
    ];

    /**
     * Generates a MySQL ST_Distance_Sphere expression.
     * Note: MySQL ST_Distance_Sphere(POINT(lng1, lat1), POINT(lng2, lat2)) takes (longitude, latitude).
     */
    public static function distanceSphereSql(
        string $lngExpr1,
        string $latExpr1,
        string $lngExpr2,
        string $latExpr2
    ): string {
        return "ST_Distance_Sphere(POINT({$lngExpr1}, {$latExpr1}), POINT({$lngExpr2}, {$latExpr2}))";
    }

    /**
     * Pure-PHP Haversine distance in meters between two lat/lng pairs.
     * Used for zero-database calculations, testing, and offline fallbacks.
     */
    public static function haversineDistanceMeters(
        float $lat1,
        float $lng1,
        float $lat2,
        float $lng2
    ): float {
        $lat1Rad = deg2rad($lat1);
        $lng1Rad = deg2rad($lng1);
        $lat2Rad = deg2rad($lat2);
        $lng2Rad = deg2rad($lng2);

        $deltaLat = $lat2Rad - $lat1Rad;
        $deltaLng = $lng2Rad - $lng1Rad;

        $a = sin($deltaLat / 2.0) ** 2
           + cos($lat1Rad) * cos($lat2Rad) * (sin($deltaLng / 2.0) ** 2);

        $c = 2.0 * atan2(sqrt($a), sqrt(max(0.0, 1.0 - $a)));

        return round(self::EARTH_RADIUS_METERS * $c, 2);
    }

    /**
     * Checks whether point 2 is within radiusMeters of point 1.
     */
    public static function isWithinRadius(
        float $lat1,
        float $lng1,
        float $lat2,
        float $lng2,
        float $radiusMeters = self::DEFAULT_DISPATCH_RADIUS_METERS
    ): bool {
        return self::haversineDistanceMeters($lat1, $lng1, $lat2, $lng2) <= $radiusMeters;
    }

    /**
     * Lookup landmark coordinates by ID.
     */
    public static function getHub(string $hubId): ?array
    {
        return self::KANO_HUBS[$hubId] ?? null;
    }
}
