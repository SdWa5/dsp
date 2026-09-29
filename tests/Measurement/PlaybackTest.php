<?php

namespace App\Tests\Measurement;

use App\Bridge\BridgeClient;
use App\Bridge\BridgeException;
use App\Measurement\Interrupt;
use App\Measurement\Interrupted;
use App\Measurement\Playback;
use App\Measurement\Solo;
use App\Measurement\TestTone;
use App\Protocol\Channel;
use App\Protocol\Commands;
use App\Protocol\Scale;
use App\Tests\Support\BridgeProcess;
use App\Tests\Support\ScriptedBridge;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * Playback against the bridge on the simulated device, and against a scripted bridge for a device that is slow
 * to take the writes, rejects them or is gone.
 */
final class PlaybackTest extends TestCase
{
    private ?BridgeProcess $bridge = null;
    private ?ScriptedBridge $scripted = null;

    protected function tearDown(): void
    {
        $this->bridge?->stop();
        $this->scripted?->stop();
    }

    private function demo(): BridgeClient
    {
        $this->bridge = new BridgeProcess();

        return new BridgeClient($this->bridge->socket);
    }

    /**
     * @param list<array<string, mixed>> $statuses
     * @param list<string|null>          $writes
     */
    private function scripted(array $statuses, array $writes = []): ScriptedBridge
    {
        return $this->scripted = new ScriptedBridge($statuses, $writes);
    }

    /**
     * The frames the restore of the scripted tests writes: the tone back to Analog Input, then Out8 unmuted.
     *
     * @return list<string>
     */
    private static function restoreFrames(): array
    {
        return [Playback::hex(Commands::testTone(TestTone::ANALOG, 0)), Playback::hex(Commands::mute(8, false))];
    }

    /**
     * Runs with Out8 muted for the recording, the setup of most scripted tests.
     *
     * @param \Closure(): mixed $record
     */
    private static function mutingOut8(Playback $playback, \Closure $record, float $settle = 0.0): mixed
    {
        return $playback->run([Commands::mute(8, true)], new TestTone(TestTone::PINK), new TestTone(TestTone::ANALOG), [Commands::mute(8, false)], $settle, $record);
    }

    /**
     * @param list<array<mixed>> $riders
     */
    private static function saveRestore(ScriptedBridge $bridge, array $riders = []): string
    {
        $file = Playback::restoreFile($bridge->socket);
        file_put_contents($file, json_encode(['time' => '2026-09-29T20:00:00+00:00', 'frames' => self::restoreFrames(), 'riders' => $riders], \JSON_THROW_ON_ERROR));

        return $file;
    }

    /**
     * @return array<mixed>
     */
    private static function state(BridgeClient $client): array
    {
        $state = $client->request('state');
        self::assertIsArray($state);

        return $state;
    }

    /**
     * @param array<mixed> $state
     *
     * @return array<mixed>
     */
    private static function out(array $state, int $number): array
    {
        self::assertIsArray($state['outputs'][$number - 1] ?? null);

        return $state['outputs'][$number - 1];
    }

    /**
     * A recording that must not start. It is typed as returning, so that PHPStan does not read run() as never
     * returning and the checks after it as unreachable.
     *
     * @return \Closure(): mixed
     */
    private static function refused(string $why): \Closure
    {
        return static fn () => self::fail($why);
    }

    /**
     * @return \Closure(): mixed
     */
    private static function failing(string $message): \Closure
    {
        return static fn () => throw new \RuntimeException($message);
    }

    public function testSolosDuringTheRecordingAndRestoresAfterwards(): void
    {
        $client = $this->demo();
        $before = self::state($client);
        $solo = Solo::plan($before, [Channel::output(3)], -20.0, Channel::output(8));

        // run() returns what the recording returns
        $during = (new Playback($client))->run($solo->setup, new TestTone(TestTone::PINK, 17), $solo->original, $solo->restore, 0.0, static fn (): array => self::state($client));

        self::assertSame(TestTone::PINK, $during['tone_mode']);
        // Out3 at +5 dB plays 20 dB lower, the reference Out8 unmuted as it is, the rest muted
        self::assertSame(Scale::dbToGain(-15.0), self::out($during, 3)['gain']);
        self::assertFalse(self::out($during, 3)['mute']);
        self::assertFalse(self::out($during, 8)['mute']);
        self::assertSame(self::out($before, 8)['gain'], self::out($during, 8)['gain']);
        foreach ([1, 2, 4, 5, 6, 7] as $number) {
            self::assertTrue(self::out($during, $number)['mute'], 'Out'.$number);
        }
        self::assertSame($before, self::state($client));
    }

