<?php

namespace App\Tests\Bridge;

use App\Bridge\Bridge;
use App\Bridge\BridgeException;
use App\Protocol\Commands;
use App\Protocol\Frame;
use App\Transport\HidrawLocator;
use App\Transport\TransportFactory;
use PHPUnit\Framework\TestCase;

/**
 * The bridge loop in the test process against the simulated device, driven by tick().
 */
final class BridgeTest extends TestCase
{
    private const DEMO = __DIR__.'/../../resources/demo_config.bin';

    private string $socket;
    private Bridge $bridge;
    /** @var resource */
    private $client;
    private string $buffer = '';
    private int $id = 0;

    protected function setUp(): void
    {
        $this->socket = sys_get_temp_dir().'/trackdsp-test-'.bin2hex(random_bytes(4)).'.sock';
        $this->bridge = $this->bridge('demo');
        $this->client = $this->connectClient();
    }

    protected function tearDown(): void
    {
        @fclose($this->client);
        $this->bridge->close();
    }

    private function bridge(string $target, ?string $ridersFile = null): Bridge
    {
        $bridge = new Bridge(new TransportFactory(new HidrawLocator('/nonexistent'), self::DEMO, 0.01), $this->socket, $target, meterInterval: 0.01, publishInterval: 0.0, ridersFile: $ridersFile);
        $bridge->listen();

        return $bridge;
    }

