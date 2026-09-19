<?php
/**
 * Test script for 4.1 Kano Address Validation UX Refinement.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/kano_service_area.php';

// Test 1: Address with known Kano district/hub but without literal "Kano"
$addr1 = '14 Zoo Road, Tarauni';
KanoServiceArea::assertAddress($addr1, 'Pickup address');
assert(str_contains(strtolower($addr1), 'kano'), 'Address should be auto-normalized with Kano context.');
assert($addr1 === '14 Zoo Road, Tarauni, Kano', "Expected '14 Zoo Road, Tarauni, Kano', got '{$addr1}'");

// Test 2: Address with market/hub details
$addr2 = 'Kantin Kwari Market, Shop D24';
KanoServiceArea::assertAddress($addr2, 'Delivery address');
assert($addr2 === 'Kantin Kwari Market, Shop D24, Kano', "Expected 'Kantin Kwari Market, Shop D24, Kano', got '{$addr2}'");

// Test 3: Address that already explicitly has Kano
$addr3 = 'Plot 45 Bompai Industrial Area, Sabon Gari, Kano';
KanoServiceArea::assertAddress($addr3, 'Operating address');
assert($addr3 === 'Plot 45 Bompai Industrial Area, Sabon Gari, Kano', 'Should not append duplicate Kano if already present.');

// Test 4: City assertion
KanoServiceArea::assertCity('Kano', 'Pickup city');
KanoServiceArea::assertCity('kano', 'Delivery city');
KanoServiceArea::assertCity(' KANO ', 'Hub city');

echo "✓ Kano address normalization and district recognition verified.\n";
echo "✓ All 4.1 Kano Address Validation UX tests passed successfully.\n";