    public function testAFailedRecordingRestoresAndRethrows(): void
    {
        $client = $this->demo();
        $before = self::state($client);
        $solo = Solo::plan($before, [Channel::output(7)], -10.0, null);

        try {
            (new Playback($client))->run($solo->setup, new TestTone(TestTone::SINE, 7), $solo->original, $solo->restore, 0.0, self::failing('arecord failed'));
            self::fail('The recording error was swallowed.');
        } catch (\RuntimeException $e) {
            self::assertSame('arecord failed', $e->getMessage());
        }

        self::assertSame($before, self::state($client));
    }

    public function testPausesTheRidersAndStartsThemAgain(): void
    {
        $client = $this->demo();
        $client->request('ride', ['channel' => 1, 'settings' => ['target' => -24]]);
        $riders = static function () use ($client): array {
            $status = $client->request('status');
            self::assertIsArray($status);
            self::assertIsArray($status['riders']);

            return array_column($status['riders'], 'target', 'channel');
        };
        $during = null;

        (new Playback($client))->run([], new TestTone(TestTone::PINK), new TestTone(TestTone::ANALOG), [], 0.0, static function () use ($riders, &$during): void {
            $during = $riders();
        });

        self::assertSame([], $during);
        self::assertEquals([1 => -24], $riders());
    }

    public function testWaitsUntilTheDeviceHasTheWrites(): void
    {
        // The rider check, then the queue drains over three polls
        $bridge = $this->scripted([[], ['queued' => 3], ['queued' => 1], ['queued' => 0]]);
        $seen = null;

        self::mutingOut8(new Playback($bridge->client()), static function () use ($bridge, &$seen): void {
            $seen = $bridge->ops();
        });

        self::assertSame(['status', 'write', 'status', 'status', 'status'], $seen);
        $log = $bridge->log();
        self::assertSame([Playback::hex(Commands::mute(8, true)), Playback::hex(Commands::testTone(TestTone::PINK, 0))], $log[1]['frames']);
        self::assertSame(self::restoreFrames(), $log[5]['frames']);
        self::assertSame('status', $log[6]['op']);
    }

    public function testRestoresWhenTheWritesDoNotGetThrough(): void
    {
        $bridge = $this->scripted([[], ['queued' => 2]]);

        try {
            self::mutingOut8(new Playback($bridge->client(), 0.2, 0.3), self::refused('Recorded without the setup on the device.'));
            self::fail('Expected a timeout.');
        } catch (BridgeException $e) {
            self::assertSame(504, $e->getCode());
            self::assertStringStartsWith('The device did not take the writes within 0.2 s. The device was not put back: The device did not take', $e->getMessage());
            self::assertStringContainsString('trackdsp:measure --restore', $e->getMessage());
        }

        // Every write after the setup is the restore, sent again while the queue does not drain
        $writes = array_values(array_filter($bridge->log(), static fn (array $request): bool => 'write' === $request['op']));
        self::assertGreaterThan(2, \count($writes));
        foreach (\array_slice($writes, 1) as $write) {
            self::assertSame(self::restoreFrames(), $write['frames']);
        }
        self::assertFileExists(Playback::restoreFile($bridge->socket));
    }

    public function testRestoresWhenTheBridgeRejectsTheSetup(): void
    {
        $bridge = $this->scripted([[]], ['busy']);

        try {
            self::mutingOut8(new Playback($bridge->client()), self::refused('Recorded without the setup.'));
            self::fail('Expected the rejection.');
        } catch (BridgeException $e) {
            self::assertSame(400, $e->getCode());
            self::assertSame('Scripted busy.', $e->getMessage());
        }

        self::assertSame(['status', 'write', 'write', 'status'], $bridge->ops());
        self::assertSame(self::restoreFrames(), $bridge->log()[2]['frames']);
        self::assertFileDoesNotExist(Playback::restoreFile($bridge->socket));
    }

