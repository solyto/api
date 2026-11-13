<?php

namespace App\Shared\Services;

use App\Shared\Exceptions\BlockedUrlException;

/**
 * Refuses outbound requests to user-supplied URLs that point at private,
 * loopback, link-local or otherwise reserved addresses. The host is
 * resolved once and every address it resolves to must be public; callers
 * pin the request to the returned address (CURLOPT_RESOLVE) so a second
 * DNS answer cannot swap in an internal one. Redirects are not followed by
 * the HTTP client; callers that follow them check every hop again.
 */
class OutboundUrlGuard
{
    /** Ranges FILTER_FLAG_NO_PRIV_RANGE / NO_RES_RANGE do not cover. */
    private const array BLOCKED_RANGES = [
        '100.64.0.0/10',
        '192.0.0.0/24',
        '198.18.0.0/15',
        '64:ff9b::/96',
        '2001:db8::/32',
    ];

    /** @var \Closure(string): array<int, string> */
    private readonly \Closure $resolver;

    /**
     * @param  (\Closure(string): array<int, string>)|null  $resolver  host → IP addresses; DNS when null
     */
    public function __construct(?\Closure $resolver = null)
    {
        $this->resolver = $resolver ?? self::resolveDns(...);
    }

    /**
     * @return string the CURLOPT_RESOLVE entry ("host:port:address") the request must be pinned to
     *
     * @throws BlockedUrlException
     */
    public function check(string $url): string
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';

        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new BlockedUrlException();
        }

        $host = trim($host, '[]');
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ($this->resolver)($host);

        if ($addresses === []) {
            throw new BlockedUrlException();
        }

        foreach ($addresses as $address) {
            if (!$this->isPublic($address)) {
                throw new BlockedUrlException();
            }
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $pinned = str_contains($addresses[0], ':') ? '['.$addresses[0].']' : $addresses[0];

        return $host.':'.$port.':'.$pinned;
    }

    public function isPublic(string $address): bool
    {
        if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        $binary = inet_pton($address);

        // IPv4-mapped and IPv4-compatible IPv6 addresses reach the IPv4 host.
        if (strlen($binary) === 16 && (str_starts_with($binary, str_repeat("\0", 10)."\xff\xff") || str_starts_with($binary, str_repeat("\0", 12)))) {
            return $this->isPublic(inet_ntop(substr($binary, 12)));
        }

        foreach (self::BLOCKED_RANGES as $range) {
            if ($this->inRange($binary, $range)) {
                return false;
            }
        }

        return true;
    }

    private function inRange(string $binary, string $range): bool
    {
        [$subnet, $bits] = explode('/', $range);
        $subnet = inet_pton($subnet);

        if (strlen($subnet) !== strlen($binary)) {
            return false;
        }

        $bytes = intdiv((int) $bits, 8);
        $remainder = (int) $bits % 8;

        if (substr($binary, 0, $bytes) !== substr($subnet, 0, $bytes)) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($binary[$bytes]) & $mask) === (ord($subnet[$bytes]) & $mask);
    }

    /**
     * @return array<int, string>
     */
    private static function resolveDns(string $host): array
    {
        $addresses = gethostbynamel($host) ?: [];

        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return $addresses;
    }
}
