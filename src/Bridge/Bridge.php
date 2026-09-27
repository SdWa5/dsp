<?php

namespace App\Bridge;

use App\Automation\GainRider;
use App\Automation\RiderSettings;
use App\Client\DspException;
use App\Client\Matrix;
use App\Model\DeviceState;
use App\Protocol\Commands;
use App\Protocol\Frame;
use App\Protocol\Opcode;
use App\Transport\TcpTransport;
use App\Transport\TransportException;
use App\Transport\TransportFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Owns the one connection to the device and serves it to any number of clients over a unix socket.
 *
 * The socket speaks JSON lines. A request is {"id": 1, "op": "...", ...}, the answer is
 * {"id": 1, "ok": true, "result": ...} or {"id": 1, "ok": false, "error": "...", "code": "..."}.
 * A subscribed client also receives {"event": "status"|"state"|"meters", "data": ...} lines.
 *
 * Writes change the cached state at once and go to the device from a queue that merges repeated
 * writes to the same target, so a fast fader drag does not pile up behind a slow device.
 *
 * Gain riders run inside the bridge, so they keep working without a browser. They read the meters, which are
 * polled while a client listens or a rider runs, and write the input gain like any other client. Their
 * settings are kept in $ridersFile and come back after a restart.
 *
 * Ops: ping, status, state, write {frames: [hex]}, connect {target?}, disconnect, refresh, subscribe,
 * ride {channel, settings}, unride {channel}
 */
final class Bridge
{
    private const MAX_BUFFER = 1 << 20;
    private const FLUSH_BUDGET = 0.05;
    private const BACKOFF = [1.0, 2.0, 4.0, 8.0, 15.0];

    /** @var resource|null */
    private $server;
    /** @var array<int, array{stream: resource, in: string, out: string, subscribed: bool}> */
    private array $clients = [];
    private ?Matrix $dsp = null;
    private ?DeviceState $state = null;
    private WriteQueue $queue;
    private bool $wanted = true;
    private bool $running = false;
    private bool $stateDirty = false;
    private bool $statusDirty = false;
    private bool $resyncPending = false;
    private int $failures = 0;
    private float $nextConnect = 0.0;
    private float $nextPublish = 0.0;
    private float $nextStatus = 0.0;
    private float $nextMeter = 0.0;
    private float $nextResync = 0.0;
    private string $info = '';
    private string $error = '';
    /** @var array<int, GainRider> input channel => rider */
    private array $riders = [];

    public function __construct(
        private readonly TransportFactory $transports,
        private readonly string $socketPath,
        private string $target,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly float $meterInterval = 0.3,
        private readonly float $publishInterval = 0.2,
        private readonly float $resyncInterval = 60.0,
        private readonly ?string $ridersFile = null,
    ) {
        $this->queue = new WriteQueue();
    }

