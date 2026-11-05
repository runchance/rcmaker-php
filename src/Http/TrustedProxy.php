<?php

declare(strict_types=1);

namespace RC\Http;

/** Immutable per-process IP policy. Usage: Request::configureTrustedProxies() during framework boot. */
final class TrustedProxy
{
    private const MAX_HEADER_BYTES = 8192;
    private const MAX_HOPS = 64;
    private array $addresses = [];
    private array $subnets = [];

    /** Compile explicit IPs/CIDRs once; never infer trust from private ranges or incoming headers. */
    public function __construct(array $proxies, public readonly string $header = 'x-forwarded-for')
    {
        if (!array_is_list($proxies) || count($proxies) > 1024) {
            throw new \InvalidArgumentException('trusted_proxies must be a list of at most 1024 IP addresses or CIDRs.');
        }
        if (!in_array($header, ['x-forwarded-for', 'x-real-ip'], true)) {
            throw new \InvalidArgumentException('trusted_proxy_header must be x-forwarded-for or x-real-ip.');
        }
        foreach ($proxies as $proxy) {
            if (!is_string($proxy)) { throw new \InvalidArgumentException('Each trusted proxy must be an IP address or CIDR string.'); }
            $parts = explode('/', trim($proxy));
            $address = self::address($parts[0]);
            if ($address === null || count($parts) > 2) {
                throw new \InvalidArgumentException('Invalid trusted proxy IP address or CIDR.');
            }
            if (count($parts) === 1) { $this->addresses[$address] = true; continue; }
            $bits = $parts[1];
            if (!preg_match('/\A(?:0|[1-9][0-9]{0,2})\z/D', $bits) || (int) $bits > (str_contains($address, ':') ? 128 : 32)) {
                throw new \InvalidArgumentException('Invalid trusted proxy CIDR prefix length.');
            }
            if ((int) $bits === (str_contains($address, ':') ? 128 : 32)) { $this->addresses[$address] = true; continue; }
            $this->subnets[] = $address . '/' . $bits;
        }
        $this->subnets = array_values(array_unique($this->subnets));
        if ($this->subnets) { require_once __DIR__ . '/trusted-proxy-bootstrap.php'; }
    }

    /** Return the first untrusted hop from the peer backwards; malformed chains fall back to the peer. */
    public function resolve(\RC\Request $request): ?string
    {
        $peer = self::address($request->remoteIp());
        if ($peer === null || !$this->trusted($peer)) { return $peer; }
        $forwarded = $request->header($this->header);
        if (!is_string($forwarded) || $forwarded === ''
            || strlen($forwarded) > self::MAX_HEADER_BYTES) {
            return $peer;
        }
        $nodes = explode(',', $forwarded);
        if (count($nodes) > self::MAX_HOPS || ($this->header === 'x-real-ip' && count($nodes) !== 1)) { return $peer; }
        foreach ($nodes as &$node) {
            $node = self::node(trim($node, " \t"));
            if ($node === null) { return $peer; }
        }
        unset($node);
        for ($index = count($nodes) - 1; $index >= 0; --$index) {
            if (!$this->trusted($nodes[$index])) { return $nodes[$index]; }
        }
        return $nodes[0];
    }

    /** Use canonical literal IPs only; mapped IPv6 remains IPv6 and requires explicit IPv6 trust. */
    private static function address(?string $address): ?string
    {
        if ($address === null || filter_var($address, FILTER_VALIDATE_IP) === false) { return null; }
        return str_contains($address, ':') ? inet_ntop(inet_pton($address)) : $address;
    }

    /** Accept plain IPv4/IPv6, IPv4:port or [IPv6]:port; never resolve hostnames or zone identifiers. */
    private static function node(string $node): ?string
    {
        if (($address = self::address($node)) !== null) { return $address; }
        if (!preg_match('/\A(?:\[([^\]]+)\]|([0-9.]+))(?::([0-9]{1,5}))?\z/D', $node, $parts)) { return null; }
        if (isset($parts[3]) && ((int) $parts[3] < 1 || (int) $parts[3] > 65535)) { return null; }
        $address = self::address($parts[1] !== '' ? $parts[1] : $parts[2]);
        if ($address === null || (($parts[1] !== '') !== str_contains($address, ':'))) { return null; }
        return $address;
    }

    /** Exact addresses use O(1) lookup; CIDR calculations use the pristine, bounded-cache upstream utility. */
    private function trusted(string $address): bool
    {
        return isset($this->addresses[$address]) || ($this->subnets && \Symfony\Component\HttpFoundation\IpUtils::checkIp($address, $this->subnets));
    }
}
