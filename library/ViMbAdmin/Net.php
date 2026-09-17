<?php

/**
 * Network helpers: resolve the real client IP behind a reverse proxy, and
 * IPv4/IPv6 CIDR matching. Shared by the brute-force limiter and the MCP
 * adapter so IP allowlisting/lockout sees the actual client, not the proxy.
 */
class ViMbAdmin_Net
{
    /**
     * Resolve the client IP from $server ($_SERVER), honouring a trusted-proxy
     * policy. X-Forwarded-For is client-controllable, so it is ONLY consulted
     * when the direct peer (REMOTE_ADDR) is itself a trusted proxy, and we then
     * take the right-most address in the chain that is NOT a trusted proxy
     * (the address the trusted proxy actually received the request from). This
     * defeats XFF spoofing -- prepended fake entries sit to the left and are
     * never selected.
     *
     * @param array<string,mixed> $server   typically $_SERVER
     * @param string $mode     'auto' (default) | 'off' | 'on'
     *                          - off : always REMOTE_ADDR (ignore XFF)
     *                          - auto: trust XFF only if REMOTE_ADDR is in a
     *                                  private, loopback or link-local network
     *                                  (a local proxy). Other reserved ranges
     *                                  (CGNAT, TEST-NET, 240/4, ...) are NOT
     *                                  evidence of a proxy and are rejected.
     *                          - on  : trust XFF only if REMOTE_ADDR is in $proxies
     * @param array<array-key,mixed> $proxies IP/CIDR list of trusted proxies (mode 'on')
     * @return string
     */
    public static function clientIp(array $server, string $mode = 'auto', array $proxies = []): string
    {
        $remoteValue = $server['REMOTE_ADDR'] ?? null;
        if ($remoteValue !== null && !is_string($remoteValue)) {
            return '0.0.0.0';
        }
        $remote = $remoteValue ?? '0.0.0.0';
        $mode   = strtolower($mode);

        if ($mode === 'off' || $mode === '0' || $mode === 'false') {
            return $remote;
        }

        $xff = '';
        if (isset($server['HTTP_X_FORWARDED_FOR'])) {
            if (!is_string($server['HTTP_X_FORWARDED_FOR'])) {
                return $remote;
            }
            $xff = $server['HTTP_X_FORWARDED_FOR'];
        }
        if ($xff === '') {
            return $remote;
        }

        // Only peel XFF if the request actually came through a trusted proxy.
        // The direct-peer check and the chain walk share one predicate so the
        // two can never disagree about what counts as a proxy.
        if (!self::isTrustedForwardedHeaderPeer($remote, $mode, $proxies)) {
            return $remote;
        }

        $chain = array_map('trim', explode(',', $xff));
        for ($i = count($chain) - 1; $i >= 0; $i--) {
            $ip = $chain[ $i ];
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)
                && !self::isTrustedForwardedHeaderPeer($ip, $mode, $proxies)) {
                return $ip;
            }
        }
        return $remote;
    }

    /**
     * Decide whether a direct peer may supply forwarded routing headers.
     * Auto mode deliberately admits only private, loopback, and link-local
     * networks; other reserved ranges are not evidence of a trusted proxy.
     *
     * @param array<array-key,mixed> $proxies IP/CIDR list for mode 'on'
     */
    public static function isTrustedForwardedHeaderPeer(
        string $ip,
        string $mode = 'auto',
        array $proxies = [],
    ): bool {
        $mode = strtolower($mode);
        if ($mode === 'off' || $mode === '0' || $mode === 'false') {
            return false;
        }
        if ($mode === 'auto') {
            foreach ([
                '10.0.0.0/8',
                '127.0.0.0/8',
                '169.254.0.0/16',
                '172.16.0.0/12',
                '192.168.0.0/16',
                '::1/128',
                'fc00::/7',
                'fe80::/10',
            ] as $cidr) {
                if (self::ipInCidr($ip, $cidr)) {
                    return true;
                }
            }
            return false;
        }
        foreach ($proxies as $proxy) {
            if (!is_string($proxy)) {
                continue;
            }
            $proxy = trim($proxy);
            if ($proxy !== '' && self::ipInCidr($ip, $proxy)) {
                return true;
            }
        }
        return false;
    }

    /**
     * RFC1918 / loopback / link-local / unique-local (fc00::/7).
     */
    public static function isPrivate(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
            return false;
        }                       // it's a normal public IP
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;  // valid but private/reserved
    }

    /**
     * Match $ip against a whitespace/comma-separated list of IPs or CIDRs.
     * Empty list => false (callers decide what an empty list means).
     *
     * @param string $ip
     * @param string $list  e.g. "127.0.0.1, 10.0.0.0/8 ::1"
     * @return bool
     */
    public static function ipInList(string $ip, string $list): bool
    {
        foreach (preg_split('/[\s,]+/', trim($list), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $entry) {
            if (self::ipInCidr($ip, $entry)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Match $ip against a single IP or CIDR (IPv4 + IPv6).
     */
    public static function ipInCidr(string $ip, string $cidr): bool
    {
        $parts = explode('/', $cidr);
        if (count($parts) === 1) {
            // A bare entry is an exact address, but the same address has many
            // textual forms ("::0001" / "::1", "2001:DB8::1" / "2001:db8::1").
            // A raw string comparison fails closed on every one of them, so a
            // configured trusted proxy silently stops being trusted. Compare
            // the packed binary instead, which is canonical per address.
            //
            // inet_pton() rejects anything that is not a valid address, so
            // malformed entries still fail closed. Packed lengths differ
            // between families (4 vs 16 bytes), so an IPv4 entry never matches
            // an IPv4-mapped IPv6 address or vice versa — the same family
            // discipline the CIDR branch below applies.
            $ipBin    = @inet_pton($ip);
            $entryBin = @inet_pton($cidr);
            return $ipBin !== false && $entryBin !== false && $ipBin === $entryBin;
        }
        if (count($parts) !== 2 || $parts[1] === '' || !ctype_digit($parts[1])) {
            return false;
        }

        [ $subnet, $prefix ] = $parts;

        $ipBin     = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bits = (int) $prefix;
        if ($bits > strlen($subnetBin) * 8) {
            return false;
        }

        $bytes = intdiv($bits, 8);
        $rem   = $bits % 8;

        if ($bytes > 0 && strncmp($ipBin, $subnetBin, $bytes) !== 0) {
            return false;
        }
        if ($rem === 0) {
            return true;
        }

        $mask = chr(0xff << (8 - $rem) & 0xff);
        return (($ipBin[ $bytes ] & $mask) === ($subnetBin[ $bytes ] & $mask));
    }
}
