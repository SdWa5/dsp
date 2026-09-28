<?php

namespace App\Measurement;

use App\Bridge\BridgeClient;
use App\Bridge\BridgeException;
use App\Protocol\Frame;

/**
 * Plays the device's test tone for one recording through a running bridge, then puts the device back.
 *
 * A run pauses the gain riders, which would otherwise ride the inputs on the tone, writes the setup, starts
 * the tone and waits until the bridge has sent every write to the device, since the bridge answers a write
 * as soon as it is queued. Then it lets the tone settle and records. Afterwards it switches the tone back,
 * writes the restore and starts the riders again, whatever went wrong, and rethrows the first failure.
 *
 * A lock next to the bridge socket keeps the measurements on one bridge apart: the auto EQ page,
 * trackdsp:autoeq and trackdsp:measure.
 */
final class Playback
{
    public function __construct(
        private readonly BridgeClient $bridge,
        private readonly float $drainTimeout = 15.0,
    ) {
    }

    /**
     * @template T
     *
     * @param list<Frame>   $setup    written before the tone
     * @param TestTone      $tone     played for the recording
     * @param TestTone      $original switched back to afterwards
     * @param list<Frame>   $restore  written after the tone is back
     * @param \Closure(): T $record
     *
     * @return T
     */
    public function run(array $setup, TestTone $tone, TestTone $original, array $restore, float $settle, \Closure $record): mixed
    {
        $lock = $this->lock();
        try {
            $riders = $this->pauseRiders();
            try {
                $this->write($setup);
                try {
                    $this->write([$tone->frame()]);
                    $this->drain();
                    usleep((int) (1e6 * max(0.0, $settle)));

                    return $record();
                } finally {
                    $this->restore([
                        fn () => $this->write([$original->frame()]),
                        fn () => $this->write($restore),
                    ]);
                }
            } finally {
                $this->resumeRiders($riders);
            }
        } finally {
            flock($lock, \LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * The lock file of a bridge socket, next to its riders file: var/bridge.sock locks var/bridge.measure.lock.
     */
    public static function lockPath(string $socket): string
    {
        return (preg_replace('/\.sock$/', '', $socket) ?? $socket).'.measure.lock';
    }

    /**
     * A frame as the bridge's write op takes it: command byte and data in hex.
     */
    public static function hex(Frame $frame): string
    {
        return str_replace(' ', '', $frame->shortHex());
    }

    /**
     * @return resource
     */
    private function lock()
    {
        $path = self::lockPath($this->bridge->socketPath());
        $lock = @fopen($path, 'c');
        if (false === $lock) {
            throw new BridgeException(\sprintf('Cannot open the measurement lock %s. Is the bridge running?', $path), 503);
        }
        if (!flock($lock, \LOCK_EX | \LOCK_NB)) {
            fclose($lock);

            throw new BridgeException('Another measurement is running on this bridge.', 409);
        }

        return $lock;
    }

    /**
     * Waits until the bridge has sent every queued write to the device.
     */
    private function drain(): void
    {
        $deadline = microtime(true) + $this->drainTimeout;
        while (true) {
            $status = $this->bridge->request('status');
            if (!\is_array($status) || true !== ($status['connected'] ?? null)) {
                throw new BridgeException('The device is not connected.', 409);
            }
            if (0 === ($status['queued'] ?? null)) {
                return;
            }
            if (microtime(true) >= $deadline) {
                throw new BridgeException(\sprintf('The device did not take the writes within %s s.', $this->drainTimeout), 504);
            }
            usleep(50_000);
        }
    }

    /**
     * @param list<Frame> $frames
     */
    private function write(array $frames): void
    {
        if ([] !== $frames) {
            $this->bridge->request('write', ['frames' => array_map(self::hex(...), $frames)]);
        }
    }

    /**
     * Stops the gain riders and returns their settings.
     *
     * @return list<array<mixed>>
     */
    private function pauseRiders(): array
    {
        $status = $this->bridge->request('status');
        $riders = \is_array($status) && \is_array($status['riders'] ?? null) ? array_values(array_filter($status['riders'], 'is_array')) : [];
        foreach ($riders as $rider) {
            $this->bridge->request('unride', ['channel' => $rider['channel'] ?? -1]);
        }

        return $riders;
    }

    /**
     * @param list<array<mixed>> $riders
     */
    private function resumeRiders(array $riders): void
    {
        $this->restore(array_map(fn (array $rider): \Closure => fn () => $this->bridge->request('ride', ['channel' => $rider['channel'] ?? -1, 'settings' => $rider]), $riders));
    }

    /**
     * Runs every step even when one fails, then throws the first failure.
     *
     * @param list<\Closure(): mixed> $steps
     */
    private function restore(array $steps): void
    {
        $first = null;
        foreach ($steps as $step) {
            try {
                $step();
            } catch (BridgeException $e) {
                $first ??= $e;
            }
        }
        if (null !== $first) {
            throw $first;
        }
    }
}
