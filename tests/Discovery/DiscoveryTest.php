<?php

namespace App\Tests\Discovery;

use App\Discovery\Discovery;
use App\Transport\HidrawLocator;
use App\Transport\TcpTransport;
use App\Transport\TransportException;
use App\Transport\TransportFactory;
use PHPUnit\Framework\TestCase;

/**
 * The network scan against listeners on 127.0.0.1. The device string comes from a stub, the
 * handshake itself is the one of Matrix.
 */
final class DiscoveryTest extends TestCase
{
    /** @var resource|null */
    private $server;
    private int $port = 0;
    private string $arp = '';

    protected function setUp(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertNotFalse($server, (string) $error);
        $this->server = $server;
        $this->port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
        $this->arp = (string) tempnam(sys_get_temp_dir(), 'arp');
        file_put_contents($this->arp, "IP address       HW type     Flags       HW address            Mask     Device\n");
    }

    protected function tearDown(): void
    {
        if (\is_resource($this->server)) {
            fclose($this->server);
        }
        @unlink($this->arp);
    }

    private function discovery(?\Closure $identify = null, ?int $port = null): Discovery
    {
        return new Discovery(
            new HidrawLocator('/nonexistent'),
            '127.0.0.1/32',
            $port ?? $this->port,
            0.3,
            $identify ?? static fn (string $host, int $port): string => '8x8Matrix V010',
            $this->arp,
        );
    }

    public function testFindsADeviceOnAnOpenPort(): void
    {
        $asked = [];
        $found = $this->discovery(static function (string $host, int $port) use (&$asked): string {
            $asked[] = $host.':'.$port;

            return '8x8Matrix V010';
        })->scan();

        self::assertSame([['target' => '127.0.0.1:'.$this->port, 'kind' => 'tcp', 'info' => '8x8Matrix V010', 'busy' => false]], $found);
        self::assertSame(['127.0.0.1:'.$this->port], $asked);
    }

    public function testAnotherServiceOnThePortIsNoDevice(): void
    {
        self::assertSame([], $this->discovery(static fn (): ?string => null)->scan());
    }

    public function testAClosedPortIsNotAsked(): void
    {
        $asked = false;
        $closed = $this->port;
        \assert(\is_resource($this->server));
        fclose($this->server);
        $this->server = null;

        self::assertSame([], $this->discovery(static function () use (&$asked): string {
            $asked = true;

            return 'x';
        }, $closed)->scan());
        self::assertFalse($asked);
    }

    public function testSkippedHostsAreNotContacted(): void
    {
        self::assertSame([], $this->discovery()->scan(['127.0.0.1']));
    }

    public function testADeviceWithoutFreeConnectionIsBusy(): void
    {
        \assert(\is_resource($this->server));
        fclose($this->server);
        $this->server = null;
        file_put_contents($this->arp, "127.0.0.1        0x1         0x2         00:04:a3:c0:d7:30     *        eth0\n10.9.9.9         0x1         0x2         00:04:a3:00:00:01     *        eth0\n127.0.0.2        0x1         0x2         aa:bb:cc:00:00:01     *        eth0\n", \FILE_APPEND);

        $found = $this->discovery()->scan();

        // Only the host of the scanned network with the chip's MAC prefix
        self::assertCount(1, $found);
        self::assertSame('127.0.0.1:'.$this->port, $found[0]['target']);
        self::assertTrue($found[0]['busy']);
    }

    public function testNetworksFromTheOption(): void
    {
        self::assertSame(['192.168.68.0/22', '10.0.0.0/30'], array_map(strval(...), $this->discovery()->withNetworks('192.168.68.0/22, 10.0.0.0/30')->subnets()));
    }

    public function testAutoTargetOpensTheFirstDevice(): void
    {
        $factory = new TransportFactory(new HidrawLocator('/nonexistent'), '/nonexistent', 0.1, $this->discovery());

        self::assertSame('127.0.0.1:'.$this->port, $factory->discover());
        $transport = $factory->open('auto');
        self::assertInstanceOf(TcpTransport::class, $transport);
        self::assertSame('tcp://127.0.0.1:'.$this->port, $transport->describe());
        $transport->close();
    }

    public function testAutoTargetWithoutDevice(): void
    {
        $factory = new TransportFactory(new HidrawLocator('/nonexistent'), '/nonexistent', 0.1, $this->discovery(static fn (): ?string => null));

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('No DSP found on USB or on 127.0.0.1/32.');
        $factory->open('auto');
    }
}
