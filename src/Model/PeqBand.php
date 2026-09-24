<?php

namespace App\Model;

final class PeqBand
{
    /**
     * @param int  $gain   raw, 120 = 0 dB
     * @param int  $freq   frequency index 0..300
     * @param int  $q      Q index, 25 = 1.70
     * @param int  $type   index into Labels::PEQ_TYPES
     * @param bool $bypass from the footer bypass masks
     */
    public function __construct(
        public int $gain = 120,
        public int $freq = 100,
        public int $q = 25,
        public int $type = 0,
        public bool $bypass = false,
    ) {
    }

    public static function parse(string $bytes, int $offset): self
    {
        return new self(Bytes::u16($bytes, $offset), Bytes::u16($bytes, $offset + 2), Bytes::u8($bytes, $offset + 4), Bytes::u8($bytes, $offset + 5));
    }

    /**
     * @return array{gain: int, freq: int, q: int, type: int, bypass: bool}
     */
    public function toArray(): array
    {
        return ['gain' => $this->gain, 'freq' => $this->freq, 'q' => $this->q, 'type' => $this->type, 'bypass' => $this->bypass];
    }
}
