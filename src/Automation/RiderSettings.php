<?php

namespace App\Automation;

use App\Protocol\Channel;

/**
 * Settings of the gain rider. Levels are meter levels in the approximated dB scale of Scale::meterDb(),
 * so the target is relative until the meters are calibrated. Gains are channel gains in dB.
 */
final readonly class RiderSettings
{
    /**
     * @param int   $channel             input channel index 0..7
     * @param float $targetDb            meter level the rider aims for
     * @param float $minGainDb           lowest gain the rider sets
     * @param float $maxGainDb           highest gain the rider sets, above 0 dB only with $allowBoost
     * @param float $slewDbPerSecond     fastest gain change
     * @param float $averageSeconds      time constant of the level average
     * @param float $silenceDb           below this level the rider holds the gain
     * @param float $deadbandDb          deviations up to this size are left alone
     * @param float $overrideHoldSeconds pause after someone else changed the gain
     */
    public function __construct(
        public int $channel,
        public float $targetDb = -18.0,
        public float $minGainDb = -20.0,
        public float $maxGainDb = 0.0,
        public float $slewDbPerSecond = 0.5,
        public float $averageSeconds = 5.0,
        public float $silenceDb = -45.0,
        public float $deadbandDb = 1.0,
        public float $overrideHoldSeconds = 30.0,
        public bool $allowBoost = false,
    ) {
        if (!Channel::isInput($channel)) {
            throw new \InvalidArgumentException(\sprintf('The gain rider works on inputs only, got %s.', Channel::label($channel)));
        }
        if ($minGainDb < -60 || $maxGainDb > 12 || $minGainDb >= $maxGainDb) {
            throw new \InvalidArgumentException(\sprintf('The gain window must lie in -60..+12 dB with min below max, got %s..%s.', $minGainDb, $maxGainDb));
        }
        if ($maxGainDb > 0 && !$allowBoost) {
            throw new \InvalidArgumentException(\sprintf('A maximum of %+.1f dB is a boost. Allow boosts to be sure.', $maxGainDb));
        }
        if ($slewDbPerSecond <= 0 || $averageSeconds <= 0 || $deadbandDb < 0 || $overrideHoldSeconds < 0) {
            throw new \InvalidArgumentException('Slew and average time must be positive, deadband and hold time must not be negative.');
        }
    }
}
