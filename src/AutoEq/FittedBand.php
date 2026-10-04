<?php

namespace App\AutoEq;

use App\Filter\Biquad;
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
     * The band as the 8x8's peaking filter at 48 kHz (see Biquad).
     */
    public function biquad(): Biquad
    {
        return Biquad::peq(0, $this->hz(), $this->db(), $this->qValue());
    }

    /**
     * Magnitude in dB at $hz.
     */
    public function responseAt(float $hz): float
    {
        return Scale::PEQ_GAIN_UNITY === $this->gain ? 0.0 : $this->biquad()->db($hz);
    }

    /**
     * Phase in degrees at $hz, -180..180. A boost leads below the centre frequency and lags above it, a cut the
     * other way round.
     */
    public function phaseAt(float $hz): float
    {
        return Scale::PEQ_GAIN_UNITY === $this->gain ? 0.0 : $this->biquad()->degrees($hz);
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
