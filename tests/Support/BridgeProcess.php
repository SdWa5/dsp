<?php

namespace App\Tests\Support;

use Symfony\Component\Process\Process;

/**
 * Runs bin/console trackdsp:bridge on the simulated device as a subprocess with its own socket.
 */
final class BridgeProcess
{
    public readonly string $socket;
    private Process $process;

    public function __construct(string $target = 'demo')
    {
        $this->socket = sys_get_temp_dir().'/trackdsp-test-'.bin2hex(random_bytes(4)).'.sock';
        $this->process = new Process(
            ['php', 'bin/console', 'trackdsp:bridge', '--env=test', '--target='.$target, '--socket='.$this->socket, '--meter-interval=0.05'],
            \dirname(__DIR__, 2),
        );
        $this->process->start();
        $deadline = microtime(true) + 10;
        while (!file_exists($this->socket)) {
            if (!$this->process->isRunning() || microtime(true) > $deadline) {
                throw new \RuntimeException('The bridge did not start: '.$this->process->getErrorOutput().$this->process->getOutput());
            }
            usleep(20_000);
        }
    }

    public function process(): Process
    {
        return $this->process;
    }

    /**
     * Sends SIGTERM and waits for the exit.
     */
    public function stop(): ?int
    {
        if ($this->process->isRunning()) {
            $this->process->signal(\SIGTERM);
            $this->process->wait();
        }
        @unlink($this->socket);
        @unlink(preg_replace('/\.sock$/', '', $this->socket).'.riders.json');
        @unlink(preg_replace('/\.sock$/', '', $this->socket).'.measure.lock');
        @unlink(preg_replace('/\.sock$/', '', $this->socket).'.measure.restore.json');

        return $this->process->getExitCode();
    }
}
