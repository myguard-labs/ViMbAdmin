<?php

require __DIR__ . '/../library/ViMbAdmin/Net.php';

final class NetworkAssertions
{
    public static int $failures = 0;
}

function networkCheck(string $label, bool $ok): void
{
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        NetworkAssertions::$failures++;
    }
}

echo "== trusted proxy and CIDR boundaries ==\n";

$forwarded = [
    'REMOTE_ADDR' => '10.0.0.2',
    'HTTP_X_FORWARDED_FOR' => '198.51.100.99, 203.0.113.5, 10.0.0.3',
];
networkCheck('auto mode selects the right-most untrusted valid hop', ViMbAdmin_Net::clientIp($forwarded) === '203.0.113.5');
networkCheck('off mode ignores X-Forwarded-For', ViMbAdmin_Net::clientIp($forwarded, 'off') === '10.0.0.2');
networkCheck('false mode ignores X-Forwarded-For', ViMbAdmin_Net::clientIp($forwarded, 'false') === '10.0.0.2');
networkCheck('zero mode ignores X-Forwarded-For', ViMbAdmin_Net::clientIp($forwarded, '0') === '10.0.0.2');
networkCheck(
    'auto mode ignores X-Forwarded-For from a public direct peer',
    ViMbAdmin_Net::clientIp(['REMOTE_ADDR' => '8.8.8.8', 'HTTP_X_FORWARDED_FOR' => '203.0.113.5']) === '8.8.8.8'
);
networkCheck(
    'explicit mode trusts a configured proxy CIDR',
    ViMbAdmin_Net::clientIp($forwarded, 'on', ['10.0.0.0/8']) === '203.0.113.5'
);
networkCheck(
    'explicit mode ignores an unconfigured direct peer',
    ViMbAdmin_Net::clientIp($forwarded, 'on', ['192.168.0.0/16']) === '10.0.0.2'
);
networkCheck(
    'invalid forwarded hops are skipped',
    ViMbAdmin_Net::clientIp(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.8, not-an-ip']) === '203.0.113.8'
);
networkCheck(
    'an entirely trusted chain falls back to the direct peer',
    ViMbAdmin_Net::clientIp(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '10.0.0.3'], 'on', ['10.0.0.0/8']) === '10.0.0.2'
);

