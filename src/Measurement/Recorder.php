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
     * @param string $device   ALSA device, e.g. "plughw:CARD=UMC1820" or "default"
     * @param int    $channels channels to capture, at least the mic's input number
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
        $process = new Process([
            'arecord', '-q', '-D', $this->device, '-c', (string) $this->channels, '-r', (string) self::RATE,
            '-f', 'S32_LE', '-t', 'wav', '-d', (string) max(1, $seconds), $path,
        ]);
        $process->setTimeout($seconds + 30);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new \RuntimeException(\sprintf('arecord failed on "%s": %s', $this->device, trim($process->getErrorOutput()) ?: 'exit code '.$process->getExitCode()));
        }

        return $path;
    }
}
