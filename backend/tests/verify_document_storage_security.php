<?php

declare(strict_types=1);

/** Lightweight non-network checks for KYC document validation and storage policy. */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../helpers/storage_adapter.php';

function expectUploadFailure(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (UploadSecurityException) {
        return;
    }
    throw new RuntimeException($message);
}

$validPdf = tempnam(sys_get_temp_dir(), 'til_pdf_');
file_put_contents($validPdf, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n");
$metadata = UploadSecurity::validateForStorage($validPdf, 'application/pdf', 1024 * 1024);
assert($metadata['mime'] === 'application/pdf');
assert($metadata['extension'] === 'pdf');

$uuid = UploadSecurity::uuidV4();
assert(
    preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid) === 1,
    'Stored document names must use UUIDv4 identifiers.'
);
assert($uuid !== UploadSecurity::uuidV4(), 'Stored document UUIDs must not repeat.');

$validPng = tempnam(sys_get_temp_dir(), 'til_png_');
file_put_contents($validPng, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScLZ7wAAAABJRU5ErkJggg==', true));
$imageMetadata = UploadSecurity::validateForStorage($validPng, 'image/png', 1024 * 1024);
assert($imageMetadata['mime'] === 'image/png');

$fakePdf = tempnam(sys_get_temp_dir(), 'til_fake_');
file_put_contents($fakePdf, "this is not a PDF");
expectUploadFailure(
    fn () => UploadSecurity::validateForStorage($fakePdf, 'application/pdf', 1024 * 1024),
    'A fake PDF must be rejected from storage.'
);

expectUploadFailure(
    fn () => UploadSecurity::validateForStorage($validPdf, 'application/pdf', 8),
    'The adapter boundary must reject an over-quota document.'
);

$polyglotPdf = tempnam(sys_get_temp_dir(), 'til_polyglot_');
file_put_contents(
    $polyglotPdf,
    "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n"
    . str_repeat('A', 9000)
    . "\n<?php echo 'unsafe'; ?>\ntrailer\n<<>>\n%%EOF\n"
);
expectUploadFailure(
    fn () => UploadSecurity::validateForStorage($polyglotPdf, 'application/pdf', 1024 * 1024),
    'A PDF containing a payload beyond the initial MIME window must be rejected.'
);

try {
    Storage::assertSafeKey('../outside.pdf');
    throw new RuntimeException('Traversal storage keys must be rejected.');
} catch (InvalidArgumentException) {
    // Expected.
}

$localStorage = new LocalStorageAdapter();
try {
    $localStorage->presignedUrl('client-kyc/1/' . $uuid . '.pdf');
    throw new RuntimeException('Local storage must not expose direct document URLs.');
} catch (LogicException) {
    // Expected.
}

$s3Variables = [
    'S3_ENDPOINT', 'S3_BUCKET', 'S3_REGION', 'S3_KEY', 'S3_SECRET',
    'S3_SERVER_SIDE_ENCRYPTION', 'S3_KMS_KEY_ID', 'S3_URL_EXPIRES',
];
$savedS3Environment = [];
foreach ($s3Variables as $variable) {
    $savedS3Environment[$variable] = getenv($variable);
}
try {
    putenv('S3_ENDPOINT=https://storage.example.invalid');
    putenv('S3_BUCKET=private-kyc-documents');
    putenv('S3_REGION=auto');
    putenv('S3_KEY=test-access-key');
    putenv('S3_SECRET=test-secret');
    putenv('S3_SERVER_SIDE_ENCRYPTION=AES256');
    putenv('S3_KMS_KEY_ID=');
    putenv('S3_URL_EXPIRES=300');

    $s3Storage = new S3StorageAdapter();
    $signedUrl = $s3Storage->presignedUrl('client-kyc/1/' . $uuid . '.pdf', 3600);
    assert(str_starts_with($signedUrl, 'https://storage.example.invalid/'));
    assert(str_contains($signedUrl, 'X-Amz-Expires=300'));
    assert(str_contains($signedUrl, 'response-content-disposition=attachment%3B%20filename%3D%22kyc-document.pdf%22'));
    assert(str_contains($signedUrl, 'response-content-type=application%2Foctet-stream'));
} finally {
    foreach ($savedS3Environment as $variable => $value) {
        putenv($variable . '=' . ($value === false ? '' : $value));
    }
}

$savedEnv = [getenv('APP_ENV'), getenv('STORAGE_DRIVER'), getenv('UPLOAD_SCAN_DRIVER')];
putenv('APP_ENV=production');
putenv('UPLOAD_SCAN_DRIVER=none');
expectUploadFailure(
    fn () => UploadSecurity::scan($validPdf),
    'Production must not permit a no-op malware scanner.'
);
putenv('STORAGE_DRIVER=local');
$property = new ReflectionProperty(Storage::class, 'instance');
$property->setValue(null, null);
try {
    new LocalStorageAdapter();
    throw new RuntimeException('Production must not permit direct local adapter construction.');
} catch (RuntimeException) {
    // Expected.
}
try {
    Storage::adapter();
    throw new RuntimeException('Production must not permit the local adapter.');
} catch (RuntimeException) {
    // Expected.
} finally {
    putenv('APP_ENV=' . ($savedEnv[0] === false ? '' : $savedEnv[0]));
    putenv('STORAGE_DRIVER=' . ($savedEnv[1] === false ? '' : $savedEnv[1]));
    putenv('UPLOAD_SCAN_DRIVER=' . ($savedEnv[2] === false ? '' : $savedEnv[2]));
    $property->setValue(null, null);
}

@unlink($validPdf);
@unlink($validPng);
@unlink($fakePdf);
@unlink($polyglotPdf);
echo "Document storage security checks passed.\n";
