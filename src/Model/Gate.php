<?php

namespace App\Model;

final class Gate
{
    /**
     * @param int $attack    ms = raw + 1
     * @param int $release   ms = raw + 1
     * @param int $threshold dB = raw / 2 - 90
     */
    public function __construct(
        public int $attack = 0,
        public int $release = 2999,
        public int $threshold = 0,
    ) {
    }

    /**
     * @return array{attack: int, release: int, threshold: int}
     */
    public function toArray(): array
    {
        return ['attack' => $this->attack, 'release' => $this->release, 'threshold' => $this->threshold];
    }
}