    /**
     * @return resource
     */
    private function connectClient()
    {
        $client = stream_socket_client('unix://'.$this->socket);
        self::assertNotFalse($client);
        stream_set_blocking($client, false);
        $this->bridge->tick(0.01);

        return $client;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<mixed>
     */
    private function request(string $op, array $params = []): array
    {
        $id = ++$this->id;
        fwrite($this->client, json_encode(['id' => $id, 'op' => $op] + $params)."\n");
        foreach ($this->messages() as $message) {
            if (($message['id'] ?? null) === $id) {
                return $message;
            }
        }
        self::fail('No answer to '.$op);
    }

    /**
     * Runs the loop and yields every line the client receives.
     *
     * @return \Generator<int, array<mixed>>
     */
    private function messages(int $ticks = 50): \Generator
    {
        for ($i = 0; $i < $ticks; ++$i) {
            $this->bridge->tick(0.005);
            $this->buffer .= (string) fread($this->client, 1 << 20);
            while (false !== $pos = strpos($this->buffer, "\n")) {
                $line = substr($this->buffer, 0, $pos);
                $this->buffer = substr($this->buffer, $pos + 1);
                $message = json_decode($line, true);
                self::assertIsArray($message);
                yield $message;
            }
        }
    }

    /**
     * @return array<mixed>
     */
    private function state(): array
    {
        $state = $this->request('state');
        self::assertTrue($state['ok'], (string) json_encode($state));
        self::assertIsArray($state['result']);

        return $state['result'];
    }

    /**
     * Runs the loop until no re-read is left.
     */
    private function settle(): void
    {
        for ($i = 0; $i < 10; ++$i) {
            iterator_to_array($this->messages(60), false);
            if (null === $this->request('status')['result']['resync']) {
                return;
            }
        }
        self::fail('The re-read does not finish.');
    }

    private static function hex(Frame $frame): string
    {
        return bin2hex(\chr($frame->cmd).$frame->data);
    }

    public function testConnectsAndServesStatusAndState(): void
    {
        self::assertSame(['id' => 1, 'ok' => true, 'result' => 'pong'], $this->request('ping'));
        $status = $this->request('status')['result'];
        self::assertIsArray($status);
        self::assertTrue($status['connected']);
        self::assertSame('8x8Matrix V010', $status['info']);

        $state = $this->state();
        self::assertSame('Bulgaria26', $state['preset_name']);
        self::assertSame(2, $state['active_preset']);
        self::assertSame('192.168.68.10', $state['ip']);
    }

    public function testWritesUpdateTheStateAndReachTheDevice(): void
    {
        $frames = array_map(self::hex(...), [Commands::gain(8, 200), Commands::gain(8, 250), Commands::mute(9, true)]);
        $answer = $this->request('write', ['frames' => $frames]);
        self::assertTrue($answer['ok']);
        self::assertSame(['queued' => 2], $answer['result']);

        $state = $this->state();
        self::assertSame(250, $state['outputs'][0]['gain']);
        self::assertTrue($state['outputs'][1]['mute']);
        self::assertSame(0, $this->request('status')['result']['queued']);

        // The device has it too: a re-read keeps the values
        $this->request('refresh');
        $this->settle();
        self::assertSame(250, $this->state()['outputs'][0]['gain']);
    }

    public function testRecallReadsThePresetBack(): void
    {
        $this->request('write', ['frames' => array_map(self::hex(...), [Commands::gain(8, 111), Commands::presetName('Probe'), Commands::storePreset(5), Commands::gain(8, 222)])]);
        iterator_to_array($this->messages(5), false);
        self::assertSame(222, $this->state()['outputs'][0]['gain']);

        $this->request('write', ['frames' => [self::hex(Commands::loadPreset(5))]]);
        $this->settle();
        $state = $this->state();
        self::assertSame(111, $state['outputs'][0]['gain']);
        self::assertSame('Probe', $state['preset_name']);
        self::assertSame(5, $state['active_preset']);
        self::assertSame('Probe', $state['preset_names'][4]);
        self::assertSame([1, 4], array_keys(array_filter($state['preset_stored'])));
    }

    public function testServesRequestsWhileItReadsTheDeviceAgain(): void
    {
        $this->settle();
        $this->request('refresh');
        $progress = [];
        for ($i = 0; $i < 10; ++$i) {
            self::assertSame('pong', $this->request('ping')['result']);
            $progress[] = $this->request('status')['result']['resync'];
        }
        self::assertNotContains(null, $progress);
        self::assertGreaterThan($progress[0], $progress[9]);
        self::assertLessThan(1.0, $progress[9]);

        $this->settle();
        self::assertSame('Bulgaria26', $this->state()['preset_name']);
    }

    public function testWritesDuringAReReadSurviveIt(): void
    {
        $this->settle();
        $this->request('refresh');
        // Wait until the configuration pages are read, so they hold the old gain
        while (($this->request('status')['result']['resync'] ?? 1.0) < 27 / 49) {
            iterator_to_array($this->messages(1), false);
        }
        $this->request('write', ['frames' => [self::hex(Commands::gain(8, 123))]]);
        $this->settle();
        self::assertSame(123, $this->state()['outputs'][0]['gain']);

        $this->request('refresh');
        $this->settle();
        self::assertSame(123, $this->state()['outputs'][0]['gain']);
    }

    public function testARecallDuringAReReadStartsItOver(): void
    {
        $this->request('write', ['frames' => array_map(self::hex(...), [Commands::gain(8, 111), Commands::presetName('Probe'), Commands::storePreset(5), Commands::gain(8, 222)])]);
        $this->settle();
        $this->request('refresh');
        iterator_to_array($this->messages(20), false);
        self::assertGreaterThan(0.2, $this->request('status')['result']['resync']);

        $this->request('write', ['frames' => [self::hex(Commands::loadPreset(5))]]);
        iterator_to_array($this->messages(2), false);
        self::assertLessThan(0.2, $this->request('status')['result']['resync']);
        $this->settle();
        self::assertSame(111, $this->state()['outputs'][0]['gain']);
        self::assertSame(5, $this->state()['active_preset']);
    }

    public function testRejectsBadRequests(): void
    {
        self::assertSame('invalid', $this->request('nope')['code']);
        self::assertSame('invalid', $this->request('write', ['frames' => ['zz']])['code']);
        self::assertSame('invalid', $this->request('write', ['frames' => []])['code']);
        self::assertSame('invalid', $this->request('connect', ['target' => ''])['code']);

        fwrite($this->client, "not json\n");
        $answers = iterator_to_array($this->messages(3), false);
        self::assertSame([['id' => null, 'ok' => false, 'error' => 'Invalid JSON.', 'code' => 'invalid']], $answers);
    }

    public function testSubscribersGetStatusStateAndMeters(): void
    {
        fwrite($this->client, '{"id":7,"op":"subscribe"}'."\n");
        $events = [];
        foreach ($this->messages(30) as $message) {
            if (isset($message['event'])) {
                $events[$message['event']] = $message['data'];
            }
        }
        self::assertArrayHasKey('status', $events);
        self::assertArrayHasKey('state', $events);
        self::assertArrayHasKey('meters', $events);
        self::assertCount(17, $events['meters']['levels']);
        self::assertSame([], $events['meters']['riders']);
    }

    public function testRidersRideTheGainAndSurviveARestart(): void
    {
        $file = sys_get_temp_dir().'/trackdsp-test-'.bin2hex(random_bytes(4)).'.riders.json';
        $this->restart($file);
        try {
            $before = $this->state()['inputs'][0]['gain'];
            $answer = $this->request('ride', ['channel' => 0, 'settings' => ['target' => -80, 'silence' => -90, 'min' => -40, 'slew' => 50, 'average' => 0.05, 'deadband' => 0]]);
            self::assertTrue($answer['ok'], (string) json_encode($answer));
            self::assertSame([0], array_column($answer['result']['riders'], 'channel'));
            self::assertEquals(-80, $answer['result']['riders'][0]['target']);

            // The rider works without any subscriber, the bridge polls the meters for it
            $deadline = microtime(true) + 5;
            while ($this->state()['inputs'][0]['gain'] >= $before && microtime(true) < $deadline) {
                iterator_to_array($this->messages(10), false);
            }
            self::assertLessThan($before, $this->state()['inputs'][0]['gain']);

            fwrite($this->client, '{"id":99,"op":"subscribe"}'."\n");
            $riders = null;
            foreach ($this->messages(30) as $message) {
                if ('meters' === ($message['event'] ?? null)) {
                    $riders = $message['data']['riders'];
                }
            }
            self::assertIsArray($riders);
            self::assertSame(0, $riders[0]['channel']);
            self::assertContains($riders[0]['status'], ['riding', 'limit', 'steady']);

            $this->restart($file);
            self::assertSame([0], array_column($this->request('status')['result']['riders'], 'channel'));

            self::assertSame([], $this->request('unride', ['channel' => 0])['result']['riders']);
            self::assertSame([], json_decode((string) file_get_contents($file), true));
        } finally {
            @unlink($file);
        }
    }

    public function testRejectsBadRiders(): void
    {
        self::assertSame('invalid', $this->request('ride', ['channel' => 8, 'settings' => []])['code']);
        self::assertSame('invalid', $this->request('ride', ['channel' => 0, 'settings' => 'fast'])['code']);
        self::assertSame('invalid', $this->request('ride', ['channel' => 0, 'settings' => ['max' => 6]])['code']);
        self::assertSame('invalid', $this->request('unride', [])['code']);
        self::assertSame([], $this->request('status')['result']['riders']);
    }

    private function restart(?string $ridersFile): void
    {
        @fclose($this->client);
        $this->bridge->close();
        $this->bridge = $this->bridge('demo', $ridersFile);
        $this->client = $this->connectClient();
        $this->buffer = '';
    }

    public function testOfflineDeviceAndReconnect(): void
    {
        $this->request('connect', ['target' => 'usb:/nonexistent/hidraw9']);
        iterator_to_array($this->messages(3), false);
        $status = $this->request('status')['result'];
        self::assertFalse($status['connected']);
        self::assertNotSame('', $status['error']);
        self::assertSame('offline', $this->request('state')['code']);
        self::assertSame('offline', $this->request('write', ['frames' => [self::hex(Commands::mute(0, true))]])['code']);

        $this->request('connect', ['target' => 'demo']);
        iterator_to_array($this->messages(3), false);
        self::assertTrue($this->request('status')['result']['connected']);

        $this->request('disconnect');
        iterator_to_array($this->messages(3), false);
        self::assertFalse($this->request('status')['result']['connected']);
    }

    public function testRefusesASecondBridgeOnTheSameSocket(): void
    {
        $this->expectException(BridgeException::class);
        $this->expectExceptionMessage('already running');

        $this->bridge('demo');
    }

    public function testRemovesAStaleSocket(): void
    {
        $this->bridge->close();
        touch($this->socket);

        $this->bridge = $this->bridge('demo');
        $this->client = $this->connectClient();
        self::assertTrue($this->request('ping')['ok']);
    }
}
