<?php

// Isolated helpers only: no bootstrap, database connection, or production writes.
require_once __DIR__ . '/../helpers/cursor_pagination.php';
require_once __DIR__ . '/../helpers/csv_export.php';
require_once __DIR__ . '/../helpers/tracker_helper.php';

function expectSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) throw new RuntimeException($message);
}

expectSame(null, CursorPagination::fromQuery([]), 'Missing cursor must retain offset mode.');
expectSame(0, CursorPagination::fromQuery(['cursor' => '']), 'An empty cursor must start at the newest record.');
expectSame(12, CursorPagination::fromQuery(['cursor' => '12']), 'A continuation must retain the integer cursor.');
$lastPage = CursorPagination::page([['id' => 3], ['id' => 2]], 2);
expectSame(false, $lastPage['has_more'], 'A full final page is not evidence of another page.');
expectSame(null, $lastPage['next_cursor'], 'The final page must not advertise a cursor.');
$firstPage = CursorPagination::page([['id' => 3], ['id' => 2], ['id' => 1]], 2);
expectSame([['id' => 3], ['id' => 2]], $firstPage['items'], 'Lookahead must not leak onto the visible page.');
expectSame(2, $firstPage['next_cursor'], 'Continuation must use the last visible ID.');

$stream = fopen('php://temp', 'w+');
CsvExport::write($stream, ['=HYPERLINK("https://example.com")', '  +1+2', "\t=1+1", 'Kano, Nigeria', 123.5]);
rewind($stream);
$row = fgetcsv($stream, 0, ',', '"', '');
fclose($stream);
expectSame("'=HYPERLINK(\"https://example.com\")", $row[0], 'Formula cells must be inert.');
expectSame("'  +1+2", $row[1], 'Whitespace must not bypass formula protection.');
expectSame("'\t=1+1", $row[2], 'Control-character formula prefixes must be inert.');
expectSame('Kano, Nigeria', $row[3], 'Ordinary CSV quoting must round-trip.');
expectSame('123.5', $row[4], 'Numeric values must remain numeric.');
$public = TrackerHelper::publicView([
    'tracking_number' => 'TIL-2026-TEST-ABCD', 'status' => 'in_transit',
    'pickup_city' => 'Kano', 'delivery_city' => 'Kano',
    'pickup_address' => 'Private home behind a named landmark',
    'delivery_contact_phone' => '08000000000', 'delivery_otp' => '123456',
    'item_description' => 'Confidential cargo', 'future_private_column' => 'secret',
]);
expectSame('Kano', $public['pickup_address'], 'Public tracking must expose only the city.');
expectSame([], array_intersect_key($public, array_flip(['delivery_contact_phone', 'delivery_otp', 'item_description', 'future_private_column'])), 'Public tracking must use an allowlist.');
echo "Audit pagination, CSV and tracking privacy helper checks passed.\n";