    public function testStopsWhenTheDeviceIsGone(): void
    {
        $bridge = $this->scripted([[], ['connected' => false]]);

        try {
            (new Playback($bridge->client(), 15.0, 0.3))->run([], new TestTone(TestTone::PINK), new TestTone(TestTone::ANALOG), [], 0.0, self::refused('Recorded without a device.'));
            self::fail('Expected an error.');
        } catch (BridgeException $e) {
            self::assertSame(409, $e->getCode());
            self::assertStringStartsWith('The device is not connected. The device was not put back: ', $e->getMessage());
        }

        // The restore waits for the device, which does not come back
        self::assertSame(['status', 'write', 'status', 'write', 'status', 'status'], \array_slice($bridge->ops(), 0, 6));
        self::assertFileExists(Playback::restoreFile($bridge->socket));
    }

    public function testPutsBackOnceTheDeviceIsThereAgain(): void
    {
        // The device drops during the recording, the bridge refuses the restore and reconnects a poll later
        $bridge = $this->scripted([[], [], ['connected' => false], []], [null, 'offline']);

        $result = self::mutingOut8(new Playback($bridge->client()), static fn (): string => 'recorded');

        self::assertSame('recorded', $result);
        self::assertSame(['status', 'write', 'status', 'write', 'status', 'status', 'write', 'status'], $bridge->ops());
        self::assertSame(self::restoreFrames(), $bridge->log()[6]['frames']);
        self::assertFileDoesNotExist(Playback::restoreFile($bridge->socket));
    }

    public function testKeepsTheRestoreInAFileWhileItRuns(): void
    {
        $riders = [['channel' => 1, 'target' => -24]];
        $bridge = $this->scripted([['riders' => $riders], ['riders' => []]]);
        $file = Playback::restoreFile($bridge->socket);
        $saved = null;

        self::mutingOut8(new Playback($bridge->client()), static function () use ($file, &$saved): void {
            $saved = json_decode((string) file_get_contents($file), true);
        });

        self::assertIsArray($saved);
        self::assertSame(self::restoreFrames(), $saved['frames']);
        self::assertSame($riders, $saved['riders']);
        self::assertFileDoesNotExist($file);
        self::assertSame(['status', 'unride', 'write', 'status', 'write', 'status', 'ride'], $bridge->ops());
        self::assertSame(['op' => 'ride', 'channel' => 1, 'settings' => $riders[0]], array_diff_key($bridge->log()[6], ['id' => true]));
    }

    public function testRefusesWhileARestoreFileIsThere(): void
    {
        $bridge = $this->scripted([[]]);
        $file = self::saveRestore($bridge);

        try {
            self::mutingOut8(new Playback($bridge->client()), self::refused('Measured on a device that was not put back.'));
            self::fail('Expected a conflict.');
        } catch (BridgeException $e) {
            self::assertSame(409, $e->getCode());
            self::assertStringContainsString('trackdsp:measure --restore', $e->getMessage());
        }

        self::assertSame([], $bridge->ops());
        self::assertFileExists($file);
    }

    public function testDoesNotStartWithoutTheRestoreFile(): void
    {
        $bridge = $this->scripted([[]]);
        // A directory where the temporary file would go makes the save fail
        $blocker = Playback::restoreFile($bridge->socket).'.tmp';
        mkdir($blocker);

        try {
            self::mutingOut8(new Playback($bridge->client()), self::refused('Measured without a restore file.'));
            self::fail('Expected the save to fail.');
        } catch (\RuntimeException $e) {
            self::assertStringStartsWith('Cannot save the restore', $e->getMessage());
        } finally {
            rmdir($blocker);
        }

        self::assertSame(['status'], $bridge->ops());
    }

    public function testRecoverReplaysTheRestoreFile(): void
    {
        $riders = [['channel' => 1, 'target' => -24]];
        $bridge = $this->scripted([[]]);
        $file = self::saveRestore($bridge, $riders);
        $playback = new Playback($bridge->client());

        self::assertSame(['time' => '2026-09-29T20:00:00+00:00', 'frames' => self::restoreFrames(), 'riders' => $riders], $playback->recover());

        self::assertSame(['write', 'status', 'ride'], $bridge->ops());
        self::assertSame(self::restoreFrames(), $bridge->log()[0]['frames']);
        self::assertFileDoesNotExist($file);
        self::assertNull($playback->recover());
    }

