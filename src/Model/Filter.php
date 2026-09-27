<?php

namespace App\Model;

final class Filter
{
    /**
     * @param int $freq  frequency index 0..300
     * @param int $slope 0 = bypass, 1..20 = Labels::SLOPES
     */
    public function __construct(
        public int $freq = 0,
        public int $slope = 0,
    ) {
    }

    /**
     * @return array{freq: int, slope: int}
     */
    public function toArray(): array
    {
        return ['freq' => $this->freq, 'slope' => $this->slope];
    }
}
