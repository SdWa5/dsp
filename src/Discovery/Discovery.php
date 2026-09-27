<?php

namespace App\Discovery;

use App\Client\DspException;
use App\Client\Matrix;
use App\Transport\HidrawLocator;
use App\Transport\TcpTransport;
use App\Transport\TransportException;

/**
 * Finds devices on USB and on the local networks.
 *
 * No discovery protocol of the device is known, so the network scan connects to TCP port 9761 of
 * every host at once and asks each open port for its device string. A host counts as a device
 * only when it answers the session start and the device info request.
 *
 * The device ignores new connections while all its TCP connections are in use, for example by the
 * vendor editor, and still answers ping. Such a device is listed as busy when the ARP table knows a
 * host of the scanned networks with the vendor prefix of its network chip.
 */
final class Discovery
{
    /** Parallel connection attempts per batch */
    private const BATCH = 256;

    /** MAC prefix of the network chip in the 8x8 (Microchip Technology) */
    public const MAC_PREFIX = '00:04:a3';

    /** Interfaces that belong to containers or VMs, not to the LAN */
    private const VIRTUAL = '/^(lo|docker|br-|veth|virbr|vnet|tun|tap|wg)/';

    /** @var \Closure(string, int): ?string */
    private readonly \Closure $identify;

    /**
     * @param string                                $networks comma-separated CIDRs, empty for the networks of the local interfaces
     * @param (\Closure(string, int): ?string)|null $identify returns the device string of host:port, null for another service
     */
    public function __construct(
        private readonly HidrawLocator $locator,
        private readonly string $networks = '',
        private readonly int $port = TcpTransport::DEFAULT_PORT,
        private readonly float $connectTimeout = 0.4,
        ?\Closure $identify = null,
        private readonly string $arpTable = '/proc/net/arp',
    ) {
        $this->identify = $identify ?? self::identifyTcp(...);
    }

    public function withNetworks(string $networks): self
    {
        return new self($this->locator, $networks, $this->port, $this->connectTimeout, $this->identify, $this->arpTable);
    }

    /**
     * Devices on USB first, then on the network, in address order.
     *
     * @param list<string> $skip hosts not to contact, for example the one the bridge is connected to
     *
     * @return list<array{target: string, kind: string, info: string, busy: bool}>
     */
    public function scan(array $skip = []): array
    {
        return [...$this->usb(), ...$this->network($skip)];
    }

    /**
     * @return list<array{target: string, kind: string, info: string, busy: bool}>
     */
    public function usb(): array
    {
        return array_map(
            static fn (string $path): array => ['target' => 'usb:'.$path, 'kind' => 'usb', 'info' => \sprintf('USB %04X:%04X', HidrawLocator::VENDOR_ID, HidrawLocator::PRODUCT_ID), 'busy' => false],
            $this->locator->find(),
        );
    }

    /**
     * @param list<string> $skip
     *
     * @return list<array{target: string, kind: string, info: string, busy: bool}>
     */
    public function network(array $skip = []): array
    {
        $hosts = array_values(array_diff($this->hosts(), $skip));
        $found = [];
        foreach ($this->openHosts($hosts) as $host) {
            $info = ($this->identify)($host, $this->port);
            if (null !== $info) {
                $found[$host] = ['target' => $this->target($host), 'kind' => 'tcp', 'info' => $info, 'busy' => false];
            }
        }
        // The connection attempts have filled the ARP table
        foreach (array_intersect($this->chipHosts(), $hosts) as $host) {
            $found[$host] ??= ['target' => $this->target($host), 'kind' => 'tcp', 'info' => 'No answer on port '.$this->port.': all connections in use, for example by the vendor editor', 'busy' => true];
        }
        uksort($found, static fn (string $a, string $b): int => ip2long($a) <=> ip2long($b));

        return array_values($found);
    }

    /**
     * @return list<Subnet>
     */
    public function subnets(): array
    {
        if ('' !== trim($this->networks)) {
            return array_map(Subnet::parse(...), array_values(array_filter(array_map(trim(...), explode(',', $this->networks)))));
        }
        $subnets = [];
        foreach (net_get_interfaces() ?: [] as $name => $interface) {
            if (preg_match(self::VIRTUAL, (string) $name) || !($interface['up'] ?? true)) {
                continue;
            }
            foreach ($interface['unicast'] ?? [] as $address) {
                if (\AF_INET === ($address['family'] ?? null) && isset($address['address'], $address['netmask'])) {
                    $subnet = Subnet::around($address['address'], $address['netmask']);
                    $subnets[(string) $subnet] = $subnet;
                }
            }
        }

        return array_values($subnets);
    }

    private function target(string $host): string
    {
        return TcpTransport::DEFAULT_PORT === $this->port ? $host : $host.':'.$this->port;
    }

    /**
     * Addresses in the ARP table whose MAC has the prefix of the device's network chip.
     *
     * @return list<string>
     */
    private function chipHosts(): array
    {
        $hosts = [];
        foreach (@file($this->arpTable, \FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $columns = preg_split('/\s+/', trim($line)) ?: [];
            // IP address, HW type, flags (0x2 = complete), HW address, mask, device
            if (\count($columns) >= 4 && '0x0' !== $columns[2] && str_starts_with(strtolower($columns[3]), self::MAC_PREFIX)) {
                $hosts[] = $columns[0];
            }
        }

        return $hosts;
    }

    /**
     * @return list<string>
     */
    private function hosts(): array
    {
        $hosts = [];
        foreach ($this->subnets() as $subnet) {
            array_push($hosts, ...$subnet->hosts());
        }

        return array_values(array_unique($hosts));
    }

    /**
     * Hosts that accept a TCP connection on the port, found with parallel non-blocking connects.
     *
     * @param list<string> $hosts
     *
     * @return list<string>
     */
    private function openHosts(array $hosts): array
    {
        $open = [];
        foreach (array_chunk($hosts, self::BATCH) as $batch) {
            /** @var array<string, resource> $pending */
            $pending = [];
            foreach ($batch as $host) {
                $stream = @stream_socket_client(\sprintf('tcp://%s:%d', $host, $this->port), $errno, $error, 0, \STREAM_CLIENT_CONNECT | \STREAM_CLIENT_ASYNC_CONNECT);
                if (false !== $stream) {
                    $pending[$host] = $stream;
                }
            }
            $deadline = microtime(true) + $this->connectTimeout;
            while ([] !== $pending && ($wait = $deadline - microtime(true)) > 0) {
                $read = $except = null;
                $write = array_values($pending);
                if (!@stream_select($read, $write, $except, 0, (int) ($wait * 1e6))) {
                    break;
                }
                foreach ($pending as $host => $stream) {
                    if (\in_array($stream, $write, true)) {
                        // Writable also means a failed connect. Only a connected socket has a peer name
                        if (false !== @stream_socket_get_name($stream, true)) {
                            $open[] = $host;
                        }
                        fclose($stream);
                        unset($pending[$host]);
                    }
                }
            }
            foreach ($pending as $stream) {
                fclose($stream);
            }
        }
        usort($open, static fn (string $a, string $b): int => ip2long($a) <=> ip2long($b));

        return $open;
    }

    private static function identifyTcp(string $host, int $port): ?string
    {
        try {
            $matrix = new Matrix(TcpTransport::connect($host, $port, 1.0), 0.5, 1);
        } catch (TransportException) {
            return null;
        }
        try {
            $matrix->handshake();

            return $matrix->info();
        } catch (DspException|TransportException) {
            return null;
        } finally {
            $matrix->close();
        }
    }
}
