<?php

namespace App\Tests\Bridge;

use App\Bridge\Resync;
use App\Client\Matrix;
use App\Model\DeviceState;
use App\Protocol\Commands;
use App\Transport\DemoTransport;
use PHPUnit\Framework\TestCase;

final class ResyncTest extends TestCase
{
    private const DEMO = __DIR__.'/../../resources/demo_config.bin';

    private DemoTransport $device;
    private Matrix $dsp;

    protected function setUp(): void
    {
        $this->device = DemoTransport::fromFile(self::DEMO);
        $this->dsp = new Matrix($this->device, 0.01);
    }

    private function finish(Resync $resync): int
    {
        $steps = 1;
        while (!$resync->step($this->dsp)) {
            ++$steps;
        }

        return $steps;
    }

    public function testReadsTheDeviceOneRequestPerStep(): void
    {
        $resync = new Resync();
        self::assertSame(0.0, $resync->progress());
        self::assertSame(50, $this->finish($resync));
        self::assertTrue($resync->done());
        self::assertSame(1.0, $resync->progress());

        $state = $resync->applyTo(null, '10.0.0.1');
        self::assertSame($this->device->config(), $state->config());
        self::assertSame($this->device->state()->groups(), $state->groups());
        self::assertSame(2, $state->activePreset);
        self::assertSame('10.0.0.1', $state->ip);
        self::assertSame([1], array_keys(array_filter($state->presetStored ?? [])));
        for ($slot = 0; $slot < DeviceState::PRESET_SLOTS; ++$slot) {
            self::assertSame($this->device->state()->presetName($slot), $state->presetName($slot));
        }
    }

    public function testAppliesTheRecordedWritesOnTopOfTheResult(): void
    {
        $gain = $this->device->state()->toDevice()->outputs[0]->gain;
        $resync = new Resync();
        $this->finish($resync);
        // Written after its page was read, so the pages still hold the old value
        $resync->written(Commands::gain(8, $gain + 7));

        $state = new DeviceState(str_repeat("\x00", \strlen($this->device->config())), ip: 'kept');
        self::assertSame($state, $resync->applyTo($state));
        self::assertSame($gain + 7, $state->toDevice()->outputs[0]->gain);
        self::assertSame($gain, $this->device->state()->toDevice()->outputs[0]->gain);
        self::assertSame('kept', $state->ip);
    }

    public function testRefusesAnUnfinishedResult(): void
    {
        $resync = new Resync();
        $resync->step($this->dsp);

        $this->expectException(\LogicException::class);
        $resync->applyTo(null);
    }
}
