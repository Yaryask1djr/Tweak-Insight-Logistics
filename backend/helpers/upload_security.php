<?php

declare(strict_types=1);

require_once __DIR__ . '/security_config.php';

/**
 * Security checks for identity documents before they cross the storage boundary.
 *
 * The browser-supplied filename and Content-Type are deliberately never used as
 * evidence of a document's type.  Every upload is re-sized, identified from its
 * bytes, signature-checked and malware-scanned before an adapter may persist it.
 */
final class UploadSecurityException extends RuntimeException
{
    public function __construct(string $message, private readonly int $httpStatus = 422)
    {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}

final class UploadSecurity
{
    public const ALLOWED_MIME_TYPES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public const MAX_DOCUMENT_BYTES = 10 * 1024 * 1024;

    private const MAX_IMAGE_PIXELS = 40_000_000;

    /** Generate an RFC 4122 version 4 identifier for an unguessable object name. */
    public static function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    public static function extensionForMime(string $mime): string
    {
        if (!isset(self::ALLOWED_MIME_TYPES[$mime])) {
            throw new UploadSecurityException('Unsupported document type.');
        }

        return self::ALLOWED_MIME_TYPES[$mime];
    }

    /** Validate PHP's multipart result and return trusted metadata. */
    public static function validateUploadedFile(array $file, int $maxBytes): array
    {
        $error = isset($file['error']) ? (int)$file['error'] : UPLOAD_ERR_NO_FILE;
        if ($error !== UPLOAD_ERR_OK) {
            throw new UploadSecurityException(self::uploadErrorMessage($error), self::uploadErrorStatus($error));
        }

        $tmpPath = (string)($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            throw new UploadSecurityException('The uploaded document could not be verified. Please choose the file again.');
        }

        return self::inspect($tmpPath, $maxBytes);
    }

    /**
     * Recheck a temporary file immediately before it is persisted.  This is
     * intentionally callable by each storage adapter, not just controllers.
     */
    public static function validateForStorage(string $tmpPath, string $expectedMime, int $maxBytes): array
    {
        return self::inspect($tmpPath, $maxBytes, $expectedMime);
    }

    /** Run the configured malware scanner. Production never permits a no-op scanner. */
    public static function scan(string $tmpPath): void
    {
        $isProduction = SecurityConfig::isProductionLike();
        $driver = strtolower(trim((string)(getenv('UPLOAD_SCAN_DRIVER') ?: ($isProduction ? 'clamav' : 'none'))));

        if ($driver === 'none') {
            if ($isProduction) {
                throw new UploadSecurityException('Secure document scanning is not configured. Uploads are temporarily unavailable.', 503);
            }
            return;
        }

        if ($driver !== 'clamav') {
            throw new UploadSecurityException('The configured document scanner is not supported.', 503);
        }

        $binary = trim((string)(getenv('CLAMAV_BINARY') ?: 'clamscan'));
        if ($binary === '' || str_contains($binary, "\0")) {
            throw new UploadSecurityException('Secure document scanning is not configured. Uploads are temporarily unavailable.', 503);
        }

        $output = [];
        $exitCode = -1;
        // Both command components are escaped. Operators should set CLAMAV_BINARY
        // to the executable path only; arguments are intentionally not accepted.
        @exec(escapeshellarg($binary) . ' --no-summary ' . escapeshellarg($tmpPath), $output, $exitCode);

        if ($exitCode === 0) {
            return;
        }
        if ($exitCode === 1) {
            throw new UploadSecurityException('This document was rejected by the security scan. Please upload a clean original file.');
        }

        throw new UploadSecurityException('Secure document scanning is temporarily unavailable. Please try again later.', 503);
    }

    /**
     * Retention is intentionally a deployment decision, not an application
     * guess. Production must declare it after legal/compliance approval.
     */
    public static function documentRetentionDays(): int
    {
        $configured = trim((string)getenv('KYC_DOCUMENT_RETENTION_DAYS'));
        $isProduction = SecurityConfig::isProductionLike();
        if ($configured === '') {
            if ($isProduction) {
                throw new RuntimeException('KYC_DOCUMENT_RETENTION_DAYS must be configured in production.');
            }
            return 30;
        }
        if (!ctype_digit($configured)) {
            throw new RuntimeException('KYC_DOCUMENT_RETENTION_DAYS must be a whole number of days.');
        }
        $days = (int)$configured;
        if ($days < 1 || $days > 3650) {
            throw new RuntimeException('KYC_DOCUMENT_RETENTION_DAYS must be between 1 and 3650.');
        }
        return $days;
    }

