<?php

namespace App\Transport;

use App\Client\Matrix;
use App\Discovery\Discovery;

/**
 * Opens a transport from a target string: "usb" (first hidraw device), "usb:/dev/hidrawN",
 * "demo", "host" / "host:port" for TCP, or "auto" for the first device that discovery finds.
 */
final readonly class TransportFactory
{
    /**
     * USB replies come within about 10 ms, but the 8x8 loses one request every 0.2 to 0.3 s of continuous
     * traffic (measured on the VIM3, 2026-09-28). The next attempt always got its reply, so a short wait with
     * more attempts costs about 0.15 s per lost request instead of the full timeout.
     */
    public const USB_TIMEOUT = 0.15;
    public const USB_RETRIES = 4;

    public function __construct(
        private HidrawLocator $locator,
        private string $demoConfigPath,
        private float $timeout = 1.0,
        private ?Discovery $discovery = null,
    ) {
    }

    public function open(string $target): TransportInterface
    {
        $target = trim($target);

        return match (true) {
            '' === $target, 'usb' === $target => new HidrawTransport($this->locator->first()),
            str_starts_with($target, 'usb:') => new HidrawTransport(substr($target, 4)),
            'demo' === $target => DemoTransport::fromFile($this->demoConfigPath),
            'auto' === $target => $this->open($this->discover()),
            default => $this->tcp($target),
        };
    }

    /**
     * Opens the transport and starts the session.
     */
    public function connect(string $target): Matrix
    {
        $transport = $this->open($target);
        $matrix = $transport instanceof HidrawTransport
            ? new Matrix($transport, min($this->timeout, self::USB_TIMEOUT), self::USB_RETRIES)
            : new Matrix($transport, $this->timeout);
        $matrix->handshake();

        return $matrix;
    }

    /**
     * The target of the first device found: USB before the network.
     */
    public function discover(): string
    {
        if (null === $this->discovery) {
            throw new TransportException('Discovery is not available.');
        }
        $found = $this->discovery->scan()[0] ?? null;
        if (null === $found) {
            $networks = implode(', ', array_map(strval(...), $this->discovery->subnets())) ?: 'no network';

            throw new TransportException(\sprintf('No DSP found on USB or on %s.', $networks));
        }

        return $found['target'];
    }

    private function tcp(string $target): TcpTransport
    {
        if (!preg_match('/^(\[[^\]]+\]|[^:]+)(?::(\d+))?$/', $target, $m)) {
            throw new \InvalidArgumentException(\sprintf('Invalid target "%s" (expected usb, demo, host or host:port).', $target));
        }

        return TcpTransport::connect(trim($m[1], '[]'), isset($m[2]) ? (int) $m[2] : TcpTransport::DEFAULT_PORT);
    }
}
