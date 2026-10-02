<?php

namespace App\Model;

final class Compressor
{
    /**
     * @param int $ratio     index into Labels::COMP_RATIOS
     * @param int $attack    ms = raw + 1
     * @param int $release   ms = raw + 1
     * @param int $threshold dB = raw / 2 - 90
     */
    public function __construct(
        public int $ratio = 0,
        public int $knee = 0,
        public int $attack = 49,
        public int $release = 199,
        public int $threshold = 220,
    ) {
    }

    public static function parse(string $bytes, int $offset): self
    {
        return new self(
            Bytes::u8($bytes, $offset),
            Bytes::u8($bytes, $offset + 1),
            Bytes::u16($bytes, $offset + 2),
            Bytes::u16($bytes, $offset + 4),
            Bytes::u16($bytes, $offset + 6),
        );
    }

    /**
     * Whether the compressor can reduce the gain at all. 1:1.0 never compresses, and from +20 dB it never works
     * below the output ceiling (docs/protocol.md). Mirrors compActive() in assets/lib/meters.js.
     */
    public function works(): bool
    {
        return 0 !== $this->ratio && $this->threshold < 220;
    }

    /**
     * @return array{ratio: int, knee: int, attack: int, release: int, threshold: int}
     */
    public function toArray(): array
    {
        return ['ratio' => $this->ratio, 'knee' => $this->knee, 'attack' => $this->attack, 'release' => $this->release, 'threshold' => $this->threshold];
    }
}