    public function testRecoverKeepsAFileItCannotRead(): void
    {
        $bridge = $this->scripted([[]]);
        $file = Playback::restoreFile($bridge->socket);
        file_put_contents($file, '{"frames": ["zz"], "riders": []}');

        try {
            (new Playback($bridge->client()))->recover();
            self::fail('Expected the file to be refused.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('not hex', $e->getMessage());
        }

        self::assertSame([], $bridge->ops());
        self::assertFileExists($file);
    }

    public function testNamesBothFailures(): void
    {
        $bridge = $this->scripted([[]], [null, 'busy']);

        try {
            self::mutingOut8(new Playback($bridge->client()), self::failing('arecord failed'));
            self::fail('Expected an error.');
        } catch (BridgeException $e) {
            self::assertSame(400, $e->getCode());
            self::assertStringStartsWith('arecord failed The device was not put back: Scripted busy. ', $e->getMessage());
            self::assertInstanceOf(\RuntimeException::class, $e->getPrevious());
            self::assertSame('arecord failed', $e->getPrevious()->getMessage());
        }

        self::assertFileExists(Playback::restoreFile($bridge->socket));
    }

    public function testAnInterruptBeforeTheRunLeavesTheDeviceAlone(): void
    {
        $bridge = $this->scripted([[]]);
        $interrupt = new Interrupt();
        $interrupt->trigger();

        $this->expectException(Interrupted::class);
        try {
            self::mutingOut8(new Playback($bridge->client(), interrupt: $interrupt), self::refused('Recorded after Ctrl+C.'));
        } finally {
            self::assertSame([], $bridge->ops());
            self::assertFileDoesNotExist(Playback::restoreFile($bridge->socket));
        }
    }

    #[RequiresPhpExtension('pcntl')]
    public function testAnInterruptEndsTheSettleAndPutsTheDeviceBack(): void
    {
        $bridge = $this->scripted([[]]);
        $interrupt = new Interrupt();
        $async = pcntl_async_signals(true);
        pcntl_signal(\SIGALRM, static fn () => $interrupt->trigger());
        pcntl_alarm(1);
        $start = microtime(true);

        try {
            self::mutingOut8(new Playback($bridge->client(), interrupt: $interrupt), self::refused('Recorded after Ctrl+C.'), 30.0);
            self::fail('Expected the interrupt.');
        } catch (Interrupted $e) {
            self::assertSame('Interrupted before the recording.', $e->getMessage());
        } finally {
            pcntl_alarm(0);
            pcntl_signal(\SIGALRM, \SIG_DFL);
            pcntl_async_signals($async);
        }

        self::assertLessThan(5.0, microtime(true) - $start);
        self::assertSame(['status', 'write', 'status', 'write', 'status'], $bridge->ops());
        self::assertSame(self::restoreFrames(), $bridge->log()[3]['frames']);
        self::assertFileDoesNotExist(Playback::restoreFile($bridge->socket));
    }

    public function testAnInterruptDuringTheRecordingDropsIt(): void
    {
        $bridge = $this->scripted([[]]);
        $interrupt = new Interrupt();

        try {
            self::mutingOut8(new Playback($bridge->client(), interrupt: $interrupt), static function () use ($interrupt): string {
                $interrupt->trigger();

                return 'cut short';
            });
            self::fail('Expected the interrupt.');
        } catch (Interrupted $e) {
            self::assertSame('Interrupted during the recording.', $e->getMessage());
        }

        self::assertSame(self::restoreFrames(), $bridge->log()[3]['frames']);
    }

    public function testOnlyOneMeasurementRunsOnABridge(): void
    {
        $bridge = $this->scripted([[]]);
        $lock = fopen(Playback::lockPath($bridge->socket), 'c');
        self::assertNotFalse($lock);
        flock($lock, \LOCK_EX);

        try {
            (new Playback($bridge->client()))->run([], new TestTone(TestTone::PINK), new TestTone(TestTone::ANALOG), [], 0.0, self::refused('Recorded next to another measurement.'));
            self::fail('Expected a conflict.');
        } catch (BridgeException $e) {
            self::assertSame(409, $e->getCode());
            self::assertStringContainsString('Another measurement', $e->getMessage());
        } finally {
            flock($lock, \LOCK_UN);
            fclose($lock);
        }

        self::assertSame([], $bridge->ops());
        self::assertSame('/run/x/bridge.measure.lock', Playback::lockPath('/run/x/bridge.sock'));
        self::assertSame('/run/x/bridge.measure.restore.json', Playback::restoreFile('/run/x/bridge.sock'));
    }
}
