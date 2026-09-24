<?php

namespace App\Transport;

use App\Protocol\Frame;

/**
 * Sends and receives whole frames. USB wraps each frame in a 64-byte report, TCP is a byte stream.
 */
interface TransportInterface
{
    public function send(Frame $frame): void;

    /**
     * Reads the next valid frame, or returns null when $timeout seconds pass without one.
     *
     * @phpstan-impure
     */
    public function receive(float $timeout): ?Frame;

    public function close(): void;

    /**
     * Short description for logs and the UI, e.g. "tcp://192.168.68.10:9761" or "usb:/dev/hidraw3".
     */
    public function describe(): string;
}
