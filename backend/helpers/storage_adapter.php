<?php

/**
 * StorageAdapter — Unified interface for binary file storage.
 *
 * Implementations:
 *   LocalStorageAdapter  — filesystem (default, single-instance / dev)
 *   S3StorageAdapter     — S3-compatible object storage (AWS S3, Cloudflare R2, MinIO)
 *
 * Select backend via STORAGE_DRIVER env:
 *   STORAGE_DRIVER=local   (development only)
 *   STORAGE_DRIVER=s3
 *
 * S3 env vars (all required when STORAGE_DRIVER=s3):
 *   S3_ENDPOINT      e.g. https://s3.eu-west-1.amazonaws.com  OR  https://<accountid>.r2.cloudflarestorage.com
 *   S3_BUCKET        e.g. tweak-insight-kyc-documents
 *   S3_REGION        e.g. eu-west-1  (use 'auto' for Cloudflare R2)
 *   S3_KEY           AWS / R2 Access Key ID
 *   S3_SECRET        AWS / R2 Secret Access Key
 *   S3_URL_EXPIRES   Presigned URL TTL in seconds (default 300; max 300)
 *   S3_SERVER_SIDE_ENCRYPTION=AES256|aws:kms (required in production)
 *   S3_KMS_KEY_ID    Required when aws:kms is selected
 */
require_once __DIR__ . '/upload_security.php';
require_once __DIR__ . '/security_config.php';

interface StorageAdapter
{
    /**
     * Store a file at the given logical key path.
     * @param string $key      Logical path, e.g. "driver-documents/42/abc123.pdf"
     * @param string $tmpPath  Local filesystem path to the upload temp file
     * @param string $mimeType MIME type for Content-Type metadata
     * @return bool True on success
     */
    public function put(string $key, string $tmpPath, string $mimeType, int $maxBytes = 10485760): bool;

    /**
 * Generate an authenticated, time-limited URL for accessing a stored file.
 * Local storage deliberately has no direct URL and throws instead.
     */
    public function presignedUrl(string $key, int $ttlSeconds = 3600): string;

    /**
     * Delete a stored file.
     */
    public function delete(string $key): bool;

    /**
     * Return true if a file exists at the given key.
     */
    public function exists(string $key): bool;
}


/**
 * LocalStorageAdapter — stores files in backend/storage/ outside the web root.
 * Suitable only for local development and single-instance test deployments.
 */
class LocalStorageAdapter implements StorageAdapter
{
    private string $baseDir;

    public function __construct()
    {
        if (SecurityConfig::isProductionLike()) {
            throw new RuntimeException('Local document storage is disabled outside development.');
        }

        $this->baseDir = defined('ROOT_PATH') ? ROOT_PATH . '/storage' : __DIR__ . '/../storage';
    }

    public function put(string $key, string $tmpPath, string $mimeType, int $maxBytes = 10485760): bool
    {
        Storage::assertSafeKey($key);
        UploadSecurity::validateForStorage($tmpPath, $mimeType, $maxBytes);
        UploadSecurity::scan($tmpPath);
        $base = $this->resolvedBaseDirectory();
        $dest = $base . DIRECTORY_SEPARATOR . $key;
        $dir = dirname($dest);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            return false;
        }
        $resolvedDirectory = realpath($dir);
        if (
            $resolvedDirectory === false
            || ($resolvedDirectory !== $base && !str_starts_with($resolvedDirectory, $base . DIRECTORY_SEPARATOR))
        ) {
            throw new RuntimeException('Secure document storage directory is invalid.');
        }
        if (file_exists($dest) || is_link($dest)) {
            throw new RuntimeException('Refusing to overwrite an existing secure document.');
        }
        $stored = move_uploaded_file($tmpPath, $dest) || rename($tmpPath, $dest);
        if ($stored) {
            @chmod($dest, 0600);
        }
        return $stored;
    }

    public function presignedUrl(string $key, int $ttlSeconds = 3600): string
    {
        throw new LogicException(
            'Local documents must be streamed through an authorized controller; direct download URLs are disabled.'
        );
    }

    public function delete(string $key): bool
    {
        Storage::assertSafeKey($key);
        $path = $this->resolvePath($key);

        return $path === null || @unlink($path);
    }

    public function exists(string $key): bool
    {
        Storage::assertSafeKey($key);

        return $this->resolvePath($key) !== null;
    }

    /** Resolve an existing regular file inside the private storage root. */
    public function resolvePath(string $key): ?string
    {
        Storage::assertSafeKey($key);
        $base = $this->resolvedBaseDirectory();
        $candidate = $base . DIRECTORY_SEPARATOR . $key;
        $path = realpath($candidate);

        if (
            $path === false
            || !str_starts_with($path, $base . DIRECTORY_SEPARATOR)
            || !is_file($path)
            || is_link($candidate)
        ) {
            return null;
        }

        return $path;
    }

    private function resolvedBaseDirectory(): string
    {
        if (!is_dir($this->baseDir) && !mkdir($this->baseDir, 0700, true) && !is_dir($this->baseDir)) {
            throw new RuntimeException('Secure document storage directory is unavailable.');
        }

        $base = realpath($this->baseDir);
        if ($base === false) {
            throw new RuntimeException('Secure document storage directory is unavailable.');
        }

        return rtrim($base, DIRECTORY_SEPARATOR);
    }
}


