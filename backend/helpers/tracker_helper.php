<?php

class TrackerHelper
{
    /**
     * Generates a high-entropy tracking reference: TIL-2026-X8K9-M4PQ
     * Uses cryptographically secure random alphanumeric characters (unambiguous base32 charset).
     * Prevents sequential enumeration and business volume scraping.
     */
    public static function generateTrackingNumber(?int $id = null): string
    {
        $year = date('Y');
        // Unambiguous 32-character uppercase alphabet (avoids 0/O, 1/I/L confusion)
        $charset = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
        $length = strlen($charset);
        $part1 = '';
        $part2 = '';
        for ($i = 0; $i < 4; $i++) {
            $part1 .= $charset[random_int(0, $length - 1)];
            $part2 .= $charset[random_int(0, $length - 1)];
        }
        return sprintf('TIL-%s-%s-%s', $year, $part1, $part2);
    }

    /**
     * Generates a secure 6-digit confirmation OTP
     */
    public static function generateOTP(): string
    {
        return str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Generates a high-entropy 32-character public tracking token for anonymous lookups.
     * This token is separate from the human-readable tracking_number and is never sequential.
     * It provides 128 bits of cryptographic randomness.
     */
    public static function generatePublicTrackingToken(): string
    {
        return bin2hex(random_bytes(16)); // 128 bits → 32 hex chars
    }

    /**
     * Extracts and validates a structured tracking reference or token from user input.
     * Supported formats:
     *   - High-entropy tracking reference: TIL-2026-X8K9-M4PQ
     *   - Legacy tracking reference: TIL-2026-00042
     *   - 32-character hexadecimal tracking token
     *
     * Bare numeric IDs and order numbers (#1, 100) are explicitly stripped of hashes and checked,
     * allowing callers to reject them with 400 Bad Request to eliminate enumeration attacks.
     */
    public static function parseReference(string $reference): string
    {
        $cleaned = trim($reference);
        // Strip leading # or 'Order #' prefixes if present
        $cleaned = preg_replace('/^(?:order\s*#?|#)/i', '', $cleaned);
        $cleaned = trim($cleaned);

        // Accept high-entropy TIL-YYYY-XXXX-XXXX or legacy TIL-YYYY-NNNNN formats
        if (preg_match('/^(TIL-\d{4}-[A-Z0-9]{4}-[A-Z0-9]{4})$/i', $cleaned, $matches)) {
            return strtoupper($matches[1]);
        }
        if (preg_match('/^(TIL-\d{4}-[A-Z0-9]+)$/i', $cleaned, $matches)) {
            return strtoupper($matches[1]);
        }

        // Accept raw public_tracking_token (32 hex chars)
        if (preg_match('/^[0-9a-fA-F]{32}$/', $cleaned)) {
            return strtolower($cleaned);
        }

        // Return cleaned string (DeliveryController will validate and reject bare numeric IDs)
        return $cleaned;
    }

    /**
     * Masks individual or business names for public privacy (e.g., "Johnathan Doe" -> "J*** D***")
     */
    public static function maskName(?string $name): string
    {
        $name = trim((string)$name);
        if ($name === '') {
            return '';
        }
        $parts = preg_split('/\s+/u', $name);
        $maskedParts = array_map(function ($part) {
            $characters = preg_match_all('/./us', $part, $matches) ? $matches[0] : str_split($part);
            $len = count($characters);
            if ($len <= 1) {
                return $part . '***';
            }
            return $characters[0] . '***';
        }, $parts);
        return implode(' ', $maskedParts);
    }

    /**
     * Masks phone numbers for public privacy (e.g., "+234 803 123 4567" -> "+234 803 *** **67")
     */
    public static function maskPhone(?string $phone): string
    {
        $phone = trim((string)$phone);
        if ($phone === '') {
            return '';
        }
        $digits = preg_replace('/\D/', '', $phone);
        $len = strlen($digits);
        if ($len < 7) {
            return '***-***';
        }
        $prefix = substr($phone, 0, min(8, max(4, (int)($len / 2))));
        $suffix = substr($phone, -2);
        return rtrim($prefix) . ' *** **' . $suffix;
    }

    /**
     * Masks exact house/plot numbers while retaining street, area, and city context
     * e.g., "Flat 4, Plot 12, Zoo Road, Tarauni, Kano" -> "*** Zoo Road, Tarauni, Kano"
     */
    public static function maskAddress(?string $address): string
    {
        $address = trim((string)$address);
        if ($address === '') {
            return '';
        }
        // Mask specific plot, flat, suite, or street building numbers at the start of the address
        $masked = preg_replace('/^\s*(?:no\.?|plot|flat|block|house|suite|unit)?\s*\d+[a-z]?[\s,\-\/]+/i', '*** ', $address);
        if ($masked === $address && preg_match('/^\d+/', $address)) {
            $masked = '*** ' . preg_replace('/^\d+[\s,\-\/]*/', '', $address);
        }
        return $masked ?: $address;
    }

    /**
     * Verifies if an input phone number matches an authorized contact phone
     */
    public static function isPhoneMatch(?string $inputPhone, ?string $targetPhone): bool
    {
        $inputDigits = preg_replace('/\D/', '', (string)$inputPhone);
        $targetDigits = preg_replace('/\D/', '', (string)$targetPhone);
        if (empty($inputDigits) || empty($targetDigits)) {
            return false;
        }
        if ($inputDigits === $targetDigits) {
            return true;
        }
        // Match national suffix (last 8-10 digits) to handle international (+234) vs local (080) format differences
        $minLen = min(8, strlen($inputDigits), strlen($targetDigits));
        if ($minLen >= 7) {
            return substr($inputDigits, -$minLen) === substr($targetDigits, -$minLen);
        }
        return false;
    }
}