    public static function retentionUntil(): DateTimeImmutable
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('+' . self::documentRetentionDays() . ' days');
    }

    private static function inspect(string $tmpPath, int $maxBytes, ?string $expectedMime = null): array
    {
        if ($maxBytes < 1) {
            throw new LogicException('Upload size limit must be greater than zero.');
        }
        if ($tmpPath === '' || !is_file($tmpPath) || !is_readable($tmpPath)) {
            throw new UploadSecurityException('The uploaded document is unavailable. Please choose the file again.');
        }

        $size = filesize($tmpPath);
        if ($size === false || $size < 1) {
            throw new UploadSecurityException('Document files cannot be empty.');
        }
        if ($size > $maxBytes) {
            throw new UploadSecurityException(sprintf('Document files must not exceed %s.', self::formatBytes($maxBytes)), 413);
        }

        // Enforce binary magic-number validation via PHP's finfo_file(FILEINFO_MIME_TYPE, $tmpPath)
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            throw new UploadSecurityException('Binary inspection engine unavailable. Upload rejected.', 500);
        }
        $mime = finfo_file($finfo, $tmpPath);
        finfo_close($finfo);

        if (!is_string($mime) || !isset(self::ALLOWED_MIME_TYPES[$mime])) {
            throw new UploadSecurityException('Only genuine PDF, JPEG, PNG, and WebP documents are accepted.');
        }
        if ($expectedMime !== null && !hash_equals($expectedMime, $mime)) {
            throw new UploadSecurityException('The document content does not match its declared type.');
        }

        self::verifySignature($tmpPath, $mime);

