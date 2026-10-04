<?php

namespace App\Tests\Bridge;

use App\Bridge\BridgeClient;
use App\Bridge\BridgeException;
use App\Protocol\Commands;
use App\Tests\Support\BridgeProcess;
use PHPUnit\Framework\TestCase;

/**
 * sdwa5-dsp:bridge as a real process, spoken to through BridgeClient.
 */
final class BridgeProcessTest extends TestCase
{
    public function testClientRequestsEventsAndShutdown(): void
    {
        $bridge = new BridgeProcess();
        try {
            $client = new BridgeClient($bridge->socket);
            self::assertSame('pong', $client->request('ping'));

            $client->request('write', ['frames' => [bin2hex(\chr(Commands::mute(9, true)->cmd).Commands::mute(9, true)->data)]]);
            $state = $client->request('state');
            self::assertIsArray($state);
            self::assertTrue($state['outputs'][1]['mute']);

            $seen = [];
            foreach ($client->events(1.0) as $event) {
                if (null !== $event) {
                    $seen[$event['event']] = true;
                }
                if (isset($seen['status'], $seen['state'], $seen['meters'])) {
                    break;
                }
            }
            self::assertSame(['status', 'state', 'meters'], array_keys($seen));

            try {
                $client->request('nope');
                self::fail('Unknown op accepted.');
            } catch (BridgeException $e) {
                self::assertSame(400, $e->getCode());
            }
        } finally {
            $exit = $bridge->stop();
        }
        self::assertSame(0, $exit, $bridge->process()->getErrorOutput());
        self::assertStringContainsString('Bridge on', $bridge->process()->getOutput());
    }

    public function testTheSocketAcceptsAConnectionAsSoonAsTheBridgeIsStarted(): void
    {
        $bridge = new BridgeProcess();
        try {
            $stream = @stream_socket_client('unix://'.$bridge->socket, $errno, $errstr, 1.0);
            self::assertNotFalse($stream, \sprintf('Connection refused right after the start (%d: %s).', $errno, $errstr));
            fclose($stream);
        } finally {
            $bridge->stop();
        }
    }

    public function testSecondBridgeOnTheSameSocketFails(): void
    {
        $bridge = new BridgeProcess();
        try {
            $second = new \Symfony\Component\Process\Process(['php', 'bin/console', 'sdwa5-dsp:bridge', '--env=test', '-t', 'demo', '-s', $bridge->socket], \dirname(__DIR__, 2));
            $second->run();
            self::assertSame(1, $second->getExitCode());
            self::assertStringContainsString('already running', $second->getOutput());
        } finally {
            $bridge->stop();
        }
    }

    public function testStartsOnTheSavedTargetUnlessOneIsGiven(): void
    {
        $saved = ['target' => 'usb:/nonexistent/hidraw9', 'connect' => false];
        $bridge = new BridgeProcess(null, $saved);
        try {
            $status = (new BridgeClient($bridge->socket))->request('status');
            self::assertIsArray($status);
            self::assertSame('usb:/nonexistent/hidraw9', $status['target']);
            self::assertFalse($status['connected']);
        } finally {
            $bridge->stop();
        }
        self::assertStringContainsString('target usb:/nonexistent/hidraw9', $bridge->process()->getOutput());

        $bridge = new BridgeProcess('demo', $saved);
        try {
            $status = (new BridgeClient($bridge->socket))->request('status');
            self::assertIsArray($status);
            self::assertSame('demo', $status['target']);
            self::assertTrue($status['demo']);
        } finally {
            $bridge->stop();
        }
    }

    public function testClientWithoutBridge(): void
    {
        $this->expectException(BridgeException::class);
        $this->expectExceptionCode(503);

        (new BridgeClient(sys_get_temp_dir().'/sdwa5-dsp-missing.sock', 0.5))->request('ping');
    }
}
