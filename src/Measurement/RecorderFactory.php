<?php

namespace App\Measurement;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Creates recorders and lists the ALSA capture devices. The tests replace it with a fake that writes a
 * synthetic recording.
 */
class RecorderFactory
{
    /**
     * @param string $defaultDevice capture device when none is given (SDWA5_DSP_CAPTURE_DEVICE)
     */
    public function __construct(
        #[Autowire('%env(legacy:SDWA5_DSP_CAPTURE_DEVICE)%')]
        private readonly string $defaultDevice = 'default',
    ) {
    }

    public function create(string $device, int $channels): Recorder
    {
        return new Recorder($device, $channels);
    }

    /**
     * The capture device of the auto EQ and the measurement when none is given.
     */
    public function defaultDevice(): string
    {
        return $this->defaultDevice;
    }

    public function available(): bool
    {
        return null !== (new ExecutableFinder())->find('arecord');
    }

    /**
     * The capture devices from "arecord -l", see listing(). Empty when arecord is missing.
     *
     * @return list<array{id: string, name: string}>
     */
    public function devices(): array
    {
        if (!$this->available()) {
            return [];
        }
        $process = new Process(['arecord', '-l']);
        $process->setTimeout(10);
        $process->run();

        return self::listing($process->getOutput(), $this->defaultDevice);
    }

    /**
     * "default" first, then the devices of an "arecord -l" listing as plughw names. A configured default device
     * the listing does not name comes last, so a missing interface shows instead of another card being chosen.
     *
     * @return list<array{id: string, name: string}>
     */
    public static function listing(string $listing, string $defaultDevice = 'default'): array
    {
        $devices = [['id' => 'default', 'name' => 'Default device'], ...self::parse($listing)];
        if (!\in_array($defaultDevice, array_column($devices, 'id'), true)) {
            $devices[] = ['id' => $defaultDevice, 'name' => 'Configured device, not listed by arecord'];
        }

        return $devices;
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    public static function parse(string $listing): array
    {
        preg_match_all('/^card \d+: (\S+) \[([^\]]*)\], device (\d+): [^\[]*\[([^\]]*)\]/m', $listing, $matches, \PREG_SET_ORDER);

        return array_map(static fn (array $m): array => [
            'id' => \sprintf('plughw:CARD=%s,DEV=%s', $m[1], $m[3]),
            'name' => \sprintf('%s · %s', $m[2], $m[4]),
        ], $matches);
    }
}
