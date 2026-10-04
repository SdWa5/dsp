<?php

namespace App\Tests\Support;

use App\Bridge\BridgeClient;
use Symfony\Component\Process\Process;

/**
 * A stand-in for the bridge as a subprocess, for what the simulated device cannot show, such as a device
 * that is slow to take the writes or gone. It answers "status" and "write" from scripts, every other op with
 * success, and "log" with every request received so far.
 */
final class ScriptedBridge
{
    public readonly string $socket;
    private Process $process;

    /**
     * @param list<array<string, mixed>> $statuses answers to "status" in turn over {connected: true, queued: 0,
     *                                             riders: []}, the last one repeats
     * @param list<string|null>          $writes   answers to "write" in turn: null takes it, a string rejects
     *                                             it with that bridge error code, for example "offline". Once
     *                                             they are used up every write is taken
     */
    public function __construct(array $statuses = [[]], array $writes = [])
    {
        $this->socket = sys_get_temp_dir().'/sdwa5-dsp-scripted-'.bin2hex(random_bytes(4)).'.sock';
        $this->process = new Process(['php', __DIR__.'/scripted-bridge.php', $this->socket, json_encode($statuses, \JSON_THROW_ON_ERROR), json_encode($writes, \JSON_THROW_ON_ERROR)]);
        $this->process->start();
        $deadline = microtime(true) + 10;
        // Unix bind creates the path before listen accepts connections. Wait for the child after listen.
        while (!str_contains($this->process->getOutput(), "ready\n")) {
            if (!$this->process->isRunning() || microtime(true) > $deadline) {
                throw new \RuntimeException('The scripted bridge did not start: '.$this->process->getErrorOutput());
            }
            usleep(10_000);
        }
    }

    public function client(): BridgeClient
    {
        return new BridgeClient($this->socket);
    }

    /**
     * Every request so far, in order.
     *
     * @return list<array<string, mixed>>
     */
    public function log(): array
    {
        $log = $this->client()->request('log');

        return \is_array($log) ? array_values(array_filter($log, 'is_array')) : [];
    }

    /**
     * The ops of the requests so far, for example ["status", "write", "status"].
     *
     * @return list<string>
     */
    public function ops(): array
    {
        return array_map(static fn (array $request): string => (string) ($request['op'] ?? ''), $this->log());
    }

    public function stop(): void
    {
        $this->process->stop(0);
        @unlink($this->socket);
        @unlink(preg_replace('/\.sock$/', '', $this->socket).'.measure.lock');
        @unlink(preg_replace('/\.sock$/', '', $this->socket).'.measure.restore.json');
    }
}
