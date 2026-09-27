<?php

namespace App\Transport;

use App\Protocol\Frame;

/**
 * USB-HID through the Linux hidraw driver (/dev/hidrawN). See udev/99-trackdsp.rules for access without root.
 *
 * The device uses no report IDs, so each write is a 0x00 report number followed by the 64-byte report,
 * and each read returns the 64-byte report. A leading report number on read is tolerated as well.
 */
final class HidrawTransport implements TransportInterface
{
    public const REPORT_SIZE = 64;

    /** @var resource */
    private $handle;

    public function __construct(private readonly string $path)
    {
        $handle = @fopen($path, 'r+b');
        if (false === $handle) {
            throw new TransportException(\sprintf('Cannot open %s. Check the udev rule and that the device is connected.', $path));
        }
        // Unbuffered: every fwrite() must be exactly one report and every fread() at most one
        stream_set_write_buffer($handle, 0);
        stream_set_read_buffer($handle, 0);
        stream_set_blocking($handle, false);
        $this->handle = $handle;
    }

    public function send(Frame $frame): void
    {
        $bytes = $frame->encode();
        if (\strlen($bytes) > self::REPORT_SIZE) {
            throw new TransportException(\sprintf('Frame too long for one HID report (%d bytes).', \strlen($bytes)));
        }
        $report = "\x00".str_pad($bytes, self::REPORT_SIZE, "\x00");
        if (@fwrite($this->handle, $report) !== \strlen($report)) {
            throw new TransportException(\sprintf('Writing to %s failed.', $this->path));
        }
    }

    public function receive(float $timeout): ?Frame
    {
        $deadline = hrtime(true) + (int) ($timeout * 1e9);
        while (($left = $deadline - hrtime(true)) > 0) {
            $read = [$this->handle];
            $write = $except = null;
            if (!@stream_select($read, $write, $except, intdiv($left, 1_000_000_000), intdiv($left % 1_000_000_000, 1000))) {
                return null;
            }
            $report = fread($this->handle, self::REPORT_SIZE + 1);
            if (false === $report) {
                throw new TransportException(\sprintf('Reading from %s failed. Was the device unplugged?', $this->path));
            }
            $frame = Frame::decode($report) ?? Frame::decode(substr($report, 1));
            if (null !== $frame) {
                return $frame;
            }
        }

        return null;
    }

    public function close(): void
    {
        if (\is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    public function describe(): string
    {
        return 'usb:'.$this->path;
    }
}
