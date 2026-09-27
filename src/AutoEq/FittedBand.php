<?php

namespace App\AutoEq;

use App\Protocol\Scale;

/**
 * One peaking PEQ band in raw device values, see Scale for the units.
 */
final readonly class FittedBand
{
    /**
     * @param int $freq frequency index 0..300
     * @param int $gain PEQ gain raw 0..240, 120 = 0 dB
     * @param int $q    Q index 0..100
     */
    public function __construct(
        public int $freq,
        public int $gain,
        public int $q,
    ) {
    }

    public function hz(): float
    {
        return Scale::indexToHz($this->freq);
    }

    public function db(): float
    {
        return Scale::peqGainToDb($this->gain);
    }

    public function qValue(): float
    {
        return Scale::indexToQ($this->q);
    }

    /**
     * Magnitude in dB at $hz of an RBJ cookbook peaking filter at 48 kHz. The 8x8's own filter shape is
     * not documented, so the verification measurement is what counts.
     */
    public function responseAt(float $hz): float
    {
        $db = $this->db();
        if (0.0 === $db) {
            return 0.0;
        }
        $a = 10 ** ($db / 40);
        $w0 = 2 * M_PI * $this->hz() / Scale::SAMPLE_RATE;
        $alpha = sin($w0) / (2 * $this->qValue());
        $cw = cos($w0);
        [$b0, $b1, $b2] = [1 + $alpha * $a, -2 * $cw, 1 - $alpha * $a];
        [$a0, $a1, $a2] = [1 + $alpha / $a, -2 * $cw, 1 - $alpha / $a];
        $w = 2 * M_PI * $hz / Scale::SAMPLE_RATE;
        [$c1, $s1, $c2, $s2] = [cos($w), sin($w), cos(2 * $w), sin(2 * $w)];
        $num = ($b0 + $b1 * $c1 + $b2 * $c2) ** 2 + ($b1 * $s1 + $b2 * $s2) ** 2;
        $den = ($a0 + $a1 * $c1 + $a2 * $c2) ** 2 + ($a1 * $s1 + $a2 * $s2) ** 2;

        return 10 * log10($num / $den);
    }

    public function with(?int $freq = null, ?int $gain = null, ?int $q = null): self
    {
        return new self($freq ?? $this->freq, $gain ?? $this->gain, $q ?? $this->q);
    }

    /**
     * @return array{freq: int, gain: int, q: int, hz: float, db: float, q_value: float}
     */
    public function toArray(): array
    {
        return ['freq' => $this->freq, 'gain' => $this->gain, 'q' => $this->q, 'hz' => round($this->hz(), 1), 'db' => $this->db(), 'q_value' => round($this->qValue(), 2)];
    }
}
