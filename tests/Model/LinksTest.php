<?php

namespace App\Tests\Model;

use App\Model\Links;
use App\Transport\DemoTransport;
use PHPUnit\Framework\TestCase;

/**
 * The link masks as the vendor editor sets them, read the same way as assets/lib/link.js.
 */
final class LinksTest extends TestCase
{
    public function testTheMasterMaskNamesTheGroup(): void
    {
        // The demo: Out1+Out2 and Out5+Out6, the others alone with their own bit
        $links = new Links([3, 0, 4, 8, 48, 0, 64, 128]);

        self::assertSame([0, 1], $links->members(0));
        self::assertSame([0, 1], $links->members(1));
        self::assertSame([2], $links->members(2));
        self::assertSame([4, 5], $links->members(5));
        self::assertSame([7], $links->members(7));
        self::assertTrue($links->isLinked(1));
        self::assertFalse($links->isLinked(6));
        self::assertSame([1, 1, 0, 0, 2, 2, 0, 0], $links->groups());
    }

    public function testASlaveBeforeItsMasterAndAChannelWithoutBits(): void
    {
        $links = new Links([0, 14, 0, 0, 16, 32, 64, 128]);

        self::assertSame([0], $links->members(0));
        self::assertSame([1, 2, 3], $links->members(3));
        self::assertSame([0, 1, 1, 1, 0, 0, 0, 0], $links->groups());
    }

    public function testTheOutputsOfADeviceState(): void
    {
        $state = DemoTransport::fromFile(__DIR__.'/../../resources/demo_config.bin')->state()->toDevice()->toArray();

        self::assertSame([4, 5], Links::outputs($state)->members(4));
        self::assertSame([0], Links::outputs([])->members(0));
    }
}
