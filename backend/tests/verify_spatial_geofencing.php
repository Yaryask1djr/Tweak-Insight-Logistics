<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../helpers/spatial_helper.php';

// Test 1: Haversine distance calculation between Sabon Gari Market and Kantin Kwari Market
$sabonGari = SpatialHelper::getHub('sabon_gari');
$kwari = SpatialHelper::getHub('kantin_kwari');

assert($sabonGari !== null, 'Sabon Gari hub must exist.');
assert($kwari !== null, 'Kantin Kwari hub must exist.');

$distanceMeters = SpatialHelper::haversineDistanceMeters(
    $sabonGari['lat'],
    $sabonGari['lng'],
    $kwari['lat'],
    $kwari['lng']
);

// Straight line distance in Kano between these adjacent markets is approx 1,380 meters (~1.4 km)
assert($distanceMeters > 1000 && $distanceMeters < 1800, "Distance between Sabon Gari and Kwari expected ~1.4km, got {$distanceMeters}m");
echo "✓ Haversine distance calculation verified ({$distanceMeters} m).\n";

// Test 2: Geofencing radius validation
assert(SpatialHelper::isWithinRadius($sabonGari['lat'], $sabonGari['lng'], $kwari['lat'], $kwari['lng'], 3000), 'Kwari should be within 3km of Sabon Gari.');
assert(!SpatialHelper::isWithinRadius($sabonGari['lat'], $sabonGari['lng'], $kwari['lat'], $kwari['lng'], 500), 'Kwari should NOT be within 500m of Sabon Gari.');
echo "✓ Spatial geofencing radius assertion verified.\n";

// Test 3: SQL ST_Distance_Sphere query string generation
$sql = SpatialHelper::distanceSphereSql('a.last_longitude', 'a.last_latitude', ':pickup_lng', ':pickup_lat');
assert(
    $sql === 'ST_Distance_Sphere(POINT(a.last_longitude, a.last_latitude), POINT(:pickup_lng, :pickup_lat))',
    'ST_Distance_Sphere SQL formulation is incorrect.'
);
echo "✓ ST_Distance_Sphere SQL formulation verified.\n";

echo "All spatial geofencing verification tests passed successfully.\n";