    /**
     * Opens the unix socket. A stale socket file is removed, a socket with a live bridge behind it is not.
     */
    public function listen(): void
    {
        if (file_exists($this->socketPath)) {
            $probe = @stream_socket_client('unix://'.$this->socketPath, $errno, $errstr, 0.2);
            if (false !== $probe) {
                fclose($probe);
                throw new BridgeException(\sprintf('A bridge is already running on %s.', $this->socketPath));
            }
            @unlink($this->socketPath);
        }
        $dir = \dirname($this->socketPath);
        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new BridgeException(\sprintf('Cannot create %s.', $dir));
        }
        $server = @stream_socket_server('unix://'.$this->socketPath, $errno, $errstr);
        if (false === $server) {
            throw new BridgeException(\sprintf('Cannot listen on %s: %s', $this->socketPath, $errstr));
        }
        stream_set_blocking($server, false);
        $this->server = $server;
        $this->loadRiders();
        $this->logger->info('Bridge listening on {socket}, target {target}.', ['socket' => $this->socketPath, 'target' => $this->target]);
    }

    public function run(): void
    {
        if (null === $this->server) {
            $this->listen();
        }
        $this->running = true;
        while ($this->running) {
            $this->tick(0.05);
        }
        $this->close();
    }

    public function stop(): void
    {
        $this->running = false;
    }

    public function close(): void
    {
        foreach ($this->clients as $id => $client) {
            $this->drop($id);
        }
        $this->disconnect();
        if (null !== $this->server) {
            fclose($this->server);
            $this->server = null;
            @unlink($this->socketPath);
        }
    }

    /**
     * One loop pass: device work that is due, then socket I/O for at most $maxWait seconds.
     */
    public function tick(float $maxWait): void
    {
        $this->deviceWork();
        $this->publish();
        $this->io($maxWait);
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        return [
            'connected' => null !== $this->dsp,
            'target' => $this->target,
            'transport' => $this->dsp?->transport()->describe(),
            'info' => $this->info,
            'error' => $this->error,
            'queued' => $this->queue->count(),
            'clients' => \count($this->clients),
            'riders' => array_values(array_map(static fn (GainRider $r): array => $r->settings()->toArray(), $this->riders)),
        ];
    }

    private function deviceWork(): void
    {
        $now = self::now();
        $dsp = $this->dsp;
        if (null === $dsp) {
            if ($this->wanted && $now >= $this->nextConnect) {
                $this->connect();
            }

            return;
        }
        try {
            $deadline = $now + self::FLUSH_BUDGET;
            while (self::now() < $deadline && null !== $frame = $this->queue->shift()) {
                $dsp->write($frame);
                if (\in_array($frame->opcode(), [Opcode::LoadPreset, Opcode::StorePreset], true)) {
                    $this->resyncPending = true;
                }
                $this->statusDirty = true;
            }
            if (0 === $this->queue->count() && ($this->resyncPending || $now >= $this->nextResync)) {
                $this->read();
            }
            if ($now >= $this->nextMeter && ($this->subscribers() > 0 || [] !== $this->riders)) {
                $this->nextMeter = $now + $this->meterInterval;
                $raw = $dsp->metersRaw();
                $levels = Matrix::parseMeters($raw);
                $this->ride($levels, self::now());
                if ($this->subscribers() > 0) {
                    $this->broadcast('meters', ['levels' => $levels, 'raw' => bin2hex($raw), 'riders' => $this->riderStatus(self::now())]);
                }
            }
        } catch (DspException|TransportException $e) {
            $this->fail($e->getMessage());
        }
    }

    private function connect(): void
    {
        try {
            $this->dsp = $this->transports->connect($this->target);
            $this->info = $this->dsp->info();
            $this->read();
            $this->failures = 0;
            $this->error = '';
            $this->logger->info('Connected to {target}: {info}.', ['target' => $this->target, 'info' => $this->info]);
        } catch (DspException|TransportException|\InvalidArgumentException $e) {
            $this->fail($e->getMessage());
        }
        $this->statusDirty = true;
    }

    /**
     * Reads the whole device into the cached state. Values the device does not report are kept.
     */
    private function read(): void
    {
        \assert(null !== $this->dsp);
        $config = $this->dsp->config();
        $groups = $this->dsp->groupsRaw();
        $names = $this->dsp->presetNames();
        $active = $this->dsp->activePreset();
        if (null === $this->state) {
            $this->state = new DeviceState($config, $groups, $names, $active, $this->ipAddress());
        } else {
            $this->state->replaceConfig($config, $groups);
            $this->state->replacePresetNames($names);
            $this->state->activePreset = $active;
        }
        $this->resyncPending = false;
        $this->nextResync = self::now() + $this->resyncInterval;
        $this->stateDirty = true;
    }

    /**
     * The device answers 0xB0 over USB only. Over TCP the address is the one connected to.
     */
    private function ipAddress(): string
    {
        \assert(null !== $this->dsp);
        $transport = $this->dsp->transport();
        if ($transport instanceof TcpTransport) {
            // The address actually connected to, also when the target was "auto"
            return preg_replace('#^tcp://(.*):\d+$#', '$1', $transport->describe()) ?? $transport->describe();
        }

        return $this->dsp->ipAddress();
    }

    private function fail(string $message): void
    {
        $this->logger->warning('Device error on {target}: {message}', ['target' => $this->target, 'message' => $message]);
        $this->disconnect();
        $this->error = $message;
        $this->nextConnect = self::now() + self::BACKOFF[min($this->failures, \count(self::BACKOFF) - 1)];
        ++$this->failures;
    }

    private function disconnect(): void
    {
        if (null !== $this->dsp) {
            try {
                $this->dsp->close();
            } catch (TransportException) {
            }
        }
        $this->dsp = null;
        $this->state = null;
        $this->info = '';
        $this->queue->clear();
        $this->statusDirty = true;
    }

    private function publish(): void
    {
        if ($this->statusDirty && self::now() >= $this->nextStatus) {
            $this->statusDirty = false;
            $this->nextStatus = self::now() + $this->publishInterval;
            $this->broadcast('status', $this->status());
        }
        if ($this->stateDirty && null !== $this->state && self::now() >= $this->nextPublish) {
            $this->stateDirty = false;
            $this->nextPublish = self::now() + $this->publishInterval;
            if ($this->subscribers() > 0) {
                $this->broadcast('state', $this->state->toDevice()->toArray());
            }
        }
    }

    private function io(float $maxWait): void
    {
        if (null === $this->server) {
            usleep((int) ($maxWait * 1e6));

            return;
        }
        $read = [$this->server];
        $write = [];
        foreach ($this->clients as $client) {
            $read[] = $client['stream'];
            if ('' !== $client['out']) {
                $write[] = $client['stream'];
            }
        }
        $except = null;
        $seconds = (int) $maxWait;
        // Interrupted by a signal: stream_select() warns and returns false, the next pass goes on
        if (!@stream_select($read, $write, $except, $seconds, (int) (($maxWait - $seconds) * 1e6))) {
            return;
        }
        foreach ($read as $stream) {
            if ($stream === $this->server) {
                $this->accept();
            } else {
                $this->receive((int) $stream);
            }
        }
        foreach ($write as $stream) {
            $this->flush((int) $stream);
        }
    }

    private function accept(): void
    {
        \assert(null !== $this->server);
        $stream = @stream_socket_accept($this->server, 0);
        if (false === $stream) {
            return;
        }
        stream_set_blocking($stream, false);
        $this->clients[(int) $stream] = ['stream' => $stream, 'in' => '', 'out' => '', 'subscribed' => false];
        $this->statusDirty = true;
    }

    private function receive(int $id): void
    {
        if (!isset($this->clients[$id])) {
            return;
        }
        $chunk = fread($this->clients[$id]['stream'], 65536);
        if (false === $chunk || ('' === $chunk && feof($this->clients[$id]['stream']))) {
            $this->drop($id);

            return;
        }
        $this->clients[$id]['in'] .= $chunk;
        if (\strlen($this->clients[$id]['in']) > self::MAX_BUFFER) {
            $this->drop($id);

            return;
        }
        while (isset($this->clients[$id]) && false !== $pos = strpos($this->clients[$id]['in'], "\n")) {
            $line = substr($this->clients[$id]['in'], 0, $pos);
            $this->clients[$id]['in'] = substr($this->clients[$id]['in'], $pos + 1);
            if ('' !== trim($line)) {
                $this->send($id, $this->handle($id, $line));
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function handle(int $client, string $line): array
    {
        try {
            $request = json_decode($line, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['id' => null, 'ok' => false, 'error' => 'Invalid JSON.', 'code' => 'invalid'];
        }
        if (!\is_array($request) || !\is_string($request['op'] ?? null)) {
            return ['id' => null, 'ok' => false, 'error' => 'Missing "op".', 'code' => 'invalid'];
        }
        $id = $request['id'] ?? null;
        try {
            return ['id' => $id, 'ok' => true, 'result' => $this->dispatch($client, $request['op'], $request)];
        } catch (BridgeException $e) {
            return ['id' => $id, 'ok' => false, 'error' => $e->getMessage(), 'code' => 409 === $e->getCode() ? 'offline' : 'invalid'];
        }
    }

    /**
     * @param array<mixed> $request
     */
    private function dispatch(int $client, string $op, array $request): mixed
    {
        switch ($op) {
            case 'ping':
                return 'pong';
            case 'status':
                return $this->status();
            case 'state':
                return $this->state()->toDevice()->toArray();
            case 'write':
                return $this->write($request['frames'] ?? null);
            case 'connect':
                $target = $request['target'] ?? $this->target;
                if (!\is_string($target) || '' === trim($target)) {
                    throw new BridgeException('"target" must be a non-empty string.', 400);
                }
                $this->disconnect();
                $this->target = trim($target);
                $this->wanted = true;
                $this->failures = 0;
                $this->error = '';
                $this->nextConnect = 0.0;

                return $this->status();
            case 'disconnect':
                $this->disconnect();
                $this->wanted = false;
                $this->error = '';

                return $this->status();
            case 'refresh':
                $this->state();
                $this->resyncPending = true;

                return $this->status();
            case 'ride':
                $channel = self::riderChannel($request);
                $settings = $request['settings'] ?? [];
                if (!\is_array($settings)) {
                    throw new BridgeException('"settings" must be an object.', 400);
                }
                try {
                    $this->riders[$channel] = new GainRider(RiderSettings::fromArray($channel, $settings));
                } catch (\InvalidArgumentException $e) {
                    throw new BridgeException($e->getMessage(), 400, $e);
                }
                ksort($this->riders);
                $this->saveRiders();
                $this->statusDirty = true;
                $this->logger->info('Gain rider on {channel}: {settings}', ['channel' => $channel, 'settings' => json_encode($this->riders[$channel]->settings()->toArray())]);

                return $this->status();
            case 'unride':
                unset($this->riders[self::riderChannel($request)]);
                $this->saveRiders();
                $this->statusDirty = true;

                return $this->status();
            case 'subscribe':
                $this->clients[$client]['subscribed'] = true;
                $this->event($client, 'status', $this->status());
                if (null !== $this->state) {
                    $this->event($client, 'state', $this->state->toDevice()->toArray());
                }

                return true;
            default:
                throw new BridgeException(\sprintf('Unknown op "%s".', $op), 400);
        }
    }

    /**
     * @return array{queued: int}
     */
    private function write(mixed $frames): array
    {
        if (!\is_array($frames) || [] === $frames) {
            throw new BridgeException('"frames" must be a non-empty list of hex strings (command and data).', 400);
        }
        $parsed = [];
        foreach ($frames as $hex) {
            $hex = \is_string($hex) ? str_replace(' ', '', $hex) : '';
            if (!preg_match('/^(?:[0-9a-fA-F]{2}){1,255}$/', $hex)) {
                throw new BridgeException('Every frame must be hex: command byte followed by the data.', 400);
            }
            $bytes = (string) hex2bin($hex);
            $parsed[] = Frame::request(\ord($bytes[0]), substr($bytes, 1));
        }
        $state = $this->state();
        foreach ($parsed as $frame) {
            $state->apply($frame);
            $this->queue->push($frame);
        }
        $this->stateDirty = true;
        $this->statusDirty = true;

        return ['queued' => $this->queue->count()];
    }

    /**
     * Feeds one meter reading to every rider and queues the gains they change.
     *
     * @param list<float> $levels
     */
    private function ride(array $levels, float $now): void
    {
        if ([] === $this->riders || null === $this->state) {
            return;
        }
        $inputs = $this->state->toDevice()->inputs;
        $frames = [];
        foreach ($this->riders as $channel => $rider) {
            $rider->observeGain($inputs[$channel]->gain, $now);
            if (null !== $raw = $rider->meter($levels[$channel] ?? -90.0, $now)) {
                $frames[] = Commands::gain($channel, $raw);
            }
        }
        foreach ($frames as $frame) {
            $this->state->apply($frame);
            $this->queue->push($frame);
        }
        if ([] !== $frames) {
            $this->stateDirty = true;
        }
    }

    /**
     * @return list<array{channel: int, gain: float|null, average: float|null, status: string}>
     */
    private function riderStatus(float $now): array
    {
        $status = [];
        foreach ($this->riders as $channel => $rider) {
            $gain = $rider->gainDb();
            $average = $rider->averageDb();
            $status[] = [
                'channel' => $channel,
                'gain' => null === $gain ? null : round($gain, 1),
                'average' => null === $average ? null : round($average, 1),
                'status' => $rider->status($now),
            ];
        }

        return $status;
    }

    /**
     * @param array<mixed> $request
     */
    private static function riderChannel(array $request): int
    {
        $channel = $request['channel'] ?? null;
        if (!\is_int($channel) || $channel < 0 || $channel > 7) {
            throw new BridgeException('"channel" must be an input index 0..7.', 400);
        }

        return $channel;
    }

    private function loadRiders(): void
    {
        if (null === $this->ridersFile || !is_file($this->ridersFile)) {
            return;
        }
        $saved = json_decode((string) file_get_contents($this->ridersFile), true);
        foreach (\is_array($saved) ? $saved : [] as $settings) {
            try {
                if (!\is_array($settings) || !\is_int($settings['channel'] ?? null)) {
                    throw new \InvalidArgumentException('no channel');
                }
                $this->riders[$settings['channel']] = new GainRider(RiderSettings::fromArray($settings['channel'], $settings));
            } catch (\InvalidArgumentException $e) {
                $this->logger->warning('Skipped a saved gain rider in {file}: {message}', ['file' => $this->ridersFile, 'message' => $e->getMessage()]);
            }
        }
        ksort($this->riders);
        if ([] !== $this->riders) {
            $this->logger->info('Restored {count} gain rider(s) from {file}.', ['count' => \count($this->riders), 'file' => $this->ridersFile]);
        }
    }

    private function saveRiders(): void
    {
        if (null === $this->ridersFile) {
            return;
        }
        $settings = array_values(array_map(static fn (GainRider $r): array => $r->settings()->toArray(), $this->riders));
        if (false === @file_put_contents($this->ridersFile, json_encode($settings, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR))) {
            $this->logger->warning('Cannot save the gain riders to {file}.', ['file' => $this->ridersFile]);
        }
    }

    private function state(): DeviceState
    {
        if (null === $this->state) {
            throw new BridgeException('' !== $this->error ? 'Device not connected: '.$this->error : 'Device not connected.', 409);
        }

        return $this->state;
    }

    private function subscribers(): int
    {
        return \count(array_filter($this->clients, static fn (array $c): bool => $c['subscribed']));
    }

    private function broadcast(string $event, mixed $data): void
    {
        foreach ($this->clients as $id => $client) {
            if ($client['subscribed']) {
                $this->event($id, $event, $data);
            }
        }
    }

    private function event(int $id, string $event, mixed $data): void
    {
        $this->send($id, ['event' => $event, 'data' => $data]);
    }

    /**
     * @param array<string, mixed> $message
     */
    private function send(int $id, array $message): void
    {
        if (!isset($this->clients[$id])) {
            return;
        }
        $this->clients[$id]['out'] .= json_encode($message, \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR)."\n";
        if (\strlen($this->clients[$id]['out']) > self::MAX_BUFFER) {
            // A client that does not read is dropped instead of growing the buffer without limit
            $this->drop($id);

            return;
        }
        $this->flush($id);
    }

    private function flush(int $id): void
    {
        if (!isset($this->clients[$id]) || '' === $this->clients[$id]['out']) {
            return;
        }
        $written = @fwrite($this->clients[$id]['stream'], $this->clients[$id]['out']);
        if (false === $written) {
            $this->drop($id);

            return;
        }
        $this->clients[$id]['out'] = substr($this->clients[$id]['out'], $written);
    }

    private function drop(int $id): void
    {
        if (isset($this->clients[$id])) {
            @fclose($this->clients[$id]['stream']);
            unset($this->clients[$id]);
            $this->statusDirty = true;
        }
    }

    private static function now(): float
    {
        return hrtime(true) / 1e9;
    }
}
