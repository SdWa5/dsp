<?php

namespace App\Protocol;

/**
 * Reassembles frames from a byte stream (TCP), skipping garbage between frames.
 */
final class FrameBuffer
{
    private string $buffer = '';

    public function append(string $bytes): void
    {
        $this->buffer .= $bytes;
    }

    /**
     * Takes the next complete frame off the buffer, or null when none is complete yet.
     *
     * @phpstan-impure
     */
    public function next(): ?Frame
    {
        while (true) {
            $start = strpos($this->buffer, Frame::STX);
            if (false === $start) {
                // Keep a trailing 0x10, it may be the first half of the next STX
                $this->buffer = str_ends_with($this->buffer, "\x10") ? "\x10" : '';

                return null;
            }
            $this->buffer = substr($this->buffer, $start);
            if (\strlen($this->buffer) < 5) {
                return null;
            }
            $total = Frame::OVERHEAD + \ord($this->buffer[4]);
            if (\strlen($this->buffer) < $total) {
                return null;
            }
            $frame = Frame::decode(substr($this->buffer, 0, $total));
            if (null === $frame) {
                // No valid frame at this position, search on behind this STX
                $this->buffer = substr($this->buffer, 2);
                continue;
            }
            $this->buffer = substr($this->buffer, $total);

            return $frame;
        }
    }
}
