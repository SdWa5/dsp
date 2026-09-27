<?php

namespace App\Measurement;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Creates recorders and lists the ALSA capture devices. The tests replace it with a fake that writes a
 * synthetic recording.
 */
class RecorderFactory
{
    public function create(string $device, int $channels): Recorder
    {
        return new Recorder($device, $channels);
    }

    public function available(): bool
    {
        return null !== (new ExecutableFinder())->find('arecord');
    }

    /**
     * The capture devices from "arecord -l" as plughw names, "default" first. Empty when arecord is missing.
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

        return [['id' => 'default', 'name' => 'Default device'], ...self::parse($process->getOutput())];
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
