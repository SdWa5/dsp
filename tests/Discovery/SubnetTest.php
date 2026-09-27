<?php

namespace App\Tests\Discovery;

use App\Discovery\Subnet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SubnetTest extends TestCase
{
    public function testHostsLeaveOutNetworkAndBroadcast(): void
    {
        $hosts = Subnet::parse('192.168.68.0/22')->hosts();
        self::assertCount(1022, $hosts);
        self::assertSame('192.168.68.1', $hosts[0]);
        self::assertSame('192.168.71.254', $hosts[1021]);
    }

    public function testSmallNetworks(): void
    {
        self::assertSame(['10.0.0.1', '10.0.0.2'], Subnet::parse('10.0.0.0/30')->hosts());
        self::assertSame(['192.168.68.10'], Subnet::parse('192.168.68.10')->hosts());
        self::assertSame('192.168.68.0/24', (string) Subnet::parse('192.168.68.77/24'));
    }

    public function testInterfaceNetworksAreNarrowed(): void
    {
        self::assertSame('192.168.68.0/22', (string) Subnet::around('192.168.68.50', '255.255.252.0'));
        self::assertSame('10.131.160.0/22', (string) Subnet::around('10.131.161.38', '255.255.0.0'));
        self::assertSame('10.131.161.0/24', (string) Subnet::around('10.131.161.38', '255.255.255.0'));
    }

    #[DataProvider('invalid')]
    public function testInvalidNetworksAreRejected(string $cidr): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Subnet::parse($cidr);
    }

    /**
     * @return iterable<array{string}>
     */
    public static function invalid(): iterable
    {
        yield 'too large' => ['10.0.0.0/16'];
        yield 'no address' => ['usb'];
        yield 'bad octet' => ['300.1.1.1/24'];
        yield 'bad prefix' => ['10.0.0.0/33'];
    }
}
