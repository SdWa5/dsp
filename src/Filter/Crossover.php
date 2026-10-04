<?php

namespace App\Filter;

use App\Protocol\Labels;
use App\Protocol\Scale;

/**
 * An HPF or LPF slope of Labels::SLOPES, as the analog prototype through the bilinear transform with the
 * corner prewarped. That maps the digital frequency f to the prototype at tan(π·f / rate) / tan(π·fc / rate).
 * The high pass is the low pass prototype at 1 / s. The loopback walk of 2026-09-30 fits the 8x8 this way within
 * 0.12 dB and 1° at every slope.
 */
final readonly class Crossover implements Section
{
    public string $family;
    public int $order;

    /** @var list<array{float, float}> */
    private array $poles;

    /**
     * @param int $slope 0 = bypass, 1..20 = Labels::SLOPES
     */
    public function __construct(
        public int $slope,
        public float $hz,
        public bool $high,
        public int $rate = Scale::SAMPLE_RATE,
        public BesselNorm $bessel = BesselNorm::DEFAULT,
    ) {
        if (!isset(Labels::SLOPES[$slope])) {
            throw new \OutOfRangeException(\sprintf('Slope %d is not one of 0..%d.', $slope, \count(Labels::SLOPES) - 1));
        }
        if (0 === $slope) {
            [$this->family, $this->order, $this->poles] = ['', 0, []];

            return;
        }
        [$family, $db] = explode(' ', Labels::SLOPES[$slope]);
        \assert('BW' === $family || 'BL' === $family || 'LK' === $family);
        $this->family = $family;
        $this->order = intdiv(-(int) $db, 6);
        $this->poles = Prototype::poles($family, $this->order, $bessel);
    }

    public function at(float $hz): array
    {
        if (0 === $this->order) {
            return [1.0, 0.0];
        }
        if ($hz <= 0) {
            return $this->high ? [0.0, 0.0] : [1.0, 0.0];
        }
        $omega = tan(M_PI * min($hz, $this->rate / 2 * 0.999999) / $this->rate) / tan(M_PI * $this->hz / $this->rate);

        return Prototype::lowPass($this->poles, $this->high ? [0.0, -1 / $omega] : [0.0, $omega]);
    }

    public function db(float $hz): float
    {
        return Complex::db($this->at($hz));
    }

    public function degrees(float $hz): float
    {
        return Complex::degrees($this->at($hz));
    }
}
