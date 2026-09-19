<?php

/**
 * Resolves the client address from the request without trusting user-supplied
 * forwarding headers on direct connections. Both security logging and rate
 * limiting use this one implementation.
 */
final class ClientIp
{
    /**
     * Trust forwarding headers only when the immediate TCP peer is one of the
     * explicitly trusted reverse-proxy/CDN networks.
     *
     * @param array<string, mixed>|null $server Optional request values for tests.
     */
    public static function resolve(?array $server = null): string
    {
        $server ??= $_SERVER;
        $remoteAddr = trim((string)($server['REMOTE_ADDR'] ?? ''));
        if (!filter_var($remoteAddr, FILTER_VALIDATE_IP)) {
            return '0.0.0.0';
        }

        if (!self::isTrustedProxy($remoteAddr)) {
            return $remoteAddr;
        }

        // Validate the complete forwarding chain before trusting any derived
        // client address. The left-most address is the client; every later
        // address must be one of our configured intermediary proxies.
        $forwarded = trim((string)($server['HTTP_X_FORWARDED_FOR'] ?? ''));
        $chain = $forwarded === '' ? [] : array_map('trim', explode(',', $forwarded));
        if ($chain !== []) {
            foreach ($chain as $address) {
                if (!filter_var($address, FILTER_VALIDATE_IP)) {
                    return $remoteAddr;
                }
            }

            $expectedHops = self::trustedProxyHopCount();
            if ($expectedHops !== null && count($chain) !== $expectedHops) {
                return $remoteAddr;
            }

            foreach (array_slice($chain, 1) as $proxyAddress) {
                if (!self::isTrustedProxy($proxyAddress)) {
                    return $remoteAddr;
                }
            }
        }

        // Only trust Cloudflare's client header after verifying that the TCP
        // peer and any forwarded intermediary chain belong to trusted proxies.
        $cloudflareIp = trim((string)($server['HTTP_CF_CONNECTING_IP'] ?? ''));
        if (filter_var($cloudflareIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $cloudflareIp;
        }

        $realIp = trim((string)($server['HTTP_X_REAL_IP'] ?? ''));
        if (filter_var($realIp, FILTER_VALIDATE_IP)) {
            return $realIp;
        }

        if ($chain !== []) {
            return $chain[0];
        }

        return $remoteAddr;
    }

    /** Whether the direct TCP peer is in TRUSTED_PROXIES. */
    public static function isTrustedProxy(string $remoteAddr): bool
    {
        foreach (self::trustedProxyCidrs() as $cidr) {
            if (self::ipInCidr($remoteAddr, $cidr)) {
                return true;
            }
        }
        return false;
    }

    /** Supports IPv4 CIDRs and exact IPv4/IPv6 addresses. */
    public static function ipInCidr(string $ip, string $cidr): bool
    {
        $cidr = trim($cidr);
        if ($cidr === '') {
            return false;
        }
        if (!str_contains($cidr, '/')) {
            return filter_var($ip, FILTER_VALIDATE_IP) !== false && hash_equals($cidr, $ip);
        }

        [$subnet, $bits] = explode('/', $cidr, 2);
        $ipBinary = @inet_pton($ip);
        $subnetBinary = @inet_pton($subnet);
        if ($ipBinary === false || $subnetBinary === false || strlen($ipBinary) !== strlen($subnetBinary) || !ctype_digit($bits)) {
            return false;
        }

        $prefixLength = (int)$bits;
        $totalBits = strlen($ipBinary) * 8;
        if ($prefixLength < 0 || $prefixLength > $totalBits) {
            return false;
        }

        $fullBytes = intdiv($prefixLength, 8);
        $remainingBits = $prefixLength % 8;
        if ($fullBytes > 0 && substr($ipBinary, 0, $fullBytes) !== substr($subnetBinary, 0, $fullBytes)) {
            return false;
        }
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
        return (ord($ipBinary[$fullBytes]) & $mask) === (ord($subnetBinary[$fullBytes]) & $mask);
    }

    /**
     * Development defaults only. Production has no implicit proxy trust: an
     * operator must explicitly configure the precise CIDRs permitted to supply
     * forwarding headers. An absent or blank value therefore returns no ranges.
     *
     * @return list<string>
     */
    private static function trustedProxyCidrs(): array
    {
        $defaults = '127.0.0.1/8,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16';
        $configured = getenv('TRUSTED_PROXIES');
        if ($configured === false || trim((string)$configured) === '') {
            if (strtolower((string)getenv('APP_ENV')) === 'production') {
                return [];
            }
            $configured = $defaults;
        }
        return array_values(array_filter(array_map('trim', explode(',', $configured))));
    }

    private static function trustedProxyHopCount(): ?int
    {
        $configured = getenv('TRUSTED_PROXY_HOPS');
        if ($configured === false || trim((string)$configured) === '') {
            return null;
        }
        return ctype_digit((string)$configured) && (int)$configured > 0 ? (int)$configured : null;
    }
}
