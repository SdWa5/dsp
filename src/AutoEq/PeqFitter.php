<?php

namespace App\AutoEq;

use App\Measurement\Response;
use App\Protocol\Scale;

/**
 * Fits peaking PEQ bands so that the measured response plus the EQ comes close to the target.
 *
 * Greedy: every new band goes to the largest remaining deviation. A dip only counts as far as it survives
 * 1-octave smoothing, so narrow dips (room nulls) are never filled, while peaks are cut as measured. After each
 * new band all bands are refined by coordinate descent in raw device steps (1/30 octave, 0.1 dB, one Q
 * step), so nothing is lost by rounding when the bands are written.
 *
 * Only the shape counts: the measurement is shifted to the target's mean level over the range first.
 */
final class PeqFitter
{
    private const MAX_PASSES = 20;
    private const MOVES = [1, 3];
    private const BOOST_PENALTY = 100.0;

    /** @var list<float> */
    private array $freqs = [];
    /** @var list<float> */
    private array $deviation = [];
    /** @var list<float> the deviation with every dip capped to its 1-octave smoothed depth */
    private array $fillable = [];
    private FitSettings $settings;

    public function fit(Response $measured, TargetCurve $target, FitSettings $settings): FitResult
    {
        $this->settings = $settings;
        $freqs = array_values(array_filter($measured->freqs, static fn (float $f): bool => $f >= $settings->low && $f <= $settings->high));
        if (\count($freqs) < 3) {
            throw new \InvalidArgumentException('The measurement has too few points inside the correction range.');
        }
        $this->freqs = $freqs;
        $inRange = $measured->resample($freqs);
        $goal = $target->on($freqs);
        $offset = $goal->mean($settings->low, $settings->high) - $inRange->mean($settings->low, $settings->high);
        $before = $goal->minus($inRange->shift($offset));
        $this->deviation = $before->db;
        $broad = $before->smooth(1.0);
        $this->fillable = array_map(static fn (float $d, float $b): float => $d <= 0 ? $d : min($d, max(0.0, $b)), $before->db, $broad->db);

        /** @var list<FittedBand> $bands */
        $bands = [];
        /** @var list<list<float>> $curves */
        $curves = [];
        while (\count($bands) < $settings->bands && null !== $band = $this->nextBand($curves)) {
            $bands[] = $band;
            $curves[] = $this->curve($band);
            [$bands, $curves] = $this->refine($bands, $curves);
        }

        return new FitResult($bands, $before, new Response($freqs, $this->remaining($curves)));
    }

    /**
     * @param list<list<float>> $curves
     */
    private function nextBand(array $curves): ?FittedBand
    {
        $eq = $this->sum($curves);
        $remaining = array_map(static fn (float $f, float $e): float => $f - $e, $this->fillable, $eq);
        $best = null;
        foreach ($remaining as $i => $r) {
            $allowed = $r < 0 || $eq[$i] < $this->settings->maxBoostDb;
            if ($allowed && abs($r) > $this->settings->toleranceDb && abs($r) > abs($best['value'] ?? 0.0)) {
                $best = ['index' => $i, 'value' => $r];
            }
        }
        if (null === $best) {
            return null;
        }
        [$index, $value] = [$best['index'], $best['value']];
        $sign = $value <=> 0;
        $series = new Response($this->freqs, $remaining);
        // Width: where the deviation has fallen to half on both sides
        [$left, $right] = [$index, $index];
        while ($left > 0 && $series->db[$left - 1] * $sign > 0 && abs($series->db[$left - 1]) >= abs($value) / 2) {
            --$left;
        }
        while ($right < \count($this->freqs) - 1 && $series->db[$right + 1] * $sign > 0 && abs($series->db[$right + 1]) >= abs($value) / 2) {
            ++$right;
        }
        $octaves = max(1 / 15, log($this->freqs[$right] / $this->freqs[$left], 2));
        $q = sqrt(2 ** $octaves) / (2 ** $octaves - 1);
        $gain = max(-$this->settings->maxCutDb, min($this->settings->maxBoostDb, $value));
        $band = new FittedBand(Scale::hzToIndex($this->freqs[$index]), Scale::dbToPeqGain(round($gain, 1)), Scale::qToIndex($q));

        return $this->clamp($band);
    }

