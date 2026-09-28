<?php

namespace App\Measurement;

use Symfony\Component\Process\Process;

/**
 * Records a WAV file with ALSA's arecord, e.g. from a Behringer UMC1820 with the measurement mic on
 * one of its inputs. plughw converts the sample format, so any class-compliant interface works.
 */
class Recorder
{
    public const RATE = 48000;

    /**
     * @param string $device   ALSA device, e.g. "plughw:CARD=UMC1820,DEV=0" or "default"
     * @param int    $channels channels to capture, at least the mic's input number. arecord captures two at least
     */
    public function __construct(
        private readonly string $device = 'default',
        private readonly int $channels = 1,
    ) {
        if ($channels < 1) {
            throw new \InvalidArgumentException('Record at least one channel.');
        }
    }

    /**
     * Records $seconds into $path and returns $path.
     */
    public function record(int $seconds, string $path): string
    {
        $process = new Process($this->command($seconds, $path));
        $process->setTimeout($seconds + 30);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new \RuntimeException(\sprintf('arecord failed on "%s": %s', $this->device, trim($process->getErrorOutput()) ?: 'exit code '.$process->getExitCode()));
        }

        return $path;
    }

    /**
     * The arecord call. It captures two channels at least, because plughw averages every input of a
     * multichannel interface into a single requested channel (a UMC1820 mixes its ten inputs at -20 dB each),
     * while from two channels up it copies them one to one.
     *
     * @return list<string>
     */
    public function command(int $seconds, string $path): array
    {
        return [
            'arecord', '-q', '-D', $this->device, '-c', (string) max(2, $this->channels), '-r', (string) self::RATE,
            '-f', 'S32_LE', '-t', 'wav', '-d', (string) max(1, $seconds), $path,
        ];
    }
}