/**
 * S3StorageAdapter — stores files on any S3-compatible object storage.
 *
 * This implementation uses raw HTTPS requests with AWS Signature Version 4
 * so it requires no composer dependencies beyond PHP's built-in cURL.
 * Compatible with: AWS S3, Cloudflare R2, MinIO, Backblaze B2, DigitalOcean Spaces.
 */
class S3StorageAdapter implements StorageAdapter
{
    private string $endpoint;
    private string $bucket;
    private string $region;
    private string $key;
    private string $secret;
    private string $serverSideEncryption;
    private ?string $kmsKeyId;

    public function __construct()
    {
        $this->endpoint = rtrim((string)getenv('S3_ENDPOINT'), '/');
        $this->bucket   = (string)getenv('S3_BUCKET');
        $this->region   = (string)(getenv('S3_REGION') ?: 'auto');
        $this->key      = (string)getenv('S3_KEY');
        $this->secret   = (string)getenv('S3_SECRET');
        $this->serverSideEncryption = strtoupper(trim((string)getenv('S3_SERVER_SIDE_ENCRYPTION')));
        $this->kmsKeyId = trim((string)getenv('S3_KMS_KEY_ID')) ?: null;

        foreach (['S3_ENDPOINT', 'S3_BUCKET', 'S3_KEY', 'S3_SECRET'] as $var) {
            if (SecurityConfig::isPlaceholderValue((string) getenv($var))) {
                throw new RuntimeException("S3StorageAdapter: missing required env variable {$var}.");
            }
        }
        $endpoint = parse_url($this->endpoint);
        if (
            !is_array($endpoint)
            || !isset($endpoint['scheme'], $endpoint['host'])
            || isset($endpoint['user'], $endpoint['pass'], $endpoint['query'], $endpoint['fragment'])
            || !in_array($endpoint['path'] ?? '', ['', '/'], true)
        ) {
            throw new RuntimeException('S3StorageAdapter: S3_ENDPOINT must be a simple HTTPS object-storage origin.');
        }
        if (SecurityConfig::isProductionLike() && strtolower((string)$endpoint['scheme']) !== 'https') {
            throw new RuntimeException('S3StorageAdapter: production document storage requires an HTTPS endpoint.');
        }

        if ($this->serverSideEncryption !== '' && !in_array($this->serverSideEncryption, ['AES256', 'AWS:KMS'], true)) {
            throw new RuntimeException('S3StorageAdapter: S3_SERVER_SIDE_ENCRYPTION must be AES256 or aws:kms.');
        }
        if (
            $this->serverSideEncryption === 'AWS:KMS'
            && ($this->kmsKeyId === null || SecurityConfig::isPlaceholderValue($this->kmsKeyId))
        ) {
            throw new RuntimeException('S3StorageAdapter: S3_KMS_KEY_ID is required when using aws:kms.');
        }
        if (SecurityConfig::isProductionLike() && $this->serverSideEncryption === '') {
            throw new RuntimeException('S3StorageAdapter: production document storage requires S3_SERVER_SIDE_ENCRYPTION.');
        }
    }

