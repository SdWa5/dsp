<?php

namespace App\Tests\Support;

use App\AutoEq\FittedBand;
use App\Measurement\Recorder;
use App\Measurement\RecorderFactory;
use App\Protocol\Scale;

/**
 * Replaces arecord in the test environment: a recording is 2 s of pink noise through a +6 dB peak at 1 kHz
 * on the mic channel. The device "trackdsp-test-none" fails like a missing interface.
 */
final class FakeRecorderFactory extends RecorderFactory
{
    public const FAILING = 'trackdsp-test-none';

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
                $samples = Signals::peak(Signals::pinkNoise(2 * Scale::SAMPLE_RATE), new FittedBand(Scale::hzToIndex(1000), Scale::dbToPeqGain(6.0), Scale::qToIndex(2.0)));
                $channels = array_fill(0, $this->count, array_fill(0, \count($samples), 0.0));
                $channels[$this->count - 1] = $samples;
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
