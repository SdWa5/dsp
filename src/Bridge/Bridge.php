<?php

namespace App\Bridge;

use App\Automation\GainRider;
use App\Automation\RiderSettings;
use App\Client\DspException;
use App\Client\Matrix;
use App\Model\DeviceState;
use App\Model\InputChannel;
use App\Model\Links;
use App\Protocol\Commands;
use App\Protocol\Frame;
use App\Protocol\Opcode;
use App\Protocol\Scale;
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
 * The device is read again in steps of one request, which run between client requests while the queue is
 * empty (see Resync). Only the first read after a connect reads everything at once.
 *
 * Gain riders run inside the bridge, so they keep working without a browser. They read the meters, which are
 * polled while a client listens or a rider runs, and write the input gain like any other client. Their
 * settings are kept in $ridersFile and come back after a restart. Riders belong to a preset as well: storing a
 * user preset keeps the running riders for its slot, recalling it brings back exactly those, and the factory
 * default (F00) or a slot stored without riders runs with none.
 *
 * Every connect and disconnect op is kept in $targetFile. With $restoreTarget the bridge starts on that target,
 * connected or not as it was left, instead of on the one it was given. A restart therefore does not drop the
 * editor's choice and fall back to the simulated device, which the status reports as "demo" while it is the target.
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
    private ?Resync $resync = null;
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
    /** Rider ticks stay suspended while guarded replay frames are being drained. */
    private bool $historyDraining = false;
    /** @var array<int, list<array<string, mixed>>> user preset slot 1..20 => settings of the riders stored with it */
    private array $presetRiders = [];

    public function __construct(
        private readonly TransportFactory $transports,
        private readonly string $socketPath,
        private string $target,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly float $meterInterval = 0.3,
        private readonly float $publishInterval = 0.2,
        private readonly float $resyncInterval = 60.0,
        private readonly ?string $ridersFile = null,
        private readonly ?string $targetFile = null,
        private readonly bool $restoreTarget = false,
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
        $this->loadTarget();
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
        // A running re-read goes on at once when no client has anything to say
        $this->io(null === $this->resync ? $maxWait : 0.0);
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        return [
            'connected' => null !== $this->dsp,
            'target' => $this->target,
            'demo' => 'demo' === $this->target,
            'transport' => $this->dsp?->transport()->describe(),
            'info' => $this->info,
            'error' => $this->error,
            'queued' => $this->queue->count(),
            'resync' => null === $this->resync ? null : round($this->resync->progress(), 2),
            'clients' => \count($this->clients),
            'riders' => $this->riderSettings(),
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
                    // The whole configuration changed, a running re-read starts over
                    $this->resync = null;
                    $this->resyncPending = true;
                } else {
                    $this->resync?->written($frame);
                }
                $this->statusDirty = true;
            }
            if (0 === $this->queue->count()) {
                $this->resyncStep($dsp, $now);
            }
            if ($now >= $this->nextMeter && ($this->subscribers() > 0 || [] !== $this->riders)) {
                $this->nextMeter = $now + $this->meterInterval;
                $raw = $dsp->metersRaw();
                $levels = Matrix::parseMeters($raw);
                $this->ride($levels, self::now());
                if ($this->subscribers() > 0) {
                    $this->broadcast('meters', ['levels' => $levels, 'limiting' => Matrix::parseLimiting($raw), 'raw' => bin2hex($raw), 'riders' => $this->riderStatus(self::now())]);
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
            $this->resyncPending = false;
            $this->resync = new Resync();
            while (!$this->resync->step($this->dsp)) {
            }
            $this->finishResync();
            $this->failures = 0;
            $this->error = '';
            $this->logger->info('Connected to {target}: {info}.', ['target' => $this->target, 'info' => $this->info]);
        } catch (DspException|TransportException|\InvalidArgumentException $e) {
            $this->fail($e->getMessage());
        }
        $this->statusDirty = true;
    }

    /**
     * Starts a re-read when one is due and sends its next request. Values the device does not report are kept.
     */
    private function resyncStep(Matrix $dsp, float $now): void
    {
        if (null === $this->resync) {
            if (!$this->resyncPending && $now < $this->nextResync) {
                return;
            }
            // Cleared at the start, so a refresh asked for while this one runs gets one of its own afterwards
            $this->resyncPending = false;
            $this->resync = new Resync();
            $this->statusDirty = true;
        }
        if ($this->resync->step($dsp)) {
            $this->finishResync();
        }
    }

    private function finishResync(): void
    {
        \assert(null !== $this->resync);
        $this->state = $this->resync->applyTo($this->state, null === $this->state ? $this->ipAddress() : '');
        $this->resync = null;
        $this->nextResync = self::now() + $this->resyncInterval;
        $this->stateDirty = true;
        $this->statusDirty = true;
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
        $this->resync = null;
        $this->historyDraining = false;
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
            case 'history':
                return $this->history($request);
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
                $this->saveTarget();

                return $this->status();
            case 'disconnect':
                $this->disconnect();
                $this->wanted = false;
                $this->error = '';
                $this->saveTarget();

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
                // A rider on a linked input is the rider of its link group, so every member gets the same settings
                try {
                    foreach ($this->inputLinks()->members($channel) as $member) {
                        $this->riders[$member] = new GainRider(RiderSettings::fromArray($member, $settings));
                    }
                } catch (\InvalidArgumentException $e) {
                    throw new BridgeException($e->getMessage(), 400, $e);
                }
                ksort($this->riders);
                $this->saveRiders();
                $this->statusDirty = true;
                $this->logger->info('Gain rider on {channel}: {settings}', ['channel' => $channel, 'settings' => json_encode($this->riders[$channel]->settings()->toArray())]);

                return $this->status();
            case 'riders':
                // Replaces every rider, as a preset file brings its own: [{"channel": 0, "target": -18, ...}, ...]
                $list = $request['riders'] ?? null;
                if (!\is_array($list) || !array_is_list($list)) {
                    throw new BridgeException('"riders" must be a list of rider settings.', 400);
                }
                $riders = [];
                foreach ($list as $settings) {
                    $channel = \is_array($settings) ? $settings['channel'] ?? null : null;
                    if (!\is_int($channel) || $channel < 0 || $channel > 7) {
                        throw new BridgeException('Every rider needs a "channel", an input index 0..7.', 400);
                    }
                    try {
                        $riders[$channel] = new GainRider(RiderSettings::fromArray($channel, $settings));
                    } catch (\InvalidArgumentException $e) {
                        throw new BridgeException($e->getMessage(), 400, $e);
                    }
                }
                ksort($riders);
                $this->riders = $riders;
                $this->saveRiders();
                $this->statusDirty = true;

                return $this->status();
            case 'unride':
                foreach ($this->inputLinks()->members(self::riderChannel($request)) as $member) {
                    unset($this->riders[$member]);
                }
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
     * Validates the entire replay, including rider and link preconditions, before changing anything.
     *
     * @param array<string, mixed> $request
     *
     * @return array{queued: int}
     */
    private function history(array $request): array
    {
        $state = $this->state()->toDevice()->toArray();
        if (!\is_array($request['changes'] ?? null) || !\is_array($request['guards'] ?? [])) {
            throw new BridgeException('Invalid history patch.', 400);
        }
        if ($this->queue->count() > 0 || null !== $this->resync || $this->resyncPending) {
            throw new BridgeException('History is waiting for device writes.', 409);
        }
        $frames = HistoryPatch::frames($state, $request['changes'], $request['guards'] ?? []);
        $riders = null;
        if (isset($request['riders'])) {
            $riders = [];
            foreach (HistoryRiders::prepare($this->riderSettings(), $request['riders']) as $settings) {
                $riders[$settings['channel']] = new GainRider(RiderSettings::fromArray($settings['channel'], $settings));
            }
        }
        // One bridge dispatch prevents another client or rider tick from interleaving validation.
        if (null !== $riders) {
            $this->riders = $riders;
            $this->saveRiders();
        }
        if ([] !== $frames) {
            $this->historyDraining = true;
            $this->write(array_map(static fn (Frame $frame): string => bin2hex(\chr($frame->cmd).$frame->data), $frames));
        }
        $this->statusDirty = true;

        return ['queued' => $this->queue->count()];
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
            $slot = \ord($frame->data[0] ?? "\x00");
            if (Opcode::StorePreset === $frame->opcode() && $slot >= 1 && $slot <= 20) {
                $this->presetRiders[$slot] = $this->riderSettings();
                $this->saveRiders();
            } elseif (Opcode::LoadPreset === $frame->opcode()) {
                $this->recallRiders($slot);
            }
        }
        $this->stateDirty = true;
        $this->statusDirty = true;

        return ['queued' => $this->queue->count()];
    }

    /**
     * Feeds one meter reading to every rider and queues the gains they change. A rider on a linked input rides
     * its whole link group (riderGroups()).
     *
     * @param list<float> $levels
     */
    private function ride(array $levels, float $now): void
    {
        if ($this->historyDraining) {
            if ($this->queue->count() > 0) {
                return;
            }
            $this->historyDraining = false;
        }
        if ([] === $this->riders || null === $this->state) {
            return;
        }
        $state = $this->state;
        $this->syncRiders();
        $inputs = $state->toDevice()->inputs;
        $frames = [];
        foreach ($this->riderGroups() as $channel => $members) {
            $rider = $this->riders[$channel];
            if ([] === $members) {
                $rider->reset();
                continue;
            }
            $gains = [];
            foreach ($members as $member) {
                $gains[$member] = $inputs[$member]->gain;
            }
            $rider->observeGains($gains, $now);
            $rider->meter(GainRider::groupLevel(array_map(static fn (int $m): float => $levels[$m] ?? Scale::METER_FLOOR, $members)), $now);
            foreach ($rider->writes($now) as $member => $raw) {
                $frames[] = Commands::gain($member, $raw);
            }
        }
        foreach ($frames as $frame) {
            $state->apply($frame);
            $this->queue->push($frame);
        }
        if ([] !== $frames) {
            $this->stateDirty = true;
        }
    }

    /**
     * Gives every member of a ridden link group the settings of the rider on its lowest input, for a group linked
     * after its riders were started. Unlinked again, the members keep riding with those settings.
     */
    private function syncRiders(): void
    {
        $changed = false;
        foreach ($this->riderGroups() as $channel => $members) {
            $settings = $this->riders[$channel]->settings()->toArray();
            unset($settings['channel']);
            foreach (array_slice($members, 1) as $member) {
                $own = isset($this->riders[$member]) ? $this->riders[$member]->settings()->toArray() : null;
                unset($own['channel']);
                if ($own !== $settings) {
                    $this->riders[$member] = new GainRider(RiderSettings::fromArray($member, $settings));
                    $changed = true;
                }
            }
        }
        if ($changed) {
            ksort($this->riders);
            $this->saveRiders();
            $this->statusDirty = true;
        }
    }

    /**
     * The links of the inputs from the device state, none before the first state.
     */
    private function inputLinks(): Links
    {
        return new Links(null === $this->state ? [] : array_map(static fn (InputChannel $c): int => $c->link, $this->state->toDevice()->inputs));
    }

    /**
     * The inputs each rider rides, rider channel => channels, its own first: its link group, or itself alone. Where
     * two riders share a link group, the one on the lowest channel rides it and the others ride none, so they never
     * take each other's writes for manual changes.
     *
     * @return array<int, list<int>>
     */
    private function riderGroups(): array
    {
        $links = $this->inputLinks();
        $groups = [];
        $ridden = [];
        foreach (array_keys($this->riders) as $channel) {
            if (isset($ridden[$channel])) {
                $groups[$channel] = [];
                continue;
            }
            $members = array_values(array_unique([$channel, ...$links->members($channel)]));
            $groups[$channel] = $members;
            $ridden += array_fill_keys($members, true);
        }

        return $groups;
    }

    /**
     * @return list<array{channel: int, gain: float|null, average: float|null, status: string, members: list<int>, lead?: int}>
     */
    private function riderStatus(float $now): array
    {
        $groups = $this->riderGroups();
        $status = [];
        foreach ($this->riders as $channel => $rider) {
            $members = $groups[$channel] ?? [$channel];
            if ([] === $members) {
                $lead = array_key_first(array_filter($groups, static fn (array $m): bool => \in_array($channel, $m, true))) ?? $channel;
                $status[] = ['channel' => $channel, 'gain' => null, 'average' => null, 'status' => 'linked', 'members' => [], 'lead' => $lead];
                continue;
            }
            $gain = $rider->gainDb();
            $average = $rider->averageDb();
            $status[] = [
                'channel' => $channel,
                'gain' => null === $gain ? null : round($gain, 1),
                'average' => null === $average ? null : round($average, 1),
                'status' => $rider->status($now),
                'members' => $members,
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

    /**
     * The riders of a preset recall: F00 (slot 0) stops them all, a user preset brings back the riders stored with
     * it, none for a slot stored without riders or before riders were kept per preset. The new riders take the
     * preset's gains as they come, without a pause.
     */
    private function recallRiders(int $slot): void
    {
        $settings = $slot >= 1 && $slot <= 20 ? ($this->presetRiders[$slot] ?? []) : [];
        if ([] === $this->riders && [] === $settings) {
            return;
        }
        $this->riders = $this->ridersFrom($settings);
        $this->saveRiders();
        $this->statusDirty = true;
        $this->logger->info('Preset {slot} recalled with {count} gain rider(s).', ['slot' => 0 === $slot ? 'F00' : \sprintf('U%02d', $slot), 'count' => \count($this->riders)]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function riderSettings(): array
    {
        return array_values(array_map(static fn (GainRider $r): array => $r->settings()->toArray(), $this->riders));
    }

    /**
     * Riders from saved settings, input channel => rider. Settings that are not valid are skipped with a warning.
     *
     * @return array<int, GainRider>
     */
    private function ridersFrom(mixed $list): array
    {
        $riders = [];
        foreach (\is_array($list) ? $list : [] as $settings) {
            try {
                if (!\is_array($settings) || !\is_int($settings['channel'] ?? null)) {
                    throw new \InvalidArgumentException('no channel');
                }
                $riders[$settings['channel']] = new GainRider(RiderSettings::fromArray($settings['channel'], $settings));
            } catch (\InvalidArgumentException $e) {
                $this->logger->warning('Skipped a saved gain rider in {file}: {message}', ['file' => $this->ridersFile, 'message' => $e->getMessage()]);
            }
        }
        ksort($riders);

        return $riders;
    }

    /**
     * Reads $ridersFile: {"riders": [...], "presets": {"5": [...]}}, or a plain list of riders as written before
     * riders were kept per preset.
     */
    private function loadRiders(): void
    {
        if (null === $this->ridersFile || !is_file($this->ridersFile)) {
            return;
        }
        $saved = json_decode((string) file_get_contents($this->ridersFile), true);
        $saved = \is_array($saved) && !array_is_list($saved) ? $saved : ['riders' => $saved];
        $this->riders = $this->ridersFrom($saved['riders'] ?? []);
        foreach (\is_array($saved['presets'] ?? null) ? $saved['presets'] : [] as $slot => $settings) {
            if (is_numeric($slot) && (int) $slot >= 1 && (int) $slot <= 20) {
                $this->presetRiders[(int) $slot] = array_values(array_map(fn (GainRider $r): array => $r->settings()->toArray(), $this->ridersFrom($settings)));
            }
        }
        if ([] !== $this->riders) {
            $this->logger->info('Restored {count} gain rider(s) from {file}.', ['count' => \count($this->riders), 'file' => $this->ridersFile]);
        }
    }

    private function saveRiders(): void
    {
        if (null === $this->ridersFile) {
            return;
        }
        ksort($this->presetRiders);
        $saved = ['riders' => $this->riderSettings(), 'presets' => (object) $this->presetRiders];
        if (false === @file_put_contents($this->ridersFile, json_encode($saved, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR))) {
            $this->logger->warning('Cannot save the gain riders to {file}.', ['file' => $this->ridersFile]);
        }
    }

    /**
     * Takes the target and the connection wish of the last connect or disconnect op, see $restoreTarget.
     * A file that cannot be read leaves the given target in place.
     */
    private function loadTarget(): void
    {
        if (!$this->restoreTarget || null === $this->targetFile || !is_file($this->targetFile)) {
            return;
        }
        $saved = json_decode((string) file_get_contents($this->targetFile), true);
        $target = \is_array($saved) && \is_string($saved['target'] ?? null) ? trim($saved['target']) : '';
        if ('' === $target || !\is_bool($saved['connect'] ?? null)) {
            $this->logger->warning('Ignored the saved target in {file}, it has no target and connect flag.', ['file' => $this->targetFile]);

            return;
        }
        $this->target = $target;
        $this->wanted = $saved['connect'];
        $this->logger->info('Restored target {target} ({wish}) from {file}.', ['target' => $target, 'wish' => $this->wanted ? 'connect' : 'stay disconnected', 'file' => $this->targetFile]);
    }

    private function saveTarget(): void
    {
        if (null === $this->targetFile) {
            return;
        }
        $saved = json_encode(['target' => $this->target, 'connect' => $this->wanted], \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR);
        if (false === @file_put_contents($this->targetFile, $saved)) {
            $this->logger->warning('Cannot save the target to {file}.', ['file' => $this->targetFile]);
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
