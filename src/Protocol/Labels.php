<?php

namespace App\Protocol;

/**
 * Value lists as the vendor editor shows them, indexed by the raw protocol value.
 */
final class Labels
{
    /** HPF/LPF slope, 0 = bypass. */
    public const SLOPES = [
        'Bypass', 'BW -6', 'BL -6', 'BW -12', 'BL -12', 'LK -12', 'BW -18', 'BL -18', 'BW -24', 'BL -24',
        'LK -24', 'BW -30', 'BL -30', 'BW -36', 'BL -36', 'LK -36', 'BW -42', 'BL -42', 'BW -48', 'BL -48',
        'LK -48',
    ];

    public const PEQ_TYPES = ['Peak', 'Low Shelf', 'High Shelf', 'Low Pass', 'High Pass', 'Allpass1', 'Allpass2'];

    public const COMP_RATIOS = [
        '1:1.0', '1:1.1', '1:1.3', '1:1.5', '1:1.7', '1:2.0', '1:2.5', '1:3.0', '1:3.5', '1:4.0',
        '1:5.0', '1:6.0', '1:8.0', '1:10.0', '1:20.0', 'Limit',
    ];

    public const TONE_MODES = ['Analog Input', 'Pink Noise', 'White Noise', 'Sine Wave'];

    /** Sine frequencies of the test tone, one per third octave. */
    public const TONE_FREQUENCIES = [
        '20Hz', '25Hz', '31.5Hz', '40Hz', '50Hz', '63Hz', '80Hz', '100Hz', '125Hz', '160Hz', '200Hz',
        '250Hz', '315Hz', '400Hz', '500Hz', '630Hz', '800Hz', '1kHz', '1.25kHz', '1.6kHz', '2kHz',
        '2.5kHz', '3.15kHz', '4kHz', '5kHz', '6.3kHz', '8kHz', '10kHz', '12.5kHz', '16kHz', '20kHz',
    ];

    public const AUTOMIX_SLOPES = ['-3dB', '-4dB', '-5dB', '-6dB'];

    public const INPUT_MODES = ['Line', 'Mic 0 dB', 'Mic +1 dB', 'Mic +2 dB'];

    public const FBQ_LEVELS = ['Close', 'Level 1', 'Level 2', 'Level 3', 'Level 4'];

    public const DELAY_UNITS = ['ms', 'm', 'ft'];

    public const WORKING_STATUS = ['Memorize', 'Forget'];
}
