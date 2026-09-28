<?php

namespace App\Measurement;

/**
 * Level of one recorded channel in dBFS, where a full-scale sine reads 0 dBFS peak and -3.01 dBFS RMS.
 *
 * Silence reads FLOOR instead of minus infinity, so the values stay valid JSON.
 */
final readonly class Levels
{
    public const FLOOR = -200.0;
    /** A sample at or above this magnitude counts as clipped */
    public const CLIP = 0.9999;

    public function __construct(
        public float $rms,
        public float $peak,
        public int $clipped,
        public int $samples,
    ) {
    }

    /**
     * @param list<float> $samples
     */
    public static function of(array $samples): self
    {
        $sum = 0.0;
        $peak = 0.0;
        $clipped = 0;
        foreach ($samples as $x) {
            $sum += $x * $x;
            $magnitude = abs($x);
            if ($magnitude > $peak) {
                $peak = $magnitude;
            }
            if ($magnitude >= self::CLIP) {
                ++$clipped;
            }
        }
        $count = \count($samples);

        return new self(self::db(0 === $count ? 0.0 : sqrt($sum / $count)), self::db($peak), $clipped, $count);
    }

    /**
     * 20·log10 of an amplitude, FLOOR for silence.
     */
    public static function db(float $amplitude): float
    {
        return $amplitude > 0 ? max(self::FLOOR, 20 * log10($amplitude)) : self::FLOOR;
    }

    /**
     * Peak over RMS in dB: 3.01 for a sine, about 10 to 13 for pink noise.
     */
    public function crest(): float
    {
        return $this->peak - $this->rms;
    }

    /**
     * @return array{rms: float, peak: float, crest: float, clipped: int, samples: int}
     */
    public function toArray(): array
    {
        return ['rms' => round($this->rms, 2), 'peak' => round($this->peak, 2), 'crest' => round($this->crest(), 2), 'clipped' => $this->clipped, 'samples' => $this->samples];
    }
}
