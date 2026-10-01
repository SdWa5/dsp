<?php

namespace App\Automation;

use App\Protocol\Scale;

/**
 * Rides the gain of one input towards a target meter level, slowly and inside a fixed window.
 *
 * It averages the meter level as power with an exponential time constant, leaves small deviations
 * alone, changes the gain by at most the slew rate and holds the gain while the input is silent, so it
 * never rides up the noise floor. The input meters read after the gain, so a gain change shifts the
 * average by the same amount at once and the loop does not overshoot. For the same reason silence is
 * judged on the level before the gain: a quiet source after a loud one is no pause, even where the gain
 * the loud one left behind puts its meter level below the silence level.
 *
 * Someone else changing the gain (the editor, a fader) pauses the rider for the hold time and makes that
 * gain the new starting point. The rider's own writes come back in later state events and are recognised.
 *
 * A rider can ride a link group of inputs as one (observeGains(), groupLevel()): it takes the power mean of the
 * members' meters, so a stereo pair keeps its balance, and the same gain goes to every member (writes()). A change
 * to any member's gain by someone else pauses it, and once the pause ends the other members follow that gain.
 *
 * Times are seconds on any monotonic clock. The rider does no I/O: meter() returns the raw gain to write.
 */
final class GainRider
{
    /** Longest gap between two meter readings that still counts as continuous */
    private const MAX_GAP = 1.0;
    /** How long a written value is expected to come back in a state event */
    private const ECHO_WINDOW = 3.0;

    /** No gain known yet */
    public const WAITING = 'waiting';
    /** Someone else changed the gain, the rider holds off */
    public const PAUSED = 'paused';
    /** The input is below the silence level, the gain is frozen */
    public const SILENT = 'silent';
    /** The average is inside the deadband */
    public const STEADY = 'steady';
    /** The gain is moving towards the target */
    public const RIDING = 'riding';
    /** The target needs a gain outside the window */
    public const LIMIT = 'limit';

    private ?float $power = null;
    private ?float $gainDb = null;
    private ?int $lastRaw = null;
    /** @var array<int, int> channel => raw gain last seen or written, for a rider of several channels */
    private array $known = [];
    private ?float $lastMeter = null;
    private float $pausedUntil = -\INF;
    private string $status = self::WAITING;
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
        return $this->observeGains([0 => $raw], $now);
    }

    /**
     * Takes the gains of the channels the rider rides from a device state, channel => raw, the rider's own
     * channel first. Returns true when someone else changed one of them, whose gain then becomes the starting
     * point. A channel seen for the first time (it joined the link group) is taken as it is, writes() aligns it.
     *
     * @param non-empty-array<int, int> $raws
     */
    public function observeGains(array $raws, float $now): bool
    {
        $this->written = array_filter($this->written, static fn (float $t): bool => $now - $t <= self::ECHO_WINDOW);
        $this->known = array_intersect_key($this->known, $raws);
        if (null === $this->lastRaw) {
            $this->adopt($raws[array_key_first($raws)]);
            $this->known = $raws;

            return false;
        }
        $changed = null;
        foreach ($raws as $channel => $raw) {
            $known = $this->known[$channel] ?? null;
            $this->known[$channel] = $raw;
            if (null === $known || $raw === $known || isset($this->written[$raw])) {
                continue;
            }
            $changed ??= $raw;
        }
        if (null === $changed) {
            return false;
        }
        $this->adopt($changed);
        $this->power = null;
        $this->pausedUntil = $now + $this->settings->overrideHoldSeconds;

        return true;
    }

    /**
     * The raw gains to write, channel => raw: every channel of observeGains() whose gain is not the rider's, so the
     * members of a link group end up at the same gain. Nothing while paused or before the first state.
     *
     * @return array<int, int>
     */
    public function writes(float $now): array
    {
        if (null === $this->lastRaw || $this->isPaused($now)) {
            return [];
        }
        $writes = [];
        foreach ($this->known as $channel => $raw) {
            if ($raw !== $this->lastRaw) {
                $writes[$channel] = $this->lastRaw;
                $this->known[$channel] = $this->lastRaw;
                $this->written[$this->lastRaw] = $now;
            }
        }

        return $writes;
    }

    /**
     * The level of a link group for meter(): the power mean of its members' meter levels in dB. A stereo pair
     * panned hard to one side reads 3 dB below the loud member.
     *
     * @param non-empty-list<float> $levels
     */
    public static function groupLevel(array $levels): float
    {
        $power = array_sum(array_map(static fn (float $db): float => $db <= Scale::METER_FLOOR ? 0.0 : 10 ** ($db / 10), $levels)) / \count($levels);

        return $power > 0 ? max(Scale::METER_FLOOR, 10 * log10($power)) : Scale::METER_FLOOR;
    }

    /**
     * Forgets the gain, the average and a pause, as for a new rider. For a rider whose channel another rider of
     * its link group rides.
     */
    public function reset(): void
    {
        $this->power = null;
        $this->gainDb = null;
        $this->lastRaw = null;
        $this->lastMeter = null;
        $this->known = [];
        $this->written = [];
        $this->pausedUntil = -\INF;
        $this->status = self::WAITING;
    }

    /**
     * Takes one meter reading of the channel. Returns the raw gain to write, or null for no change.
     */
    public function meter(float $levelDb, float $now): ?int
    {
        $dt = null === $this->lastMeter ? 0.0 : min(self::MAX_GAP, max(0.0, $now - $this->lastMeter));
        $this->lastMeter = $now;
        if (null === $this->gainDb || $this->isPaused($now)) {
            return null;
        }
        // A meter at its floor (gain raw 0 or nothing playing) tells nothing about the level before the gain
        if ($levelDb <= Scale::METER_FLOOR || $levelDb - $this->gainDb < $this->settings->silenceDb) {
            $this->status = self::SILENT;

            return null;
        }
        $power = 10 ** ($levelDb / 10);
        $this->power = null === $this->power ? $power : $this->power + (1 - exp(-$dt / $this->settings->averageSeconds)) * ($power - $this->power);
        $error = $this->settings->targetDb - $this->averageDb();
        if (abs($error) <= $this->settings->deadbandDb) {
            $this->status = self::STEADY;

            return null;
        }
        $step = min(abs($error), $this->settings->slewDbPerSecond * $dt) * ($error <=> 0);
        // A gain outside the window moves back in at the slew rate, never further out
        $low = min($this->settings->minGainDb, $this->gainDb);
        $high = max($this->settings->maxGainDb, $this->gainDb);
        $gainDb = max($low, min($high, $this->gainDb + $step));
        $applied = $gainDb - $this->gainDb;
        $this->status = 0.0 === $applied && 0.0 !== $step ? self::LIMIT : self::RIDING;
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

    /**
     * What the rider is doing: one of the constants WAITING, PAUSED, SILENT, STEADY, RIDING or LIMIT.
     */
    public function status(float $now): string
    {
        if (null === $this->gainDb) {
            return self::WAITING;
        }

        return $this->isPaused($now) ? self::PAUSED : $this->status;
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
