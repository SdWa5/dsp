<?php

namespace App\Transport;

use App\Protocol\Frame;
use App\Protocol\FrameBuffer;

/**
 * TCP connection to the network port of the device (port 9761).
 */
final class TcpTransport implements TransportInterface
{
    public const DEFAULT_PORT = 9761;

    private FrameBuffer $buffer;

    /**
     * @param resource $stream
     */
    private function __construct(private $stream, private readonly string $description)
    {
        stream_set_blocking($this->stream, false);
        $this->buffer = new FrameBuffer();
    }

    public static function connect(string $host, int $port = self::DEFAULT_PORT, float $connectTimeout = 3.0): self
    {
        $address = \sprintf('tcp://%s:%d', $host, $port);
        $stream = @stream_socket_client($address, $errno, $error, $connectTimeout);
        if (false === $stream) {
            // The device ignores new connections while all of its connections are in use
            $hint = 110 === $errno ? ' The device does not accept a new connection while all of them are in use, for example by the vendor editor. Close it and try again.' : '';

            throw new TransportException(\sprintf('Cannot connect to %s: %s (%d).%s', $address, $error, $errno, $hint));
        }

        return new self($stream, $address);
    }

    /**
     * Wraps an already open stream, e.g. one end of stream_socket_pair() in tests.
     *
     * @param resource $stream
     */
    public static function fromStream($stream, string $description = 'stream'): self
    {
        return new self($stream, $description);
    }

    public function send(Frame $frame): void
    {
        $bytes = $frame->encode();
        stream_set_blocking($this->stream, true);
        try {
            $written = @fwrite($this->stream, $bytes);
        } finally {
            stream_set_blocking($this->stream, false);
        }
        if ($written !== \strlen($bytes)) {
            throw new TransportException(\sprintf('Writing to %s failed.', $this->description));
        }
    }

    public function receive(float $timeout): ?Frame
    {
        $deadline = hrtime(true) + (int) ($timeout * 1e9);
        while (true) {
            $frame = $this->buffer->next();
            if (null !== $frame) {
                return $frame;
            }
            $left = $deadline - hrtime(true);
            if ($left <= 0) {
                return null;
            }
            $read = [$this->stream];
            $write = $except = null;
            if (!@stream_select($read, $write, $except, intdiv($left, 1_000_000_000), intdiv($left % 1_000_000_000, 1000))) {
                return null;
            }
            $chunk = fread($this->stream, 4096);
            if (false === $chunk || ('' === $chunk && feof($this->stream))) {
                throw new TransportException(\sprintf('Connection to %s closed by the device.', $this->description));
            }
            $this->buffer->append($chunk);
        }
    }

    public function close(): void
    {
        if (\is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    public function describe(): string
    {
        return $this->description;
    }
}
