<?php

namespace App\AutoEq;

/**
 * Limits of the PEQ fit. Cuts are cheap and safe, boosts cost headroom and cannot fill room nulls,
 * so boosts are smaller, broad only and aimed at the 1/3-octave smoothed error.
 */
final readonly class FitSettings
{
    /**
     * @param int   $bands       bands to use, the outputs have 7
     * @param float $low         lowest frequency to correct in Hz
     * @param float $high        highest frequency to correct in Hz
     * @param float $maxBoostDb  largest total boost at any frequency
     * @param float $maxCutDb    deepest cut of one band
     * @param float $maxQ        narrowest cut
     * @param float $maxBoostQ   narrowest boost
     * @param float $toleranceDb deviations up to this size are left alone
     */
    public function __construct(
        public int $bands = 7,
        public float $low = 60.0,
        public float $high = 16000.0,
        public float $maxBoostDb = 3.0,
        public float $maxCutDb = 9.0,
        public float $maxQ = 5.0,
        public float $maxBoostQ = 2.0,
        public float $toleranceDb = 1.0,
    ) {
        if ($bands < 1 || $bands > 7) {
            throw new \InvalidArgumentException(\sprintf('Use 1..7 bands, got %d.', $bands));
        }
        if ($low < 20 || $high > 20000 || $low >= $high) {
            throw new \InvalidArgumentException(\sprintf('The range must lie in 20..20000 Hz with low below high, got %s..%s.', $low, $high));
        }
        if ($maxBoostDb < 0 || $maxBoostDb > 12 || $maxCutDb < 0 || $maxCutDb > 12) {
            throw new \InvalidArgumentException('Boost and cut must be 0..12 dB.');
        }
        if ($maxQ < 0.5 || $maxQ > 20 || $maxBoostQ < 0.5 || $maxBoostQ > $maxQ || $toleranceDb <= 0) {
            throw new \InvalidArgumentException('Q limits must be 0.5..20 with the boost limit not above the cut limit, and the tolerance must be positive.');
        }
    }
}