// Negative controls: reserved-but-not-private ranges are NOT evidence of a
// local reverse proxy, so a peer in one of them must never have its
// X-Forwarded-For peeled in auto mode (VIM-D06).
foreach ([ '100.64.0.1', '192.0.2.10', '198.51.100.7', '203.0.113.9', '240.0.0.1', '0.0.0.1' ] as $reservedPeer) {
    networkCheck(
        "auto mode ignores X-Forwarded-For from reserved peer {$reservedPeer}",
        ViMbAdmin_Net::clientIp(
            ['REMOTE_ADDR' => $reservedPeer, 'HTTP_X_FORWARDED_FOR' => '8.8.4.4']
        ) === $reservedPeer
    );
}
networkCheck(
    'auto mode ignores X-Forwarded-For from a reserved IPv6 peer',
    ViMbAdmin_Net::clientIp(
        ['REMOTE_ADDR' => '2001:db8::1', 'HTTP_X_FORWARDED_FOR' => '8.8.4.4']
    ) === '2001:db8::1'
);
// A chain hop in a reserved-but-not-private range is a real client, not a
// proxy, so the walk must return it instead of skipping past it.
networkCheck(
    'auto mode returns a CGNAT chain hop rather than skipping it',
    ViMbAdmin_Net::clientIp(
        ['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '8.8.4.4, 100.64.0.1']
    ) === '100.64.0.1'
);
networkCheck(
    'auto mode returns a TEST-NET chain hop rather than skipping it',
    ViMbAdmin_Net::clientIp(
        ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '8.8.4.4, 192.0.2.10']
    ) === '192.0.2.10'
);
networkCheck(
    'auto mode returns a 240/4 chain hop rather than skipping it',
    ViMbAdmin_Net::clientIp(
        ['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '8.8.4.4, 240.0.0.1']
    ) === '240.0.0.1'
);

networkCheck('loopback is private', ViMbAdmin_Net::isPrivate('127.0.0.1'));
networkCheck('public IPv4 is not private', !ViMbAdmin_Net::isPrivate('8.8.8.8'));
networkCheck('IPv6 unique-local is private', ViMbAdmin_Net::isPrivate('fd00::1'));
networkCheck('malformed address is not private', !ViMbAdmin_Net::isPrivate('not-an-ip'));

networkCheck(
    'forwarded headers reject 0/8 in auto mode',
    !ViMbAdmin_Net::isTrustedForwardedHeaderPeer('0.0.0.1')
);
networkCheck(
    'forwarded headers reject reserved 240/4 in auto mode',
    !ViMbAdmin_Net::isTrustedForwardedHeaderPeer('240.0.0.1')
);
networkCheck(
    'forwarded headers reject IPv6 unspecified in auto mode',
    !ViMbAdmin_Net::isTrustedForwardedHeaderPeer('::')
);
networkCheck(
    'forwarded headers reject IPv6 multicast in auto mode',
    !ViMbAdmin_Net::isTrustedForwardedHeaderPeer('ff02::1')
);

networkCheck('IPv4 exact address matches', ViMbAdmin_Net::ipInCidr('192.0.2.1', '192.0.2.1'));
networkCheck('invalid exact values never match themselves', !ViMbAdmin_Net::ipInCidr('bad', 'bad'));
networkCheck('IPv4 CIDR contains an in-range address', ViMbAdmin_Net::ipInCidr('192.0.2.42', '192.0.2.0/24'));
networkCheck('IPv4 CIDR excludes an out-of-range address', !ViMbAdmin_Net::ipInCidr('192.0.3.1', '192.0.2.0/24'));
networkCheck('IPv4 zero prefix accepts any IPv4 address', ViMbAdmin_Net::ipInCidr('203.0.113.5', '0.0.0.0/0'));
networkCheck('IPv6 exact address matches', ViMbAdmin_Net::ipInCidr('2001:db8::1', '2001:db8::1'));
networkCheck('IPv6 CIDR contains an in-range address', ViMbAdmin_Net::ipInCidr('2001:db8::42', '2001:db8::/64'));
networkCheck('mixed IP families do not match', !ViMbAdmin_Net::ipInCidr('192.0.2.1', '2001:db8::/64'));

foreach (['192.0.2.0/-1', '192.0.2.0/33', '2001:db8::/129', '192.0.2.0/', '192.0.2.0/nope', '192.0.2.0/24/1'] as $invalidCidr) {
    networkCheck("invalid CIDR prefix {$invalidCidr} is rejected", !ViMbAdmin_Net::ipInCidr('192.0.2.1', $invalidCidr));
}
echo "== bare allowlist entries normalise before comparing ==\n";

// A bare (non-CIDR) entry is an exact address. The same address has many
// textual forms, and a raw string comparison fails CLOSED on all of them: a
// configured trusted proxy silently stops being trusted. Both sides are
// normalised with inet_pton, so equivalent forms match.
networkCheck(
    'IPv6 leading-zero form matches its canonical entry',
    ViMbAdmin_Net::ipInCidr('::1', '::0001')
);
networkCheck(
    'IPv6 canonical form matches a leading-zero entry',
    ViMbAdmin_Net::ipInCidr('::0001', '::1')
);
networkCheck(
    'IPv6 compressed form matches its expanded entry',
    ViMbAdmin_Net::ipInCidr('2001:db8::1', '2001:0db8:0000:0000:0000:0000:0000:0001')
);
networkCheck(
    'IPv6 entries match case-insensitively',
    ViMbAdmin_Net::ipInCidr('2001:db8::1', '2001:DB8::1')
);
networkCheck(
    'an equivalent IPv6 form is trusted through the list form',
    ViMbAdmin_Net::ipInList('::1', '10.0.0.0/8, ::0001')
);

// Negative control: normalisation must not make genuinely different addresses
// match. These stay false whether or not the entry is normalised.
networkCheck(
    'a different IPv6 address still does not match',
    !ViMbAdmin_Net::ipInCidr('::2', '::0001')
);
networkCheck(
    'a different IPv4 address still does not match',
    !ViMbAdmin_Net::ipInCidr('192.0.2.2', '192.0.2.1')
);
networkCheck(
    'a near-miss IPv6 address still does not match',
    !ViMbAdmin_Net::ipInCidr('2001:db8::11', '2001:db8::1')
);

// Family discipline, consistent with the CIDR branch, which requires equal
// packed lengths: an IPv4 entry never matches an IPv4-mapped IPv6 address.
networkCheck(
    'an IPv4 address does not match an IPv4-mapped IPv6 entry',
    !ViMbAdmin_Net::ipInCidr('192.0.2.1', '::ffff:192.0.2.1')
);
networkCheck(
    'an IPv4-mapped IPv6 address does not match an IPv4 entry',
    !ViMbAdmin_Net::ipInCidr('::ffff:192.0.2.1', '192.0.2.1')
);

// Malformed input fails closed and raises nothing.
networkCheck(
    'a garbage entry does not match a valid address',
    !ViMbAdmin_Net::ipInCidr('192.0.2.1', 'not-an-ip')
);
networkCheck(
    'a garbage address does not match a valid entry',
    !ViMbAdmin_Net::ipInCidr('not-an-ip', '192.0.2.1')
);
networkCheck(
    'two identical garbage values do not match each other',
    !ViMbAdmin_Net::ipInCidr('garbage', 'garbage')
);
networkCheck(
    'an empty entry does not match',
    !ViMbAdmin_Net::ipInCidr('192.0.2.1', '')
);
networkCheck(
    'an ambiguous leading-zero IPv4 entry is rejected',
    !ViMbAdmin_Net::ipInCidr('192.0.2.1', '192.0.2.001')
);

networkCheck('empty list does not match', !ViMbAdmin_Net::ipInList('192.0.2.1', ''));
networkCheck('malformed list entries do not match', !ViMbAdmin_Net::ipInList('192.0.2.1', 'bad, 2001:db8::/64'));
networkCheck('whitespace/comma list matches a later CIDR', ViMbAdmin_Net::ipInList('192.0.2.1', "bad,\n192.0.2.0/24"));

$failureCount = NetworkAssertions::$failures;
echo $failureCount === 0 ? "\nALL PASSED\n" : "\n{$failureCount} FAILED\n";
exit(min(1, $failureCount));
