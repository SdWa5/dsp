<?php

namespace App\Measurement;

/**
 * Polarity of a transfer function, read from the phase of its analytic impulse response at the peak.
 */
enum Polarity: string
{
    case Normal = 'normal';
    case Inverted = 'inverted';
    case Unclear = 'unclear';

    /**
     * Normal within ±45° of 0°, inverted within ±45° of 180°, unclear in between or without a peak.
     */
    public static function fromPhase(?float $degrees): self
    {
        if (null === $degrees) {
            return self::Unclear;
        }
        $fromZero = abs(fmod(fmod($degrees, 360) + 540, 360) - 180);

        return match (true) {
            $fromZero < 45 => self::Normal,
            $fromZero > 135 => self::Inverted,
            default => self::Unclear,
        };
    }
}