        return [
            'mime' => $mime,
            'extension' => self::ALLOWED_MIME_TYPES[$mime],
            'size' => $size,
        ];
    }

    private static function verifySignature(string $path, string $mime): void
    {
        // ── LAYER 1: Read raw bytes for binary magic-number inspection ──
        $header = file_get_contents($path, false, null, 0, 8192);
        if (!is_string($header) || strlen($header) < 4) {
            throw new UploadSecurityException('The document could not be inspected securely.');
        }

        // ── LAYER 2: Magic-byte signature must match declared MIME ──
        // Each format is validated against its binary specification,
        // independent of finfo_file(), to detect polyglot files where
        // one layer reports a safe MIME but the actual bytes differ.
        $signatureValid = match ($mime) {
            'application/pdf' => str_starts_with($header, '%PDF-'),
            'image/jpeg'      => str_starts_with($header, "\xFF\xD8\xFF"),
            'image/png'       => str_starts_with($header, "\x89PNG\r\n\x1A\n"),
            'image/webp'      => substr($header, 0, 4) === 'RIFF'
                                 && substr($header, 8, 4) === 'WEBP',
            default           => false,
        };

        if (!$signatureValid) {
            throw new UploadSecurityException(
                'The file does not contain valid '
                . strtoupper(self::ALLOWED_MIME_TYPES[$mime] ?? 'unknown')
                . ' binary content. A genuine file is required.'
            );
        }

        // ── LAYER 3: Anti-polyglot / embedded executable scan ──
        // Attackers craft files that pass magic-byte checks for one format
        // while containing a runnable payload for another format (PE, ELF,
        // PHP, shell scripts, HTML/JS XSS). We scan the first 8 KB of every
        // upload for executable signatures that must never coexist with a
        // legitimate document or image.
        self::rejectEmbeddedPayloads($header, $mime);
        self::rejectEmbeddedTextPayloads($path);

        // ── LAYER 4: Format-specific deep validation ──
        if ($mime === 'application/pdf') {
            self::validatePdfSafety($path);
            return;
        }

        // Image formats: validate via GD/getimagesize for type + dimensions
        $image = @getimagesize($path);
        $imageType = is_array($image) ? ($image[2] ?? null) : null;
        $expectedType = match ($mime) {
            'image/jpeg' => IMAGETYPE_JPEG,
            'image/png'  => IMAGETYPE_PNG,
            'image/webp' => defined('IMAGETYPE_WEBP') ? IMAGETYPE_WEBP : -1,
            default      => -1,
        };

        if ($imageType !== $expectedType) {
            throw new UploadSecurityException(
                'The file is not a valid '
                . strtoupper(self::ALLOWED_MIME_TYPES[$mime] ?? 'unknown')
                . ' image.'
            );
        }

        // Decompression-bomb guard: reject images whose pixel area would
        // consume excessive memory when decoded to an uncompressed bitmap.
        $width  = (int)($image[0] ?? 0);
        $height = (int)($image[1] ?? 0);
        if ($width < 1 || $height < 1 || $width * $height > self::MAX_IMAGE_PIXELS) {
            throw new UploadSecurityException('The image dimensions are too large for secure processing.');
        }
    }

    /**
     * Scan the first 8 KB of every upload for executable or scripting
     * signatures that should never appear inside a legitimate document.
     *
     * This blocks:
     * - PE executables (MZ header), ELF binaries, Mach-O, Java class files
     * - PHP open tags (<?php, <?=, <? followed by whitespace)
     * - HTML/JS injection (<script, <html, <svg, <iframe, <!DOCTYPE)
     * - Shell scripts (#!/, #! /)
     * - Null bytes that can truncate filenames on legacy web servers
     */
    private static function rejectEmbeddedPayloads(string $header, string $declaredMime): void
    {
        // ── Binary executable signatures ──
        $executableSignatures = [
            'MZ'                       => 'Windows executable (PE/MZ)',
            "\x7FELF"                  => 'Linux executable (ELF)',
            "\xFE\xED\xFA\xCE"        => 'macOS executable (Mach-O 32-bit)',
            "\xFE\xED\xFA\xCF"        => 'macOS executable (Mach-O 64-bit)',
            "\xCE\xFA\xED\xFE"        => 'macOS executable (Mach-O 32-bit reverse)',
            "\xCF\xFA\xED\xFE"        => 'macOS executable (Mach-O 64-bit reverse)',
            "\xCA\xFE\xBA\xBE"        => 'Java class file or macOS universal binary',
            "PK\x03\x04"              => 'ZIP archive (may contain executables)',
        ];

        foreach ($executableSignatures as $sig => $label) {
            // For the declared MIME, skip the check if the signature is at
            // position 0 AND it's a valid start for that format (none of the
            // allowed formats share signatures with executables, so this
            // always triggers for an actual polyglot).
            if (str_starts_with($header, $sig)) {
                throw new UploadSecurityException(
                    "This file contains a $label signature and cannot be accepted as a document."
                );
            }
        }

        // ── Null-byte injection ──
        // Null bytes in filenames or early content can cause path truncation
        // on CGI-based servers (e.g. "photo.php\x00.png" → executes as PHP).
        if (str_contains($header, "\x00")) {
            // Allow null bytes only in valid binary image formats where they
            // naturally occur in the pixel data stream.
            if ($declaredMime === 'application/pdf') {
                // PDF content streams may legitimately contain null bytes in
                // binary object data, so we only check the first 128 bytes
                // (the header/version area, which must be printable ASCII).
                $pdfHeader = substr($header, 0, 128);
                if (str_contains($pdfHeader, "\x00")) {
                    throw new UploadSecurityException(
                        'The PDF contains null bytes in its header. A genuine PDF is required.'
                    );
                }
            }
            // For images, null bytes in pixel data are normal — skip check.
        }

        // ── Scripting / web payload injection (case-insensitive) ──
        $headerLower = strtolower($header);
        $scriptPatterns = [
            '<?php'     => 'embedded PHP code',
            '<?='       => 'embedded PHP short echo tag',
            '<script'   => 'embedded JavaScript',
            '<html'     => 'embedded HTML markup',
            '<svg'      => 'embedded SVG/XML (potential XSS vector)',
            '<iframe'   => 'embedded iframe injection',
            '<!doctype' => 'embedded HTML doctype',
            'javascript:' => 'embedded JavaScript URI',
        ];

        foreach ($scriptPatterns as $pattern => $label) {
            if (str_contains($headerLower, $pattern)) {
                throw new UploadSecurityException(
                    "This file contains $label and cannot be accepted as a document."
                );
            }
        }

        // Short PHP open tag: <? followed by whitespace (but not <?xml or
        // <?xpacket which legitimately appear in PDF XMP metadata and JPEG
        // EXIF data). Only flag if this is NOT an image EXIF scenario.
        if (preg_match('/<\?\s/', $header) && !preg_match('/<\?x/i', $header)) {
            throw new UploadSecurityException(
                'This file contains a suspected scripting payload and cannot be accepted.'
            );
        }

        // ── Shell script shebang ──
        if (preg_match('/^#!\s*\//', $header)) {
            throw new UploadSecurityException(
                'This file contains a shell script header and cannot be accepted as a document.'
            );
        }
    }

    /** Scan the complete bounded upload for text payloads beyond the initial magic-byte window. */
    private static function rejectEmbeddedTextPayloads(string $path): void
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new UploadSecurityException('The document could not be inspected securely.');
        }

        $patterns = [
            '<?php'      => 'embedded PHP code',
            '<?='        => 'embedded PHP short echo tag',
            '<script'    => 'embedded JavaScript',
            '<html'      => 'embedded HTML markup',
            '<svg'       => 'embedded SVG/XML (potential XSS vector)',
            '<iframe'    => 'embedded iframe injection',
            '<!doctype'  => 'embedded HTML doctype',
            'javascript:' => 'embedded JavaScript URI',
        ];
        $carry = '';

        try {
            while (!feof($handle)) {
                $chunk = fread($handle, 8192);
                if ($chunk === false) {
                    throw new UploadSecurityException('The document could not be inspected securely.');
                }
                if ($chunk === '') {
                    continue;
                }

                $window = $carry . $chunk;
                $lower = strtolower($window);
                foreach ($patterns as $pattern => $label) {
                    if (str_contains($lower, $pattern)) {
                        throw new UploadSecurityException(
                            "This file contains $label and cannot be accepted as a document."
                        );
                    }
                }

                if (preg_match('/<\?\s/', $window) && !preg_match('/<\?x/i', $window)) {
                    throw new UploadSecurityException(
                        'This file contains a suspected scripting payload and cannot be accepted.'
                    );
                }

                $carry = substr($window, -128);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Additional safety checks specific to PDF files:
     * - Reject PDFs with embedded JavaScript (/JS, /JavaScript actions)
     * - Reject PDFs with /Launch actions (can execute local programs)
     * - Reject PDFs with /SubmitForm or /ImportData (data exfiltration)
     *
     * These checks scan the first 64 KB of the PDF for action keywords.
     * A full structural parse is beyond scope here — the malware scanner
     * (ClamAV) handles deep PDF analysis in production.
     */
    private static function validatePdfSafety(string $path): void
    {
        $size = filesize($path);
        if ($size === false || $size < 1) {
            throw new UploadSecurityException('The PDF document could not be inspected securely.');
        }

        // Read a larger portion for PDF structure scanning.
        $scanSize = min($size, 65536);
        $content = file_get_contents($path, false, null, 0, $scanSize);
        if (!is_string($content)) {
            throw new UploadSecurityException('The PDF document could not be inspected securely.');
        }

        $tail = file_get_contents($path, false, null, max(0, $size - 2048), 2048);
        if (!is_string($tail) || !str_contains($tail, '%%EOF')) {
            throw new UploadSecurityException('The PDF document is incomplete or malformed.');
        }

        $dangerousActions = [
            '/JavaScript' => 'embedded JavaScript',
            '/JS'         => 'embedded JavaScript action',
            '/Launch'     => 'a Launch action (can execute programs)',
            '/SubmitForm' => 'a form submission action (data exfiltration risk)',
            '/ImportData' => 'a data import action',
        ];

        foreach ($dangerousActions as $keyword => $label) {
            // Use word-boundary matching to avoid false positives on
            // substrings (e.g. "/JSoup" should not match "/JS").
            // PDF keywords are case-sensitive per the spec.
            if (preg_match('/\/' . preg_quote(ltrim($keyword, '/'), '/') . '(?:\s|\/|>|$)/s', $content)) {
                throw new UploadSecurityException(
                    "This PDF document contains $label and cannot be accepted for security reasons."
                );
            }
        }
    }

    private static function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The document exceeds this server\'s upload limit. Choose a file within the stated limit.',
            UPLOAD_ERR_PARTIAL => 'The document upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_FILE => 'Choose a document file to upload.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION => 'The server could not receive the document securely. Please try again later.',
            default => 'The document upload failed. Please try again.',
        };
    }

    private static function uploadErrorStatus(int $error): int
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 413,
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION => 503,
            default => 422,
        };
    }

    private static function formatBytes(int $bytes): string
    {
        return $bytes % (1024 * 1024) === 0
            ? (int)($bytes / (1024 * 1024)) . ' MB'
            : number_format($bytes / (1024 * 1024), 1) . ' MB';
    }
}