    public function put(string $key, string $tmpPath, string $mimeType, int $maxBytes = 10485760): bool
    {
        Storage::assertSafeKey($key);
        UploadSecurity::validateForStorage($tmpPath, $mimeType, $maxBytes);
        UploadSecurity::scan($tmpPath);
        $body    = file_get_contents($tmpPath);
        if ($body === false) {
            return false;
        }
        $bodyHash = hash('sha256', $body);
        $now     = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $dateKey  = $now->format('Ymd');
        $amzDate  = $now->format('Ymd\THis\Z');
        $uri = $this->objectUri($key);
        $url = $this->endpoint . $uri;

        $headers = [
            'content-type'        => $mimeType,
            'content-disposition' => 'attachment; filename="document.' . UploadSecurity::extensionForMime($mimeType) . '"',
            'cache-control'       => 'private, no-store, no-cache, max-age=0',
            'host'                => $this->endpointHost(),
            'x-amz-content-sha256'=> $bodyHash,
            'x-amz-date'          => $amzDate,
        ];
        if ($this->serverSideEncryption !== '') {
            $headers['x-amz-server-side-encryption'] = strtolower($this->serverSideEncryption) === 'aws:kms' ? 'aws:kms' : 'AES256';
        }
        if ($this->kmsKeyId !== null) {
            $headers['x-amz-server-side-encryption-aws-kms-key-id'] = $this->kmsKeyId;
        }

        $authHeader = $this->buildAuthHeader('PUT', $uri, '', $headers, $bodyHash, $dateKey, $amzDate);

        $curlHeaders = ["authorization: {$authHeader}"];
        foreach ($headers as $k => $v) {
            $curlHeaders[] = "{$k}: {$v}";
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $curlHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ]);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return $httpCode === 200 || $httpCode === 201;
    }

    public function presignedUrl(string $key, int $ttlSeconds = 3600): string
    {
        Storage::assertSafeKey($key);
        // Identity documents should never receive long-lived direct URLs.
        $configuredTtl = getenv('S3_URL_EXPIRES');
        $ttl = (int)($configuredTtl !== false && $configuredTtl !== '' ? $configuredTtl : min($ttlSeconds, 300));
        $ttl = max(60, min(300, $ttl));
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $dateKey = $now->format('Ymd');
        $amzDate = $now->format('Ymd\THis\Z');
        $uri = $this->objectUri($key);
        $url = $this->endpoint . $uri;
        $host = $this->endpointHost();

        $scope        = "{$dateKey}/{$this->region}/s3/aws4_request";
        $credential   = "{$this->key}/{$scope}";
        $signedHeaders= 'host';

        $extension = $this->downloadExtension($key);
        $queryParams = [
            'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential'    => $credential,
            'X-Amz-Date'          => $amzDate,
            'X-Amz-Expires'       => $ttl,
            'X-Amz-SignedHeaders' => $signedHeaders,
            'response-cache-control' => 'private, no-store, no-cache, max-age=0',
            'response-content-disposition' => 'attachment; filename="kyc-document.' . $extension . '"',
            'response-content-type' => 'application/octet-stream',
        ];
        $queryString = $this->canonicalQuery($queryParams);

        $canonicalRequest = implode("\n", [
            'GET',
            $uri,
            $queryString,
            "host:{$host}\n",
            $signedHeaders,
            'UNSIGNED-PAYLOAD',
        ]);

        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        $signingKey = $this->signingKey($dateKey);
        $signature  = hash_hmac('sha256', $stringToSign, $signingKey);

        return "{$url}?{$queryString}&X-Amz-Signature={$signature}";
    }

    public function delete(string $key): bool
    {
        Storage::assertSafeKey($key);
        $uri = $this->objectUri($key);
        $url = $this->endpoint . $uri;
        $now     = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $dateKey = $now->format('Ymd');
        $amzDate = $now->format('Ymd\THis\Z');
        $bodyHash = hash('sha256', '');

        $headers = [
            'host'                 => $this->endpointHost(),
            'x-amz-content-sha256' => $bodyHash,
            'x-amz-date'           => $amzDate,
        ];

        $authHeader = $this->buildAuthHeader('DELETE', $uri, '', $headers, $bodyHash, $dateKey, $amzDate);
        $curlHeaders = ["authorization: {$authHeader}"];
        foreach ($headers as $k => $v) {
            $curlHeaders[] = "{$k}: {$v}";
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_HTTPHEADER     => $curlHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return $httpCode === 204;
    }

    public function exists(string $key): bool
    {
        Storage::assertSafeKey($key);
        $uri = $this->objectUri($key);
        $url = $this->endpoint . $uri;
        $now     = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $dateKey = $now->format('Ymd');
        $amzDate = $now->format('Ymd\THis\Z');
        $bodyHash = hash('sha256', '');

        $headers = [
            'host'                 => $this->endpointHost(),
            'x-amz-content-sha256' => $bodyHash,
            'x-amz-date'           => $amzDate,
        ];

        $authHeader = $this->buildAuthHeader('HEAD', $uri, '', $headers, $bodyHash, $dateKey, $amzDate);
        $curlHeaders = ["authorization: {$authHeader}"];
        foreach ($headers as $k => $v) {
            $curlHeaders[] = "{$k}: {$v}";
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'HEAD',
            CURLOPT_NOBODY         => true,
            CURLOPT_HTTPHEADER     => $curlHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return $httpCode === 200;
    }

    private function endpointHost(): string
    {
        $host = parse_url($this->endpoint, PHP_URL_HOST);
        $port = parse_url($this->endpoint, PHP_URL_PORT);
        if (!is_string($host) || $host === '') {
            throw new RuntimeException('S3StorageAdapter: invalid S3 endpoint host.');
        }

        return $port === null ? $host : $host . ':' . $port;
    }

    private function objectUri(string $key): string
    {
        Storage::assertSafeKey($key);
        $encodedKey = implode('/', array_map('rawurlencode', explode('/', ltrim($key, '/'))));

        return '/' . rawurlencode($this->bucket) . '/' . $encodedKey;
    }

    private function canonicalQuery(array $parameters): string
    {
        ksort($parameters, SORT_STRING);

        return http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }

    private function downloadExtension(string $key): string
    {
        $extension = strtolower(pathinfo($key, PATHINFO_EXTENSION));

        return in_array($extension, UploadSecurity::ALLOWED_MIME_TYPES, true) ? $extension : 'bin';
    }

    /** Build the AWS4-HMAC-SHA256 Authorization header value. */
    private function buildAuthHeader(
        string $method,
        string $uri,
        string $queryString,
        array  $headers,
        string $bodyHash,
        string $dateKey,
        string $amzDate
    ): string {
        ksort($headers);
        $canonicalHeaders = '';
        $signedHeadersList = [];
        foreach ($headers as $k => $v) {
            $canonicalHeaders  .= strtolower($k) . ':' . trim($v) . "\n";
            $signedHeadersList[] = strtolower($k);
        }
        $signedHeaders = implode(';', $signedHeadersList);

        $canonicalRequest = implode("\n", [
            $method,
            $uri,
            $queryString,
            $canonicalHeaders,
            $signedHeaders,
            $bodyHash,
        ]);

        $scope = "{$dateKey}/{$this->region}/s3/aws4_request";
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        $signingKey = $this->signingKey($dateKey);
        $signature  = hash_hmac('sha256', $stringToSign, $signingKey);

        return "AWS4-HMAC-SHA256 Credential={$this->key}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}";
    }

    /** Derive the V4 signing key from the date and secret. */
    private function signingKey(string $dateKey): string
    {
        $kDate    = hash_hmac('sha256', $dateKey,          'AWS4' . $this->secret, true);
        $kRegion  = hash_hmac('sha256', $this->region,     $kDate,    true);
        $kService = hash_hmac('sha256', 's3',              $kRegion,  true);
        return      hash_hmac('sha256', 'aws4_request',    $kService, true);
    }
}


/**
 * Storage factory — returns the configured adapter singleton.
 */
class Storage
{
    private static ?StorageAdapter $instance = null;

    public static function adapter(): StorageAdapter
    {
        if (self::$instance === null) {
            $isProduction = SecurityConfig::isProductionLike();
            $driver = strtolower(trim((string)(getenv('STORAGE_DRIVER') ?: ($isProduction ? '' : 'local'))));
            if ($isProduction && $driver !== 's3') {
                throw new RuntimeException('Production document storage requires STORAGE_DRIVER=s3 and a private object-storage bucket.');
            }
            self::$instance = match ($driver) {
                's3'    => new S3StorageAdapter(),
                'local' => new LocalStorageAdapter(),
                default => throw new RuntimeException('Unsupported document storage driver.'),
            };
        }
        return self::$instance;
    }

    /** Object keys are generated server-side; reject traversal or ambiguous keys defensively. */
    public static function assertSafeKey(string $key): void
    {
        if ($key === '' || str_contains($key, '..') || !preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]*$#', $key)) {
            throw new InvalidArgumentException('Unsafe document storage key.');
        }
    }
}
