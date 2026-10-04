<?php

namespace App\Tests\Support;

use App\AutoEq\FittedBand;
use App\Measurement\Recorder;
use App\Measurement\RecorderFactory;
use App\Protocol\Scale;

/**
 * Replaces arecord in the test environment: a recording is 2 s of pink noise through a +6 dB peak at 1 kHz
 * on the last channel, the mic channel of the auto EQ. Every channel before it holds the same noise without
 * the peak, STEP_DB lower and LAG samples later per channel. The device "sdwa5-dsp-test-none" fails like a
 * missing interface.
 *
 * $probe runs at the start of every recording, so a test sees the device while it records.
 */
final class FakeRecorderFactory extends RecorderFactory
{
    public const FAILING = 'sdwa5-dsp-test-none';
    public const STEP_DB = -6.0;
    public const LAG = 48;

    /** @var (\Closure(): void)|null */
    public static ?\Closure $probe = null;

    public function create(string $device, int $channels): Recorder
    {
        return new class($device, $channels) extends Recorder {
            public function __construct(private readonly string $name, private readonly int $count)
            {
                parent::__construct($name, $count);
            }

            public function record(int $seconds, string $path): string
            {
                if (FakeRecorderFactory::FAILING === $this->name) {
                    throw new \RuntimeException(\sprintf('arecord failed on "%s": no such device', $this->name));
                }
                if (null !== FakeRecorderFactory::$probe) {
                    (FakeRecorderFactory::$probe)();
                }
                $noise = Signals::pinkNoise(2 * Scale::SAMPLE_RATE);
                $samples = Signals::peak($noise, new FittedBand(Scale::hzToIndex(1000), Scale::dbToPeqGain(6.0), Scale::qToIndex(2.0)));
                $channels = [];
                for ($steps = $this->count - 1; $steps > 0; --$steps) {
                    $gain = 10 ** (FakeRecorderFactory::STEP_DB * $steps / 20);
                    $lag = FakeRecorderFactory::LAG * $steps;
                    $channels[] = array_merge(array_fill(0, $lag, 0.0), array_map(static fn (float $x): float => $x * $gain, \array_slice($noise, 0, max(0, \count($noise) - $lag))));
                }
                $channels[] = $samples;
                file_put_contents($path, Signals::wav($channels, 24));

                return $path;
            }
        };
    }

    public function available(): bool
    {
        return true;
    }

    public function devices(): array
    {
        return [['id' => 'default', 'name' => 'Default device'], ['id' => 'plughw:CARD=Fake,DEV=0', 'name' => 'Fake · USB Audio']];
    }
}
