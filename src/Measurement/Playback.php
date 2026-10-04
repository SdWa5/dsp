<?php

namespace App\Measurement;

use App\Bridge\BridgeClient;
use App\Bridge\BridgeException;
use App\Protocol\Frame;

/**
 * Plays the device's test tone for one recording through a running bridge, then puts the device back.
 *
 * A run saves what puts the device back to a restore file next to the bridge socket, pauses the gain riders,
 * which would otherwise ride the inputs on the tone, writes the setup, starts the tone and waits until the
 * bridge has sent every write to the device, since the bridge answers a write as soon as it is queued. Then it
 * lets the tone settle and records. Afterwards it switches the tone back, writes the restore, waits until the
 * device has it and starts the riders again, whatever went wrong. A restore that finds the device gone waits
 * for the bridge to reconnect and sends the frames again, which is safe because they carry absolute values.
 * Only a clean restore deletes the restore file. After a crash, sdwa5-dsp:measure --restore replays it, and no
 * measurement starts while it is there, since its snapshot would take the unrestored device for the original.
 *
 * Errors: a failure before or during the recording is rethrown after the restore. A failed restore is thrown
 * on its own, or together with the first failure when both went wrong.
 *
 * An Interrupt set by a signal handler stops a run before the recording starts. The settle and the wait for
 * the writes check it every 0.1 s, and a recording that ends after it is thrown away. The restore ignores it.
 *
 * A lock next to the bridge socket keeps the measurements on one bridge apart: the auto EQ page,
 * sdwa5-dsp:autoeq and sdwa5-dsp:measure.
 */
final class Playback
{
    private const SLICE = 0.1;

