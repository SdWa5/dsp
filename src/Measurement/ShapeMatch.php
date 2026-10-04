<?php

namespace App\Measurement;

use App\Filter\Complex;
use App\Filter\Section;

/**
 * What a filter setting changed on a loopback: the transfer function of the case divided by the one of the
 * base, both against the same reference input. The division cancels the interface inputs, the cables and
 * whatever the output did as set, so only the setting's own magnitude and phase are left.
 *
 * The phase puts the delay back that each Transfer leaves out, so a delay or the group delay of a filter shows
 * as phase here. The points are the bins nearest to 1/12 octave steps from 20 Hz to 20 kHz. Averaging bins
 * would blur the phase of a delay, which turns by 0.36° per Hz at 1 ms.
 */
final readonly class ShapeMatch
{
    public const POINTS_PER_OCTAVE = 12;

    /** Points below this coherence stay out of the comparison. A loopback reads about 1.0 */
    public const MIN_COHERENCE = 0.95;

    /** Points where the model falls below this stay out of the comparison, since the noise takes over there */
    public const FLOOR_DB = -30.0;

    /** A setting fits its model when no compared point differs by more than these */
    public const MAX_DB = 0.5;
    public const MAX_DEGREES = 5.0;

    private const LOW = 20.0;
    private const HIGH = 20000.0;

    /**
     * @param list<array{hz: float, db: float, phase: float, coherence: float}> $points
     * @param float|null                                                        $delay  samples the case arrives later than the base, null without a clear peak
     */
    public function __construct(
        public array $points,
        public ?float $delay = null,
    ) {
    }

    public static function measure(Transfer $base, Transfer $case): self
    {
        if ($base->freqs !== $case->freqs) {
            throw new \InvalidArgumentException('The base and the case need transfer functions of the same size.');
        }
        $step = $case->freqs[1];
        $points = [];
        $last = -1;
        for ($k = (int) ceil(self::POINTS_PER_OCTAVE * log(self::LOW / 1000, 2)); ($hz = 1000 * 2 ** ($k / self::POINTS_PER_OCTAVE)) <= self::HIGH; ++$k) {
            $bin = (int) round($hz / $step);
            if ($bin === $last || !isset($case->freqs[$bin])) {
                continue;
            }
            $last = $bin;
            $f = $case->freqs[$bin];
            $phase = $case->phase[$bin] - $base->phase[$bin] - 360 * $f * ($case->aligned - $base->aligned) / $case->rate;
            $points[] = [
                'hz' => $f,
                'db' => $case->magnitude[$bin] - $base->magnitude[$bin],
                'phase' => self::wrap($phase),
                'coherence' => min($case->coherence[$bin], $base->coherence[$bin]),
            ];
        }

        return new self($points, null === $case->delay || null === $base->delay ? null : $case->delay - $base->delay);
    }

    /**
     * The largest differences against a model of the case, divided by a model of the base when given, over
     * the points that count.
     *
     * @return array{db: float|null, phase: float|null, from: float|null, to: float|null, points: int, fits: bool}
     */
    public function against(Section $model, ?Section $base = null): array
    {
        [$db, $phase, $from, $to, $count] = [0.0, 0.0, null, null, 0];
        foreach ($this->points as $point) {
            $expected = $model->at($point['hz']);
            if (null !== $base) {
                $expected = Complex::div($expected, $base->at($point['hz']));
            }
            if ($point['coherence'] < self::MIN_COHERENCE || Complex::db($expected) < self::FLOOR_DB) {
                continue;
            }
            $db = max($db, abs($point['db'] - Complex::db($expected)));
            $phase = max($phase, abs(self::wrap($point['phase'] - Complex::degrees($expected))));
            $from ??= $point['hz'];
            $to = $point['hz'];
            ++$count;
        }
        if (0 === $count) {
            return ['db' => null, 'phase' => null, 'from' => null, 'to' => null, 'points' => 0, 'fits' => false];
        }

        return ['db' => $db, 'phase' => $phase, 'from' => $from, 'to' => $to, 'points' => $count, 'fits' => $db <= self::MAX_DB && $phase <= self::MAX_DEGREES];
    }

    /**
     * @return array{delay: float|null, points: list<array{float, float, float, float}>} points as [hz, dB, degrees, coherence], rounded
     */
    public function toArray(): array
    {
        return [
            'delay' => null === $this->delay ? null : round($this->delay, 3),
            'points' => array_map(static fn (array $p): array => [round($p['hz'], 2), round($p['db'], 3), round($p['phase'], 2), round($p['coherence'], 4)], $this->points),
        ];
    }

    /**
     * @param array{delay?: float|null, points: list<array{float, float, float, float}>} $data as toArray() gives it
     */
    public static function fromArray(array $data): self
    {
        return new self(
            array_map(static fn (array $p): array => ['hz' => $p[0], 'db' => $p[1], 'phase' => $p[2], 'coherence' => $p[3]], $data['points']),
            $data['delay'] ?? null,
        );
    }

    private static function wrap(float $degrees): float
    {
        return fmod(fmod($degrees, 360) + 540, 360) - 180;
    }
}
