<?php

require_once __DIR__ . '/../helpers/tracker_helper.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/jwt.php';
require_once __DIR__ . '/../helpers/auth_middleware.php';

// Test 1: Name Masking
$name1 = TrackerHelper::maskName('John Doe');
assert($name1 === 'J*** D***', "Expected 'J*** D***', got '{$name1}'");

$name2 = TrackerHelper::maskName('Amina Ibrahim Garba');
assert($name2 === 'A*** I*** G***', "Expected 'A*** I*** G***', got '{$name2}'");

$name3 = TrackerHelper::maskName('');
assert($name3 === '', "Expected empty string, got '{$name3}'");

// Test 2: Phone Masking
$phone1 = TrackerHelper::maskPhone('+2348031234567');
assert(str_contains($phone1, '***'), "Phone number not masked: {$phone1}");
assert(str_ends_with($phone1, '67'), "Phone suffix missing: {$phone1}");

$phone2 = TrackerHelper::maskPhone('08031234567');
assert(str_contains($phone2, '***'), "Phone number not masked: {$phone2}");
assert(str_ends_with($phone2, '67'), "Phone suffix missing: {$phone2}");

// Test 3: Address Masking
$addr1 = TrackerHelper::maskAddress('Flat 4, Plot 12, Zoo Road, Tarauni, Kano');
assert(str_starts_with($addr1, '***'), "Address plot number not masked: {$addr1}");
assert(str_contains($addr1, 'Zoo Road, Tarauni, Kano'), "Address context lost: {$addr1}");

$addr2 = TrackerHelper::maskAddress('14 Bompai Road, Kano');
assert(str_starts_with($addr2, '***'), "Street number not masked: {$addr2}");

// Test 4: Phone Match Verification
assert(TrackerHelper::isPhoneMatch('08031234567', '+2348031234567') === true, 'Failed matching local and international phone');
assert(TrackerHelper::isPhoneMatch('+234 803 123 4567', '08031234567') === true, 'Failed matching spaced phone');
assert(TrackerHelper::isPhoneMatch('08099999999', '08031234567') === false, 'Wrongly matched different phone');

echo "All tracking PII masking and verification unit tests passed successfully.\n";
