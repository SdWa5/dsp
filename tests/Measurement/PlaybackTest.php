<?php

namespace App\Tests\Measurement;

use App\Bridge\BridgeClient;
use App\Bridge\BridgeException;
use App\Measurement\Playback;
use App\Measurement\Solo;
use App\Measurement\TestTone;
use App\Protocol\Channel;
use App\Protocol\Commands;
use App\Protocol\Scale;
use App\Tests\Support\BridgeProcess;
use App\Tests\Support\ScriptedBridge;
use PHPUnit\Framework\TestCase;

/**
 * Playback against the bridge on the simulated device, and against a scripted bridge for a device that is slow
 * to take the writes or gone.
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
     */
    private function scripted(array $statuses): ScriptedBridge
    {
        return $this->scripted = new ScriptedBridge($statuses);
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

        (new Playback($bridge->client()))->run([Commands::mute(8, true)], new TestTone(TestTone::PINK), new TestTone(TestTone::ANALOG), [Commands::mute(8, false)], 0.0, static function () use ($bridge, &$seen): void {
            $seen = $bridge->ops();
        });

        self::assertSame(['status', 'write', 'write', 'status', 'status', 'status'], $seen);
        $log = $bridge->log();
        self::assertSame([Playback::hex(Commands::testTone(TestTone::ANALOG, 0))], $log[6]['frames']);
        self::assertSame([Playback::hex(Commands::mute(8, false))], $log[7]['frames']);
    }

    public function testRestoresWhenTheWritesDoNotGetThrough(): void
    {
        $bridge = $this->scripted([[], ['queued' => 2]]);

        try {
            (new Playback($bridge->client(), 0.2))->run([Commands::mute(8, true)], new TestTone(TestTone::PINK), new TestTone(TestTone::ANALOG), [Commands::mute(8, false)], 0.0, self::refused('Recorded without the setup on the device.'));
            self::fail('Expected a timeout.');
        } catch (BridgeException $e) {
            self::assertSame(504, $e->getCode());
        }

        $log = $bridge->log();
        self::assertSame([Playback::hex(Commands::mute(8, false))], end($log)['frames'] ?? null);
    }

    public function testStopsWhenTheDeviceIsGone(): void
    {
        $bridge = $this->scripted([[], ['connected' => false]]);

        try {
            (new Playback($bridge->client()))->run([], new TestTone(TestTone::PINK), new TestTone(TestTone::ANALOG), [], 0.0, self::refused('Recorded without a device.'));
            self::fail('Expected an error.');
        } catch (BridgeException $e) {
            self::assertSame(409, $e->getCode());
        }

        self::assertSame(['status', 'write', 'status', 'write'], $bridge->ops());
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
    }
}
