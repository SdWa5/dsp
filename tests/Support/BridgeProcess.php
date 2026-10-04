<?php

namespace App\Tests\Support;

use Symfony\Component\Process\Process;

/**
 * Runs bin/console trackdsp:bridge on the simulated device as a subprocess with its own socket.
 * A null target starts it without --target, on the saved target or else on $TRACKDSP_TARGET (demo in tests).
 */
final class BridgeProcess
{
    public readonly string $socket;
    private Process $process;

    /**
     * @param array<string, mixed>|null $savedTarget written to the target file before the start
     */
    public function __construct(?string $target = 'demo', ?array $savedTarget = null)
    {
        $this->socket = sys_get_temp_dir().'/trackdsp-test-'.bin2hex(random_bytes(4)).'.sock';
        if (null !== $savedTarget) {
            file_put_contents($this->file('.target.json'), json_encode($savedTarget, \JSON_THROW_ON_ERROR));
        }
        $this->process = new Process(
            ['php', 'bin/console', 'trackdsp:bridge', '--env=test', ...(null === $target ? [] : ['--target='.$target]), '--socket='.$this->socket, '--meter-interval=0.05'],
            \dirname(__DIR__, 2),
        );
        $this->process->start();
        $deadline = microtime(true) + 10;
        // Unix bind creates the path before listen accepts connections. The command reports after listen.
        while (!str_contains($this->process->getOutput(), 'Bridge on ')) {
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
        foreach (['.riders.json', '.target.json', '.measure.lock', '.measure.restore.json'] as $suffix) {
            @unlink($this->file($suffix));
        }

        return $this->process->getExitCode();
    }

    private function file(string $suffix): string
    {
        return (preg_replace('/\.sock$/', '', $this->socket) ?? $this->socket).$suffix;
    }
}