    public function __construct(
        private readonly BridgeClient $bridge,
        private readonly float $drainTimeout = 15.0,
        private readonly float $reconnectTimeout = 30.0,
        private readonly ?Interrupt $interrupt = null,
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
            $file = $this->restorePath();
            if (file_exists($file)) {
                throw new BridgeException(\sprintf('A measurement before this one did not put the device back. Run bin/console sdwa5-dsp:measure --restore to replay %s, or delete it to keep the device as it is.', $file), 409);
            }
            $this->checkInterrupt();
            $status = $this->bridge->request('status');
            $riders = \is_array($status) && \is_array($status['riders'] ?? null) ? array_values(array_filter($status['riders'], 'is_array')) : [];
            $frames = array_map(self::hex(...), [$original->frame(), ...$restore]);
            $this->save($file, $frames, $riders);

            return self::guarded(
                function () use ($riders, $setup, $tone, $settle, $record): mixed {
                    foreach ($riders as $rider) {
                        $this->bridge->request('unride', ['channel' => $rider['channel'] ?? -1]);
                    }
                    $this->checkInterrupt();
                    $this->write(array_map(self::hex(...), [...$setup, $tone->frame()]));
                    $this->drain(true);
                    $this->settle($settle);
                    $recording = $record();
                    // arecord gets the signal too and may end early without an error
                    $this->checkInterrupt('Interrupted during the recording.');

                    return $recording;
                },
                fn () => $this->putBack($file, $frames, $riders),
            );
        } finally {
            flock($lock, \LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Writes frames from inside the recording closure of a run, waits until the device has them and lets the
     * signal settle, for several recordings in one run. The run's restore has to put back what they change.
     *
     * @param list<Frame> $frames
     */
    public function apply(array $frames, float $settle): void
    {
        $this->checkInterrupt();
        $this->write(array_map(self::hex(...), $frames));
        $this->drain(true);
        $this->settle($settle);
    }

    /**
     * Replays the restore file a crashed run left behind and deletes it. Returns what it replayed, or null when
     * there was nothing to restore.
     *
     * @return array{time: string, frames: list<string>, riders: list<array<mixed>>}|null
     */
    public function recover(): ?array
    {
        $lock = $this->lock();
        try {
            $file = $this->restorePath();
            if (!file_exists($file)) {
                return null;
            }
            $saved = self::load($file);
            $this->putBack($file, $saved['frames'], $saved['riders']);

            return $saved;
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
        return self::base($socket).'.measure.lock';
    }

    /**
     * The restore file of a bridge socket: var/bridge.sock keeps it in var/bridge.measure.restore.json.
     */
    public static function restoreFile(string $socket): string
    {
        return self::base($socket).'.measure.restore.json';
    }

    /**
     * A frame as the bridge's write op takes it: command byte and data in hex.
     */
    public static function hex(Frame $frame): string
    {
        return str_replace(' ', '', $frame->shortHex());
    }

    private static function base(string $socket): string
    {
        return preg_replace('/\.sock$/', '', $socket) ?? $socket;
    }

    private function restorePath(): string
    {
        return self::restoreFile($this->bridge->socketPath());
    }

    /**
     * Runs $body, then $cleanup whatever happened. Throws the body's failure, the cleanup's, or one naming both.
     *
     * @template T
     *
     * @param \Closure(): T $body
     *
     * @return T
     */
    private static function guarded(\Closure $body, \Closure $cleanup): mixed
    {
        try {
            $result = $body();
        } catch (\Throwable $failure) {
            try {
                $cleanup();
            } catch (BridgeException $e) {
                throw new BridgeException(\sprintf('%s %s', $failure->getMessage(), $e->getMessage()), (int) $e->getCode(), $failure);
            }

            throw $failure;
        }
        $cleanup();

        return $result;
    }

    /**
     * Writes the restore until the device has it, starts the riders again and deletes the restore file. The
     * riders start even when the device stays gone, since they run in the bridge.
     *
     * @param list<string>       $frames
     * @param list<array<mixed>> $riders
     */
    private function putBack(string $file, array $frames, array $riders): void
    {
        $first = null;
        try {
            $this->resend($frames);
        } catch (BridgeException $e) {
            $first = $e;
        }
        foreach ($riders as $rider) {
            try {
                $this->bridge->request('ride', ['channel' => $rider['channel'] ?? -1, 'settings' => $rider]);
            } catch (BridgeException $e) {
                $first ??= $e;
            }
        }
        if (null !== $first) {
            throw new BridgeException(\sprintf('The device was not put back: %s Once it is connected again, run bin/console sdwa5-dsp:measure --restore to replay %s.', $first->getMessage(), $file), (int) $first->getCode(), $first);
        }
        @unlink($file);
    }

    /**
     * Writes frames until the device has them. A lost device or a stuck queue gets the frames again once the
     * bridge reports the device connected, until the reconnect timeout runs out.
     *
     * @param list<string> $frames
     */
    private function resend(array $frames): void
    {
        $deadline = microtime(true) + $this->reconnectTimeout;
        while (true) {
            try {
                $this->write($frames);
                $this->drain(false);

                return;
            } catch (BridgeException $e) {
                if (!\in_array($e->getCode(), [409, 504], true) || microtime(true) >= $deadline) {
                    throw $e;
                }
                $this->awaitConnection($deadline);
            }
        }
    }

    /**
     * Waits until the bridge reports the device connected, or the deadline passes.
     */
    private function awaitConnection(float $deadline): void
    {
        while (microtime(true) < $deadline) {
            try {
                $status = $this->bridge->request('status');
                if (\is_array($status) && true === ($status['connected'] ?? null)) {
                    return;
                }
            } catch (BridgeException $e) {
                if (503 === $e->getCode()) {
                    throw $e;
                }
            }
            usleep((int) (1e6 * min(0.25, max(0.0, $deadline - microtime(true)))));
        }
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
    private function drain(bool $interruptible): void
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
            if ($interruptible) {
                $this->checkInterrupt();
            }
            usleep(50_000);
        }
    }

    /**
     * Sleeps in slices, so that an interrupt ends the settle at once instead of starting the recording early.
     */
    private function settle(float $seconds): void
    {
        $end = microtime(true) + max(0.0, $seconds);
        while (($left = $end - microtime(true)) > 0) {
            $this->checkInterrupt();
            usleep((int) (1e6 * min(self::SLICE, $left)));
        }
        $this->checkInterrupt();
    }

    private function checkInterrupt(string $message = 'Interrupted before the recording.'): void
    {
        if ($this->interrupt?->triggered()) {
            throw new Interrupted($message);
        }
    }

    /**
     * @param list<string> $frames
     */
    private function write(array $frames): void
    {
        if ([] !== $frames) {
            $this->bridge->request('write', ['frames' => $frames]);
        }
    }

    /**
     * @param list<string>       $frames
     * @param list<array<mixed>> $riders
     */
    private function save(string $file, array $frames, array $riders): void
    {
        $json = json_encode(['time' => date(\DATE_ATOM), 'frames' => $frames, 'riders' => $riders], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
        $temporary = $file.'.tmp';
        if (false === @file_put_contents($temporary, $json) || !@rename($temporary, $file)) {
            @unlink($temporary);

            throw new \RuntimeException(\sprintf('Cannot save the restore to %s, so the measurement does not start.', $file));
        }
    }

    /**
     * @return array{time: string, frames: list<string>, riders: list<array<mixed>>}
     */
    private static function load(string $file): array
    {
        $saved = json_decode((string) @file_get_contents($file), true);
        if (!\is_array($saved) || !\is_array($saved['frames'] ?? null) || !\is_array($saved['riders'] ?? null)) {
            throw new \RuntimeException(\sprintf('%s is no restore file of sdwa5-dsp:measure.', $file));
        }
        $frames = array_values(array_filter($saved['frames'], static fn (mixed $hex): bool => \is_string($hex) && 1 === preg_match('/^(?:[0-9a-fA-F]{2})+$/', $hex)));
        if (\count($frames) !== \count($saved['frames'])) {
            throw new \RuntimeException(\sprintf('%s holds a frame that is not hex.', $file));
        }

        return [
            'time' => \is_string($saved['time'] ?? null) ? $saved['time'] : '',
            'frames' => $frames,
            'riders' => array_values(array_filter($saved['riders'], 'is_array')),
        ];
    }
}
