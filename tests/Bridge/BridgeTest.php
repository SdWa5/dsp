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

    private function bridge(string $target): Bridge
    {
        $bridge = new Bridge(new TransportFactory(new HidrawLocator('/nonexistent'), self::DEMO, 0.01), $this->socket, $target, meterInterval: 0.01, publishInterval: 0.0);
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
        iterator_to_array($this->messages(5), false);
        self::assertSame(250, $this->state()['outputs'][0]['gain']);
    }

    public function testRecallReadsThePresetBack(): void
    {
        $this->request('write', ['frames' => array_map(self::hex(...), [Commands::gain(8, 111), Commands::presetName('Probe'), Commands::storePreset(5), Commands::gain(8, 222)])]);
        iterator_to_array($this->messages(5), false);
        self::assertSame(222, $this->state()['outputs'][0]['gain']);

        $this->request('write', ['frames' => [self::hex(Commands::loadPreset(5))]]);
        iterator_to_array($this->messages(5), false);
        $state = $this->state();
        self::assertSame(111, $state['outputs'][0]['gain']);
        self::assertSame('Probe', $state['preset_name']);
        self::assertSame(5, $state['active_preset']);
        self::assertSame('Probe', $state['preset_names'][4]);
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
