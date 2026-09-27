<?php

namespace App\Automation;

use App\Protocol\Scale;

/**
 * Rides the gain of one input towards a target meter level, slowly and inside a fixed window.
 *
 * It averages the meter level as power with an exponential time constant, leaves small deviations
 * alone, changes the gain by at most the slew rate and holds the gain while the input is silent, so it
 * never rides up the noise floor. The input meters read after the gain, so a gain change shifts the
 * average by the same amount at once and the loop does not overshoot.
 *
 * Someone else changing the gain (the editor, a fader) pauses the rider for the hold time and makes that
 * gain the new starting point. The rider's own writes come back in later state events and are recognised.
 *
 * Times are seconds on any monotonic clock. The rider does no I/O: meter() returns the raw gain to write.
 */
final class GainRider
{
    /** Longest gap between two meter readings that still counts as continuous */
    private const MAX_GAP = 1.0;
    /** How long a written value is expected to come back in a state event */
    private const ECHO_WINDOW = 3.0;

    private ?float $power = null;
    private ?float $gainDb = null;
    private ?int $lastRaw = null;
    private ?float $lastMeter = null;
    private float $pausedUntil = -\INF;
    /** @var array<int, float> raw value => time written */
    private array $written = [];

    public function __construct(private readonly RiderSettings $settings)
    {
    }

    public function settings(): RiderSettings
    {
        return $this->settings;
    }

    /**
     * Takes the gain of the channel from a device state. Returns true when someone else changed it.
     */
    public function observeGain(int $raw, float $now): bool
    {
        $this->written = array_filter($this->written, static fn (float $t): bool => $now - $t <= self::ECHO_WINDOW);
        if (null === $this->lastRaw) {
            $this->adopt($raw);

            return false;
        }
        if ($raw === $this->lastRaw || isset($this->written[$raw])) {
            return false;
        }
        $this->adopt($raw);
        $this->power = null;
        $this->pausedUntil = $now + $this->settings->overrideHoldSeconds;

        return true;
    }

    /**
     * Takes one meter reading of the channel. Returns the raw gain to write, or null for no change.
     */
    public function meter(float $levelDb, float $now): ?int
    {
        $dt = null === $this->lastMeter ? 0.0 : min(self::MAX_GAP, max(0.0, $now - $this->lastMeter));
        $this->lastMeter = $now;
        if (null === $this->gainDb || $this->isPaused($now) || $levelDb < $this->settings->silenceDb) {
            return null;
        }
        $power = 10 ** ($levelDb / 10);
        $this->power = null === $this->power ? $power : $this->power + (1 - exp(-$dt / $this->settings->averageSeconds)) * ($power - $this->power);
        $error = $this->settings->targetDb - $this->averageDb();
        if (abs($error) <= $this->settings->deadbandDb) {
            return null;
        }
        $step = min(abs($error), $this->settings->slewDbPerSecond * $dt) * ($error <=> 0);
        // A gain outside the window moves back in at the slew rate, never further out
        $low = min($this->settings->minGainDb, $this->gainDb);
        $high = max($this->settings->maxGainDb, $this->gainDb);
        $gainDb = max($low, min($high, $this->gainDb + $step));
        $applied = $gainDb - $this->gainDb;
        if (0.0 === $applied) {
            return null;
        }
        $this->gainDb = $gainDb;
        $this->power *= 10 ** ($applied / 10);
        $raw = Scale::dbToGain($gainDb);
        if ($raw === $this->lastRaw) {
            return null;
        }
        $this->lastRaw = $raw;
        $this->written[$raw] = $now;

        return $raw;
    }

    /**
     * Averaged meter level in dB, null before the first reading above the silence level.
     */
    public function averageDb(): ?float
    {
        return null === $this->power ? null : 10 * log10(max($this->power, 1e-12));
    }

    /**
     * Gain the rider holds in dB, fractional between two raw steps. Null before the first state.
     */
    public function gainDb(): ?float
    {
        return $this->gainDb;
    }

    public function isPaused(float $now): bool
    {
        return $now < $this->pausedUntil;
    }

    private function adopt(int $raw): void
    {
        $this->lastRaw = $raw;
        $this->gainDb = Scale::gainToDb($raw);
    }
}
