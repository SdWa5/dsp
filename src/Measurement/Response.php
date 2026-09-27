<?php

namespace App\Measurement;

/**
 * A magnitude response in dB on a logarithmic frequency grid.
 */
final readonly class Response
{
    /**
     * @param list<float> $freqs ascending, in Hz
     * @param list<float> $db    one level per frequency
     */
    public function __construct(
        public array $freqs,
        public array $db,
    ) {
        if (\count($freqs) !== \count($db) || [] === $freqs) {
            throw new \InvalidArgumentException('A response needs one level per frequency.');
        }
    }

    /**
     * Grid of $perOctave points per octave from $low to $high Hz, starting at $low.
     *
     * @return list<float>
     */
    public static function grid(float $low = 20.0, float $high = 20000.0, int $perOctave = 24): array
    {
        $freqs = [];
        for ($i = 0; ($f = $low * 2 ** ($i / $perOctave)) <= $high * (1 + 1e-9); ++$i) {
            $freqs[] = $f;
        }

        return $freqs;
    }

    /**
     * Averages several responses on the same grid as power.
     *
     * @param non-empty-list<self> $responses
     */
    public static function average(array $responses): self
    {
        $first = $responses[0];
        $db = [];
        foreach ($first->freqs as $i => $f) {
            $power = 0.0;
            foreach ($responses as $response) {
                if ($response->freqs !== $first->freqs) {
                    throw new \InvalidArgumentException('Only responses on the same grid can be averaged.');
                }
                $power += 10 ** ($response->db[$i] / 10);
            }
            $db[] = 10 * log10($power / \count($responses));
        }

        return new self($first->freqs, $db);
    }

    /**
     * Level at $hz, interpolated linearly over log frequency and held beyond both ends.
     */
    public function at(float $hz): float
    {
        $n = \count($this->freqs);
        if ($hz <= $this->freqs[0]) {
            return $this->db[0];
        }
        if ($hz >= $this->freqs[$n - 1]) {
            return $this->db[$n - 1];
        }
        $lo = 0;
        $hi = $n - 1;
        while ($hi - $lo > 1) {
            $mid = intdiv($lo + $hi, 2);
            if ($this->freqs[$mid] <= $hz) {
                $lo = $mid;
            } else {
                $hi = $mid;
            }
        }
        $t = log($hz / $this->freqs[$lo]) / log($this->freqs[$hi] / $this->freqs[$lo]);

        return $this->db[$lo] + $t * ($this->db[$hi] - $this->db[$lo]);
    }

    /**
     * This response on another grid.
     *
     * @param list<float> $freqs
     */
    public function resample(array $freqs): self
    {
        return new self($freqs, array_map($this->at(...), $freqs));
    }

    /**
     * Pointwise $this - $other on this grid.
     */
    public function minus(self $other): self
    {
        return new self($this->freqs, array_map(fn (float $f, float $db): float => $db - $other->at($f), $this->freqs, $this->db));
    }

    public function shift(float $db): self
    {
        return new self($this->freqs, array_map(static fn (float $v): float => $v + $db, $this->db));
    }

    /**
     * Averages the level over a fraction of an octave around every point.
     */
    public function smooth(float $octaves): self
    {
        $half = 2 ** ($octaves / 2);
        $db = [];
        foreach ($this->freqs as $f) {
            $sum = 0.0;
            $count = 0;
            foreach ($this->freqs as $j => $g) {
                if ($g >= $f / $half && $g <= $f * $half) {
                    $sum += $this->db[$j];
                    ++$count;
                }
            }
            $db[] = $sum / $count;
        }

        return new self($this->freqs, $db);
    }

    /**
     * Mean level between $low and $high Hz.
     */
    public function mean(float $low, float $high): float
    {
        $values = [];
        foreach ($this->freqs as $i => $f) {
            if ($f >= $low && $f <= $high) {
                $values[] = $this->db[$i];
            }
        }
        if ([] === $values) {
            throw new \InvalidArgumentException(\sprintf('No points between %s and %s Hz.', $low, $high));
        }

        return array_sum($values) / \count($values);
    }
}
