<?php

namespace App\Filter;

/**
 * Where a Bessel filter puts its corner frequency. The three are the usual choices, as in SciPy's bessel().
 * The 8x8 uses the magnitude norm at every BL slope, while the other two miss its loopback by 4 dB and more
 * from BL -12 on (docs/protocol.md).
 */
enum BesselNorm: string
{
    /** The norm of the 8x8, like the -3 dB corner of the Butterworth slopes */
    public const DEFAULT = self::Magnitude;

    /** The phase follows a Butterworth of the same order far from the corner, and -3 dB lies below it */
    case Phase = 'phase';

    /** The group delay at 0 Hz is 1 / (2π·fc), and -3 dB lies above the corner */
    case Delay = 'delay';

    /** The magnitude is -3 dB at the corner */
    case Magnitude = 'magnitude';
}
