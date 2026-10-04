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
        $this->socket = sys_get_temp_dir().'/sdwa5-dsp-test-'.bin2hex(random_bytes(4)).'.sock';
        $this->bridge = $this->bridge('demo');
        $this->client = $this->connectClient();
    }

    protected function tearDown(): void
    {
        @fclose($this->client);
        $this->bridge->close();
    }

    private function bridge(string $target, ?string $ridersFile = null, ?string $targetFile = null, bool $restoreTarget = false): Bridge
    {
        $bridge = new Bridge(new TransportFactory(new HidrawLocator('/nonexistent'), self::DEMO, 0.01), $this->socket, $target, meterInterval: 0.01, publishInterval: 0.0, ridersFile: $ridersFile, targetFile: $targetFile, restoreTarget: $restoreTarget);
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
        self::assertIsInt($events['meters']['limiting']);
        self::assertSame([], $events['meters']['riders']);
    }

    public function testRidersRideTheGainAndSurviveARestart(): void
    {
        // The demo links In1 and In2, so a rider on In1 is the rider of both
        $file = sys_get_temp_dir().'/sdwa5-dsp-test-'.bin2hex(random_bytes(4)).'.riders.json';
        $this->restart($file);
        try {
            $before = $this->state()['inputs'][0]['gain'];
            $answer = $this->request('ride', ['channel' => 0, 'settings' => ['target' => -80, 'silence' => -90, 'min' => -40, 'slew' => 50, 'average' => 0.05, 'deadband' => 0]]);
            self::assertTrue($answer['ok'], (string) json_encode($answer));
            self::assertSame([0, 1], array_column($answer['result']['riders'], 'channel'));
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
            self::assertSame([0, 1], array_column($this->request('status')['result']['riders'], 'channel'));

            self::assertSame([], $this->request('unride', ['channel' => 0])['result']['riders']);
            self::assertSame(['riders' => [], 'presets' => []], json_decode((string) file_get_contents($file), true));
        } finally {
            @unlink($file);
        }
    }

    public function testARiderRidesItsLinkGroupAsOne(): void
    {
        // In3 and In4 linked, In4 starting 3 dB below In3
        $frames = array_map(self::hex(...), [Commands::link(2, 0b1100), Commands::link(3, 0), Commands::gain(3, $this->state()['inputs'][2]['gain'] - 30)]);
        self::assertTrue($this->request('write', ['frames' => $frames])['ok']);
        $before = $this->state()['inputs'][2]['gain'];
        $settings = ['target' => -80, 'silence' => -90, 'min' => -40, 'slew' => 50, 'average' => 0.05, 'deadband' => 0];
        // Started on In4, the rider is the rider of the group: both inputs get the settings
        $riders = $this->request('ride', ['channel' => 3, 'settings' => $settings])['result']['riders'];
        self::assertSame([2, 3], array_column($riders, 'channel'));
        self::assertSame([-80, -80], array_map(intval(...), array_column($riders, 'target')));

        fwrite($this->client, '{"id":99,"op":"subscribe"}'."\n");
        $riders = null;
        $deadline = microtime(true) + 5;
        do {
            foreach ($this->messages(10) as $message) {
                if ('meters' === ($message['event'] ?? null)) {
                    $riders = $message['data']['riders'];
                }
            }
            $inputs = $this->state()['inputs'];
        } while (($inputs[2]['gain'] >= $before || $inputs[2]['gain'] !== $inputs[3]['gain']) && microtime(true) < $deadline);

        self::assertLessThan($before, $inputs[2]['gain']);
        self::assertSame($inputs[2]['gain'], $inputs[3]['gain']);
        self::assertIsArray($riders);
        self::assertSame([2, 3], $riders[0]['members']);
        self::assertContains($riders[0]['status'], ['riding', 'limit', 'steady']);
        self::assertSame(['linked', [], 2], [$riders[1]['status'], $riders[1]['members'], $riders[1]['lead']]);
    }

    public function testRidersOfALinkGroupShareTheirSettings(): void
    {
        $settings = ['target' => -80, 'silence' => -90, 'min' => -40, 'slew' => 50, 'average' => 0.05, 'deadband' => 0];
        // The demo links In3 and In4, so they start apart here
        $this->request('write', ['frames' => array_map(self::hex(...), [Commands::link(2, 0b100), Commands::link(3, 0b1000)])]);
        $this->request('ride', ['channel' => 2, 'settings' => $settings]);
        $this->request('ride', ['channel' => 3, 'settings' => ['target' => -20] + $settings]);

        // Linked afterwards, the rider on the lowest input wins
        $this->request('write', ['frames' => array_map(self::hex(...), [Commands::link(2, 0b1100), Commands::link(3, 0)])]);
        $deadline = microtime(true) + 5;
        do {
            iterator_to_array($this->messages(10), false);
            $targets = array_column($this->request('status')['result']['riders'], 'target', 'channel');
        } while (-80 != $targets[3] && microtime(true) < $deadline);
        self::assertEquals([2 => -80, 3 => -80], $targets);

        // A change or a stop on any member applies to the group
        $riders = $this->request('ride', ['channel' => 3, 'settings' => ['target' => -30] + $settings])['result']['riders'];
        self::assertEquals([2 => -30, 3 => -30], array_column($riders, 'target', 'channel'));
        self::assertSame([], $this->request('unride', ['channel' => 3])['result']['riders']);
    }

    public function testRidersAreKeptPerPreset(): void
    {
        $file = sys_get_temp_dir().'/sdwa5-dsp-test-'.bin2hex(random_bytes(4)).'.riders.json';
        $this->restart($file);
        $channels = fn (): array => array_column($this->request('status')['result']['riders'], 'channel');
        $write = fn (Frame ...$frames): array => $this->request('write', ['frames' => array_map(self::hex(...), $frames)]);
        try {
            // U01 stored with the rider on In1 and In2, U02 without riders
            $this->request('ride', ['channel' => 0, 'settings' => ['target' => -24]]);
            $write(Commands::presetName('With rider'), Commands::storePreset(1));
            $this->request('unride', ['channel' => 0]);
            $write(Commands::presetName('No rider'), Commands::storePreset(2));

            $write(Commands::loadPreset(1));
            self::assertSame([0, 1], $channels());
            self::assertEquals([-24, -24], array_column($this->request('status')['result']['riders'], 'target'));
            $write(Commands::loadPreset(2));
            self::assertSame([], $channels());
            $write(Commands::loadPreset(1));
            self::assertSame([0, 1], $channels());
            // Factory default runs with none
            $write(Commands::loadPreset(0));
            self::assertSame([], $channels());

            // The riders of a preset survive a restart
            $this->restart($file);
            $write(Commands::loadPreset(1));
            self::assertSame([0, 1], $channels());
        } finally {
            @unlink($file);
        }
    }

    public function testReadsARidersFileFromBeforePresets(): void
    {
        $file = sys_get_temp_dir().'/sdwa5-dsp-test-'.bin2hex(random_bytes(4)).'.riders.json';
        file_put_contents($file, json_encode([['channel' => 2, 'target' => -24]]));
        try {
            $this->restart($file);
            // In3 comes back, the demo's link with In4 may already have added In4
            self::assertSame(2, $this->request('status')['result']['riders'][0]['channel']);
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

    private function restart(?string $ridersFile, ?string $targetFile = null, bool $restoreTarget = false): void
    {
        @fclose($this->client);
        $this->bridge->close();
        $this->bridge = $this->bridge('demo', $ridersFile, $targetFile, $restoreTarget);
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

    public function testReportsTheSimulatedDevice(): void
    {
        self::assertTrue($this->request('status')['result']['demo']);
        $this->request('connect', ['target' => 'usb:/nonexistent/hidraw9']);
        self::assertFalse($this->request('status')['result']['demo']);
    }

    public function testTheChosenTargetSurvivesARestart(): void
    {
        $file = self::targetFile();
        try {
            $this->restart(null, $file, true);
            self::assertSame('demo', $this->request('status')['result']['target']);

            $this->request('connect', ['target' => 'usb:/nonexistent/hidraw9']);
            self::assertSame(['target' => 'usb:/nonexistent/hidraw9', 'connect' => true], json_decode((string) file_get_contents($file), true));

            // The web container restarts, and its daemon starts the bridge on the demo again
            $this->restart(null, $file, true);
            iterator_to_array($this->messages(3), false);
            $status = $this->request('status')['result'];
            self::assertSame('usb:/nonexistent/hidraw9', $status['target']);
            self::assertFalse($status['demo']);
            self::assertFalse($status['connected']);
            self::assertNotSame('', $status['error'], 'It keeps trying the chosen device rather than falling back.');
        } finally {
            @unlink($file);
        }
    }

    public function testADisconnectSurvivesARestart(): void
    {
        $file = self::targetFile();
        try {
            $this->restart(null, $file, true);
            $this->request('disconnect');
            $this->restart(null, $file, true);
            iterator_to_array($this->messages(5), false);
            $status = $this->request('status')['result'];
            self::assertSame('demo', $status['target']);
            self::assertFalse($status['connected']);
            self::assertSame('', $status['error']);

            $this->request('connect');
            iterator_to_array($this->messages(3), false);
            self::assertTrue($this->request('status')['result']['connected']);
        } finally {
            @unlink($file);
        }
    }

    public function testAGivenTargetWinsOverTheSavedOne(): void
    {
        $file = self::targetFile();
        file_put_contents($file, json_encode(['target' => 'usb:/nonexistent/hidraw9', 'connect' => false]));
        try {
            $this->restart(null, $file, false);
            $status = $this->request('status')['result'];
            self::assertSame('demo', $status['target']);
            self::assertTrue($status['connected']);

            // It is still kept for a later start without a target
            $this->request('connect', ['target' => 'usb:/nonexistent/hidraw8']);
            self::assertSame('usb:/nonexistent/hidraw8', json_decode((string) file_get_contents($file), true)['target']);
        } finally {
            @unlink($file);
        }
    }

    public function testAnUnreadableSavedTargetKeepsTheGivenOne(): void
    {
        $file = self::targetFile();
        try {
            foreach (['not json', '[]', '{"target": "  ", "connect": true}', '{"target": "usb"}', '{"target": 5, "connect": true}'] as $content) {
                file_put_contents($file, $content);
                $this->restart(null, $file, true);
                $status = $this->request('status')['result'];
                self::assertSame('demo', $status['target'], $content);
                self::assertTrue($status['connected'], $content);
            }
        } finally {
            @unlink($file);
        }
    }

    private static function targetFile(): string
    {
        return sys_get_temp_dir().'/sdwa5-dsp-test-'.bin2hex(random_bytes(4)).'.target.json';
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
