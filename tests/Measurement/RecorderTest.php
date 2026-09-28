<?php

namespace App\Tests\Measurement;

use App\Measurement\Recorder;
use App\Measurement\RecorderFactory;
use PHPUnit\Framework\TestCase;

/**
 * The arecord call and the list of capture devices, without a sound card.
 */
final class RecorderTest extends TestCase
{
    private const LISTING = <<<'TXT'
        **** List of CAPTURE Hardware Devices ****
        card 0: PCH [HDA Intel PCH], device 0: ALC256 Analog [ALC256 Analog]
          Subdevices: 1/1
          Subdevice #0: subdevice #0
        card 1: UMC1820 [UMC1820], device 0: USB Audio [USB Audio]
          Subdevices: 1/1
          Subdevice #0: subdevice #0
        TXT;

    public function testCapturesTwoChannelsAtLeast(): void
    {
        self::assertSame(
            ['arecord', '-q', '-D', 'plughw:CARD=UMC1820,DEV=0', '-c', '2', '-r', '48000', '-f', 'S32_LE', '-t', 'wav', '-d', '8', '/tmp/m.wav'],
            (new Recorder('plughw:CARD=UMC1820,DEV=0', 1))->command(8, '/tmp/m.wav'),
        );
        self::assertSame('6', (new Recorder('default', 6))->command(0, 'm.wav')[5]);
        self::assertSame('1', (new Recorder('default', 6))->command(0, 'm.wav')[13]);
    }

    public function testNeedsOneChannel(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Recorder('default', 0);
    }

    public function testListsTheCaptureDevicesAsPlughwNames(): void
    {
        self::assertSame([
            ['id' => 'default', 'name' => 'Default device'],
            ['id' => 'plughw:CARD=PCH,DEV=0', 'name' => 'HDA Intel PCH · ALC256 Analog'],
            ['id' => 'plughw:CARD=UMC1820,DEV=0', 'name' => 'UMC1820 · USB Audio'],
        ], RecorderFactory::listing(self::LISTING, 'plughw:CARD=UMC1820,DEV=0'));
    }

    public function testListsAConfiguredDeviceThatArecordDoesNotSee(): void
    {
        $factory = new RecorderFactory('plughw:CARD=UMC1820,DEV=0');

        self::assertSame('plughw:CARD=UMC1820,DEV=0', $factory->defaultDevice());
        self::assertSame('default', (new RecorderFactory())->defaultDevice());
        self::assertSame([
            ['id' => 'default', 'name' => 'Default device'],
            ['id' => 'plughw:CARD=UMC1820,DEV=0', 'name' => 'Configured device, not listed by arecord'],
        ], RecorderFactory::listing('', $factory->defaultDevice()));
    }
}
