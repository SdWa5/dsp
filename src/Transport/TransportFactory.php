<?php

namespace App\Transport;

use App\Client\Matrix;

/**
 * Opens a transport from a target string: "usb" (first hidraw device), "usb:/dev/hidrawN",
 * "demo", or "host" / "host:port" for TCP.
 */
final readonly class TransportFactory
{
    public function __construct(
        private HidrawLocator $locator,
        private string $demoConfigPath,
        private float $timeout = 1.0,
    ) {
    }

    public function open(string $target): TransportInterface
    {
        $target = trim($target);

        return match (true) {
            '' === $target, 'usb' === $target => new HidrawTransport($this->locator->first()),
            str_starts_with($target, 'usb:') => new HidrawTransport(substr($target, 4)),
            'demo' === $target => DemoTransport::fromFile($this->demoConfigPath),
            default => $this->tcp($target),
        };
    }

    /**
     * Opens the transport and starts the session.
     */
    public function connect(string $target): Matrix
    {
        $matrix = new Matrix($this->open($target), $this->timeout);
        $matrix->handshake();

        return $matrix;
    }

    private function tcp(string $target): TcpTransport
    {
        if (!preg_match('/^(\[[^\]]+\]|[^:]+)(?::(\d+))?$/', $target, $m)) {
            throw new \InvalidArgumentException(\sprintf('Invalid target "%s" (expected usb, demo, host or host:port).', $target));
        }

        return TcpTransport::connect(trim($m[1], '[]'), isset($m[2]) ? (int) $m[2] : TcpTransport::DEFAULT_PORT);
    }
}
