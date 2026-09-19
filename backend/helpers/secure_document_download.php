<?php

declare(strict_types=1);

require_once __DIR__ . '/response.php';
require_once __DIR__ . '/storage_adapter.php';

/**
 * Delivers identity documents only after a controller has performed its
 * ownership or permission check. Local files are streamed as attachments;
 * object storage receives a short-lived signed redirect.
 */
final class SecureDocumentDownload
{
    public static function send(string $storageKey, string $filenameStem): void
    {
        try {
            Storage::assertSafeKey($storageKey);
            $storage = Storage::adapter();
            if (!$storage->exists($storageKey)) {
                Response::notFound('The secure KYC document is unavailable.');
            }
        } catch (InvalidArgumentException) {
            Response::notFound('The secure KYC document is unavailable.');
        } catch (Throwable $exception) {
            self::logFailure('Secure document storage is unavailable', $exception);
            Response::error('Secure document storage is temporarily unavailable. Please try again later.', 503);
        }

        if ($storage instanceof LocalStorageAdapter) {
            self::streamLocal($storage, $storageKey, $filenameStem);
        }

        self::redirectToSignedObject($storage, $storageKey);
    }

    private static function streamLocal(
        LocalStorageAdapter $storage,
        string $storageKey,
        string $filenameStem
    ): void {
        $path = $storage->resolvePath($storageKey);
        if ($path === null) {
            Response::notFound('The secure KYC document is unavailable.');
        }

        $detectedMime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!is_string($detectedMime)) {
            self::logFailure('Stored document MIME inspection failed', null);
            Response::notFound('The secure KYC document is unavailable.');
        }

        try {
            $metadata = UploadSecurity::validateForStorage(
                $path,
                $detectedMime,
                UploadSecurity::MAX_DOCUMENT_BYTES
            );
        } catch (UploadSecurityException $exception) {
            self::logFailure('Stored document failed security validation', $exception);
            Response::notFound('The secure KYC document is unavailable.');
        }

        $size = filesize($path);
        if ($size === false) {
            self::logFailure('Stored document size inspection failed', null);
            Response::notFound('The secure KYC document is unavailable.');
        }

        $filename = self::safeFilename($filenameStem, $metadata['extension']);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . $size);
        header('Cache-Control: private, no-store, no-cache, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Content-Type-Options: nosniff');
        header('X-Download-Options: noopen');
        header('Content-Security-Policy: sandbox; default-src \'none\'');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('Referrer-Policy: no-referrer');
        readfile($path);
        exit;
    }

    private static function redirectToSignedObject(StorageAdapter $storage, string $storageKey): void
    {
        try {
            $url = $storage->presignedUrl($storageKey, 300);
            $parts = parse_url($url);
            $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
            if ($scheme === '' || ($scheme !== 'https' && SecurityConfig::isProductionLike())) {
                throw new RuntimeException('Document download URL is not secure.');
            }
        } catch (Throwable $exception) {
            self::logFailure('Secure document download URL creation failed', $exception);
            Response::error('Secure document storage is temporarily unavailable. Please try again later.', 503);
        }

        header('Cache-Control: private, no-store, no-cache, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('Referrer-Policy: no-referrer');
        header('Location: ' . $url, true, 302);
        exit;
    }

    private static function safeFilename(string $stem, string $extension): string
    {
        $safeStem = trim((string) preg_replace('/[^A-Za-z0-9._-]/', '-', $stem), '.-');
        if ($safeStem === '') {
            $safeStem = 'kyc-document';
        }

        return $safeStem . '.' . $extension;
    }

    private static function logFailure(string $message, ?Throwable $exception): void
    {
        if (class_exists('Logger')) {
            Logger::warning($message, $exception === null ? [] : ['exception' => $exception->getMessage()]);
        }
    }
}
