<?php

namespace App\Filter;

use App\Protocol\Scale;

/**
 * The filters of a channel in series, with its polarity and delay: what the channel does to a signal apart from
 * its gain, its compressor and its routing.
 */
final readonly class Chain implements Section
{
    /**
     * @param list<Section> $sections
     * @param float         $delay    samples at $rate
     */
    public function __construct(
        public array $sections = [],
        public bool $inverted = false,
        public float $delay = 0.0,
        public int $rate = Scale::SAMPLE_RATE,
    ) {
    }

    /**
     * An output of the bridge state: the PEQ bands unless bypassed, the HPF, the LPF, the phase and the delay.
     * A peak or a shelf at 0 dB passes the signal unchanged and is left out, while a pass or an allpass filters
     * at any gain.
     *
     * @param array<string, mixed> $output
     */
    public static function output(array $output, int $rate = Scale::SAMPLE_RATE, BesselNorm $bessel = BesselNorm::DEFAULT): self
    {
        $sections = [];
        if (!($output['eq_bypass'] ?? false)) {
            foreach (\is_array($output['peq'] ?? null) ? $output['peq'] : [] as $band) {
                $type = \is_array($band) ? self::int($band, 'type') : 0;
                if (\is_array($band) && !($band['bypass'] ?? false) && ($type > 2 || Scale::PEQ_GAIN_UNITY !== self::int($band, 'gain'))) {
                    $sections[] = Biquad::peq(
                        $type,
                        Scale::indexToHz(self::int($band, 'freq')),
                        Scale::peqGainToDb(self::int($band, 'gain')),
                        Scale::indexToQ(self::int($band, 'q')),
                        $rate,
                    );
                }
            }
        }
        foreach (['hpf' => true, 'lpf' => false] as $key => $high) {
            $filter = $output[$key] ?? null;
            if (\is_array($filter) && 0 !== self::int($filter, 'slope')) {
                $sections[] = new Crossover(self::int($filter, 'slope'), Scale::indexToHz(self::int($filter, 'freq')), $high, $rate, $bessel);
            }
        }

        return new self($sections, (bool) ($output['phase'] ?? false), (float) self::int($output, 'delay'), $rate);
    }

    public function at(float $hz): array
    {
        $h = [$this->inverted ? -1.0 : 1.0, 0.0];
        foreach ($this->sections as $section) {
            $h = Complex::mul($h, $section->at($hz));
        }
        if (0.0 !== $this->delay) {
            $angle = -2 * M_PI * $hz * $this->delay / $this->rate;
            $h = Complex::mul($h, [cos($angle), sin($angle)]);
        }

        return $h;
    }

    public function db(float $hz): float
    {
        return Complex::db($this->at($hz));
    }

    public function degrees(float $hz): float
    {
        return Complex::degrees($this->at($hz));
    }

    /**
     * @param array<mixed> $values
     */
    private static function int(array $values, string $key): int
    {
        $value = $values[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }
}