    /**
     * @param list<FittedBand>  $bands
     * @param list<list<float>> $curves
     *
     * @return array{list<FittedBand>, list<list<float>>}
     */
    private function refine(array $bands, array $curves): array
    {
        $cost = $this->cost($this->sum($curves));
        for ($pass = 0; $pass < self::MAX_PASSES; ++$pass) {
            $improved = false;
            foreach ($bands as $j => $band) {
                $others = $this->sum(array_values(array_diff_key($curves, [$j => true])));
                foreach ($this->neighbours($band) as $candidate) {
                    $curve = $this->curve($candidate);
                    $candidateCost = $this->cost(array_map(static fn (float $a, float $b): float => $a + $b, $others, $curve));
                    if ($candidateCost < $cost - 1e-9) {
                        [$cost, $band, $bands[$j], $curves[$j], $improved] = [$candidateCost, $candidate, $candidate, $curve, true];
                    }
                }
            }
            if (!$improved) {
                break;
            }
        }

        return [$bands, $curves];
    }

    /**
     * @return list<FittedBand>
     */
    private function neighbours(FittedBand $band): array
    {
        $candidates = [];
        foreach (self::MOVES as $step) {
            foreach ([-$step, $step] as $d) {
                $candidates[] = $band->with(freq: $band->freq + $d);
                $candidates[] = $band->with(gain: $band->gain + $d);
                $candidates[] = $band->with(q: $band->q + $d);
            }
        }

        return array_values(array_filter($candidates, fn (FittedBand $c): bool => $c == $this->clamp($c)));
    }

    private function clamp(FittedBand $band): FittedBand
    {
        $s = $this->settings;
        $gain = max(Scale::dbToPeqGain(-$s->maxCutDb), min(Scale::dbToPeqGain($s->maxBoostDb), $band->gain));
        $maxQ = self::qIndexAtMost($gain > Scale::PEQ_GAIN_UNITY ? $s->maxBoostQ : $s->maxQ);

        return new FittedBand(
            max(Scale::hzToIndex($s->low), min(Scale::hzToIndex($s->high), $band->freq)),
            $gain,
            max(Scale::qToIndex(0.5), min($maxQ, $band->q)),
        );
    }

    /**
     * The largest Q index whose Q does not exceed $q (qToIndex rounds to the nearest step, which may lie above).
     */
    private static function qIndexAtMost(float $q): int
    {
        $index = Scale::qToIndex($q);

        return Scale::indexToQ($index) > $q + 1e-9 ? $index - 1 : $index;
    }

    /**
     * @param list<float> $eq
     */
    private function cost(array $eq): float
    {
        $cost = 0.0;
        foreach ($this->fillable as $i => $d) {
            $cost += ($d - $eq[$i]) ** 2;
            if ($eq[$i] > $this->settings->maxBoostDb) {
                $cost += self::BOOST_PENALTY * ($eq[$i] - $this->settings->maxBoostDb) ** 2;
            }
        }

        return $cost;
    }

    /**
     * @return list<float>
     */
    private function curve(FittedBand $band): array
    {
        return array_map($band->responseAt(...), $this->freqs);
    }

    /**
     * @param list<list<float>> $curves
     *
     * @return list<float>
     */
    private function sum(array $curves): array
    {
        $sum = array_fill(0, \count($this->freqs), 0.0);
        foreach ($curves as $curve) {
            foreach ($curve as $i => $v) {
                $sum[$i] += $v;
            }
        }

        return array_values($sum);
    }

    /**
     * @param list<list<float>> $curves
     *
     * @return list<float>
     */
    private function remaining(array $curves): array
    {
        return array_map(static fn (float $d, float $eq): float => $d - $eq, $this->deviation, $this->sum($curves));
    }
}
