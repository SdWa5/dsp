<?php

namespace App\Discovery;

/**
 * An IPv4 network in CIDR notation, e.g. 192.168.68.0/22.
 */
final readonly class Subnet
{
    /** Largest network that is scanned: 1022 hosts. */
    public const MIN_PREFIX = 22;

    private function __construct(public int $network, public int $prefix)
    {
    }

    /**
     * @throws \InvalidArgumentException for anything that is not an IPv4 CIDR of at most MIN_PREFIX bits
     */
    public static function parse(string $cidr): self
    {
        if (!preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})(?:/(\d{1,2}))?$#', trim($cidr), $m) || false === $address = ip2long($m[1])) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not an IPv4 network such as 192.168.68.0/22.', $cidr));
        }
        $prefix = isset($m[2]) ? (int) $m[2] : 32;
        if ($prefix > 32 || $prefix < self::MIN_PREFIX) {
            throw new \InvalidArgumentException(\sprintf('"%s" is too large to scan. Use /%d or smaller.', $cidr, self::MIN_PREFIX));
        }

        return new self($address & self::mask($prefix), $prefix);
    }

    /**
     * The network of an interface address. A larger network is narrowed to the MIN_PREFIX block around it.
     */
    public static function around(string $address, string $netmask): self
    {
        $ip = ip2long($address);
        $mask = ip2long($netmask);
        if (false === $ip || false === $mask) {
            throw new \InvalidArgumentException(\sprintf('Invalid address %s/%s.', $address, $netmask));
        }
        $prefix = max(self::MIN_PREFIX, substr_count(decbin($mask), '1'));

        return new self($ip & self::mask($prefix), $prefix);
    }

    /**
     * Host addresses without the network and broadcast address (a /31 or /32 keeps all of them).
     *
     * @return list<string>
     */
    public function hosts(): array
    {
        $size = 1 << (32 - $this->prefix);
        $first = $size > 2 ? 1 : 0;
        $last = $size > 2 ? $size - 2 : $size - 1;
        $hosts = [];
        for ($i = $first; $i <= $last; ++$i) {
            $hosts[] = (string) long2ip($this->network + $i);
        }

        return $hosts;
    }

    public function __toString(): string
    {
        return long2ip($this->network).'/'.$this->prefix;
    }

    private static function mask(int $prefix): int
    {
        return 0 === $prefix ? 0 : (0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF;
    }
}
