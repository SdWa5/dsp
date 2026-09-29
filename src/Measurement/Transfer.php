<?php

namespace App\Measurement;

/**
 * Result of TransferFunction: how one recorded channel relates to the reference channel.
 *
 * The phase is relative to the arrival. It leaves out a pure delay of $aligned samples, which is the measured
 * delay, or the whole-sample lag when no delay could be measured. A pure delay therefore reads 0° at every
 * frequency. The magnitude includes the level difference between the two interface inputs, so only differences
 * between measurements on the same inputs mean anything.
 */
final readonly class Transfer
{
    /** Below this mean coherence a band's phase is noise and reads null */
    public const PHASE_COHERENCE = 0.5;

    /**
     * @param int                                                                                   $lag       whole-sample lag of the measured channel behind the reference
     * @param float|null                                                                            $delay     sub-sample delay in samples, null without a clear peak in the impulse response
     * @param float                                                                                 $aligned   delay in samples that the phase leaves out
     * @param float|null                                                                            $peakPhase phase of the analytic impulse response at its peak in degrees
     * @param float                                                                                 $peakRatio envelope peak over its median in dB
     * @param list<float>                                                                           $freqs     bin frequencies from 0 Hz to half the rate
     * @param list<float>                                                                           $magnitude dB per bin
     * @param list<float>                                                                           $phase     degrees per bin, -180..180
     * @param list<float>                                                                           $coherence 0..1 per bin
     * @param list<array{hz: float, label: string, db: float, phase: float|null, coherence: float}> $bands     third octaves as in Spectrum::bands()
     * @param list<string>                                                                          $warnings
     */
    public function __construct(
        public int $rate,
        public int $lag,
        public ?float $delay,
        public float $aligned,
        public Polarity $polarity,
        public ?float $peakPhase,
        public float $peakRatio,
        public int $segments,
        public array $freqs,
        public array $magnitude,
        public array $phase,
        public array $coherence,
        public array $bands,
        public array $warnings = [],
    ) {
    }

    public function delayMs(): ?float
    {
        return null === $this->delay ? null : $this->delay * 1000 / $this->rate;
    }

    /**
     * Magnitude, phase and coherence at the bin nearest to $hz.
     *
     * @return array{db: float, phase: float, coherence: float}
     */
    public function at(float $hz): array
    {
        $k = max(0, min(\count($this->freqs) - 1, (int) round($hz / ($this->freqs[1] - $this->freqs[0]))));

        return ['db' => $this->magnitude[$k], 'phase' => $this->phase[$k], 'coherence' => $this->coherence[$k]];
    }

    /**
     * Mean coherence of the bins between $low and $high.
     */
    public function meanCoherence(float $low = 20.0, float $high = 20000.0): float
    {
        $sum = 0.0;
        $count = 0;
        foreach ($this->freqs as $k => $f) {
            if ($f >= $low && $f <= $high) {
                $sum += $this->coherence[$k];
                ++$count;
            }
        }

        return 0 === $count ? 0.0 : $sum / $count;
    }

    /**
     * The widest run of adjacent bands whose coherence reaches PHASE_COHERENCE, which is the band a way plays
     * in. Null when no band reaches it.
     *
     * @return array{from: string, to: string, octaves: float, coherence: float}|null band labels, width and mean coherence
     */
    public function coherentRange(): ?array
    {
        [$best, $start] = [null, null];
        foreach ([...$this->bands, null] as $i => $band) {
            if (null !== $band && $band['coherence'] >= self::PHASE_COHERENCE) {
                $start ??= $i;
                continue;
            }
            if (null !== $start && (null === $best || $i - $start > $best[1] - $best[0] + 1)) {
                $best = [$start, $i - 1];
            }
            $start = null;
        }
        if (null === $best) {
            return null;
        }
        $run = \array_slice($this->bands, $best[0], $best[1] - $best[0] + 1);

        return [
            'from' => $run[0]['label'],
            'to' => $run[\count($run) - 1]['label'],
            'octaves' => log($run[\count($run) - 1]['hz'] / $run[0]['hz'], 2),
            'coherence' => array_sum(array_column($run, 'coherence')) / \count($run),
        ];
    }

    /**
     * Everything except the bins, rounded for JSON.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'lag' => $this->lag,
            'delay' => null === $this->delay ? null : round($this->delay, 2),
            'delay_ms' => null === ($ms = $this->delayMs()) ? null : round($ms, 3),
            'aligned' => round($this->aligned, 2),
            'polarity' => $this->polarity->value,
            'peak_phase' => null === $this->peakPhase ? null : round($this->peakPhase, 1),
            'peak_ratio' => round($this->peakRatio, 1),
            'coherence' => round($this->meanCoherence(), 3),
            'coherent' => null === ($range = $this->coherentRange()) ? null : ['octaves' => round($range['octaves'], 2), 'coherence' => round($range['coherence'], 3)] + $range,
            'segments' => $this->segments,
            'warnings' => $this->warnings,
            'bands' => array_map(static fn (array $band): array => [
                'hz' => round($band['hz'], 1),
                'label' => $band['label'],
                'db' => round($band['db'], 2),
                'phase' => null === $band['phase'] ? null : round($band['phase'], 1),
                'coherence' => round($band['coherence'], 3),
            ], $this->bands),
        ];
    }
}
