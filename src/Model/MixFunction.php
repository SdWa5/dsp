<?php

namespace App\Model;

/**
 * AutoMix or Camera function of the Aux (command 0x64).
 */
final class MixFunction
{
    /**
     * @param int $attack    ms = raw + 1
     * @param int $release   ms = raw + 1
     * @param int $threshold dB = raw / 2 - 90
     * @param int $select    input mask
     */
    public function __construct(
        public int $attack = 99,
        public int $release = 2999,
        public int $threshold = 180,
        public int $select = 0,
        public bool $on = false,
    ) {
    }

    public static function parse(string $bytes, int $offset): self
    {
        return new self(
            Bytes::u16($bytes, $offset),
            Bytes::u16($bytes, $offset + 2),
            Bytes::u16($bytes, $offset + 4),
            Bytes::u16($bytes, $offset + 6),
            0 !== Bytes::u16($bytes, $offset + 8),
        );
    }

    /**
     * @return array{attack: int, release: int, threshold: int, select: int, on: bool}
     */
    public function toArray(): array
    {
        return ['attack' => $this->attack, 'release' => $this->release, 'threshold' => $this->threshold, 'select' => $this->select, 'on' => $this->on];
    }
}
