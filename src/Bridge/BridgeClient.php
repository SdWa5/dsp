<?php

namespace App\Bridge;

/**
 * Talks to a running sdwa5-dsp:bridge over its unix socket.
 *
 * Errors are BridgeException with an HTTP-like code: 503 bridge not reachable, 409 device not connected,
 * 400 request rejected, 504 no answer in time.
 */
final class BridgeClient
{
    private int $nextId = 1;

    public function __construct(
        private readonly string $socketPath,
        private readonly float $timeout = 5.0,
    ) {
    }

    public function socketPath(): string
    {
        return $this->socketPath;
    }

    /**
     * Sends one request and returns its result.
     *
     * @param array<string, mixed> $params
     */
    public function request(string $op, array $params = []): mixed
    {
        $stream = $this->open();
        try {
            $id = $this->nextId++;
            $this->writeLine($stream, ['id' => $id, 'op' => $op] + $params);
            $deadline = microtime(true) + $this->timeout;
            $buffer = '';
            while (true) {
                foreach ($this->lines($stream, $buffer, $deadline - microtime(true)) ?? [] as $message) {
                    if (($message['id'] ?? null) === $id && \array_key_exists('ok', $message)) {
                        return $this->result($message);
                    }
                }
                if (microtime(true) >= $deadline) {
                    throw new BridgeException(\sprintf('The bridge did not answer "%s" in time.', $op), 504);
                }
            }
        } finally {
            fclose($stream);
        }
    }

    /**
     * Subscribes and yields every event as ['event' => ..., 'data' => ...]. When nothing arrives for
     * $idle seconds it yields null, so the caller can send a keep-alive or stop.
     *
     * @return \Generator<int, array{event: string, data: mixed}|null>
     */
    public function events(float $idle = 15.0): \Generator
    {
        $stream = $this->open();
        try {
            $this->writeLine($stream, ['id' => 0, 'op' => 'subscribe']);
            $buffer = '';
            while (true) {
                $messages = $this->lines($stream, $buffer, $idle);
                if (null === $messages) {
                    yield null;
                    continue;
                }
                foreach ($messages as $message) {
                    if (\is_string($message['event'] ?? null)) {
                        yield ['event' => $message['event'], 'data' => $message['data'] ?? null];
                    }
                }
            }
        } finally {
            fclose($stream);
        }
    }

    /**
     * @return resource
     */
    private function open()
    {
        $stream = @stream_socket_client('unix://'.$this->socketPath, $errno, $errstr, $this->timeout);
        if (false === $stream) {
            throw new BridgeException(\sprintf('The bridge is not running (%s: %s). Start it with bin/console sdwa5-dsp:bridge.', $this->socketPath, $errstr), 503);
        }

        return $stream;
    }

    /**
     * @param resource             $stream
     * @param array<string, mixed> $message
     */
    private function writeLine($stream, array $message): void
    {
        $line = json_encode($message, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n";
        if (false === @fwrite($stream, $line)) {
            throw new BridgeException('Lost the connection to the bridge.', 503);
        }
    }

    /**
     * Waits up to $wait seconds for data and returns the complete lines received, null when nothing arrived.
     *
     * @param resource $stream
     *
     * @return list<array<mixed>>|null
     */
    private function lines($stream, string &$buffer, float $wait): ?array
    {
        $read = [$stream];
        $write = $except = null;
        $wait = max(0.0, $wait);
        if (!@stream_select($read, $write, $except, (int) $wait, (int) (($wait - (int) $wait) * 1e6))) {
            return null;
        }
        $chunk = fread($stream, 65536);
        if (false === $chunk || ('' === $chunk && feof($stream))) {
            throw new BridgeException('The bridge closed the connection.', 503);
        }
        $buffer .= $chunk;
        $messages = [];
        while (false !== $pos = strpos($buffer, "\n")) {
            $message = json_decode(substr($buffer, 0, $pos), true);
            $buffer = substr($buffer, $pos + 1);
            if (\is_array($message)) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    /**
     * @param array<mixed> $message
     */
    private function result(array $message): mixed
    {
        if (true === $message['ok']) {
            return $message['result'] ?? null;
        }
        $error = \is_string($message['error'] ?? null) ? $message['error'] : 'The bridge rejected the request.';

        throw new BridgeException($error, 'offline' === ($message['code'] ?? null) ? 409 : 400);
    }
}
