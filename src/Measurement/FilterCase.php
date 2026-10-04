<?php

namespace App\Measurement;

use App\Protocol\Commands;
use App\Protocol\Frame;
use App\Protocol\Labels;
use App\Protocol\Scale;

/**
 * One setting of the filter shape measurement (sdwa5-dsp:filter-shapes). It changes some values of the measured
 * output, in the keys of an output of the bridge state: PEQ band 1, the HPF, the LPF, the delay or the phase.
 * Everything else stays as the output has it.
 */
final readonly class FilterCase
{
    public const BASE = 'base';

    /** Groups for --cases, besides single ids */
    public const GROUPS = ['timing', 'peq', 'hpf', 'lpf'];

    /** Corner of the HPF and LPF slopes */
    public const CORNER = 1000.0;

    /**
     * @param array{peq?: array{gain: int, freq: int, q: int, type: int, bypass: bool}, hpf?: array{freq: int, slope: int}, lpf?: array{freq: int, slope: int}, delay?: int, phase?: bool} $changes
     */
    public function __construct(
        public string $id,
        public string $group,
        public string $label,
        public array $changes = [],
    ) {
    }

    /**
     * Every case, the base first. The PEQ cases check the gain, the frequency warping near 10 kHz, the shelf
     * form and whether the passes and allpasses apply the gain. The slopes all sit at CORNER.
     *
     * @return non-empty-list<self>
     */
    public static function catalogue(): array
    {
        $cases = [
            new self(self::BASE, self::BASE, 'as set'),
            new self('delay', 'timing', 'delay 48 samples (1.000 ms)', ['delay' => 48]),
            new self('invert', 'timing', 'phase inverted', ['phase' => true]),
        ];
        foreach ([
            ['peak-1k', 0, 1000, 6.0, 1.4],
            ['peak-10k', 0, 10000, -9.0, 4.0],
            ['peak-100', 0, 100, 6.0, 0.7],
            ['lowshelf', 1, 200, 6.0, 0.7],
            ['lowshelf-q2', 1, 200, 6.0, 2.0],
            ['highshelf', 2, 5000, -6.0, 0.7],
            ['lowpass', 3, 2000, 6.0, 0.7],
            ['highpass', 4, 500, 0.0, 2.0],
            ['allpass1', 5, 1000, 6.0, 0.7],
            ['allpass2', 6, 1000, 0.0, 0.7],
            ['allpass2-q4', 6, 1000, 0.0, 4.0],
        ] as [$id, $type, $hz, $db, $q]) {
            $band = ['gain' => Scale::dbToPeqGain($db), 'freq' => Scale::hzToIndex($hz), 'q' => Scale::qToIndex($q), 'type' => $type, 'bypass' => false];
            $label = \sprintf('%s %s %+.1f dB Q %.2f', Labels::PEQ_TYPES[$type], self::hz(Scale::indexToHz($band['freq'])), Scale::peqGainToDb($band['gain']), Scale::indexToQ($band['q']));
            $cases[] = new self($id, 'peq', $label, ['peq' => $band]);
        }
        foreach (['hpf' => 'HPF', 'lpf' => 'LPF'] as $key => $name) {
            foreach (Labels::SLOPES as $slope => $slopeLabel) {
                if (0 !== $slope) {
                    $id = $key.'-'.strtolower(str_replace([' ', '-'], '', $slopeLabel));
                    $cases[] = new self($id, $key, \sprintf('%s %s %s', $name, $slopeLabel, self::hz(self::corner())), [$key => ['freq' => Scale::hzToIndex(self::CORNER), 'slope' => $slope]]);
                }
            }
        }

        return $cases;
    }

    /**
     * The cases of a comma-separated list of ids and groups, or of "all", always with the base first.
     *
     * @return non-empty-list<self>
     */
    public static function select(string $selection): array
    {
        $catalogue = self::catalogue();
        $wanted = array_values(array_filter(array_map('trim', explode(',', strtolower($selection))), static fn (string $s): bool => '' !== $s));
        if ([] === $wanted || \in_array('all', $wanted, true)) {
            return $catalogue;
        }
        $ids = array_map(static fn (self $case): string => $case->id, $catalogue);
        foreach ($wanted as $name) {
            if (!\in_array($name, $ids, true) && !\in_array($name, self::GROUPS, true)) {
                throw new \InvalidArgumentException(\sprintf('Unknown case "%s". Use all, a group (%s) or case ids such as peak-1k or hpf-lk24.', $name, implode(', ', self::GROUPS)));
            }
        }

        return [$catalogue[0], ...array_values(array_filter(\array_slice($catalogue, 1), static fn (self $case): bool => \in_array($case->id, $wanted, true) || \in_array($case->group, $wanted, true)))];
    }

    /**
     * The output with this case's changes, PEQ band 1 carrying the band.
     *
     * @param array<string, mixed> $output an output of the bridge state
     *
     * @return array<string, mixed>
     */
    public function apply(array $output): array
    {
        foreach ($this->changes as $key => $value) {
            if ('peq' === $key) {
                $bands = \is_array($output['peq'] ?? null) ? $output['peq'] : [];
                $bands[0] = $value;
                $output['peq'] = $bands;
                $output['eq_bypass'] = false;
            } else {
                $output[$key] = $value;
            }
        }

        return $output;
    }

    /**
     * Frames that take the output from the case $from, null for the output as set, to this case. Only the
     * values that differ get a frame, since every USB frame costs time.
     *
     * @param array<string, mixed> $output the output as set
     *
     * @return list<Frame>
     */
    public function transition(array $output, int $channel, ?self $from = null): array
    {
        return self::frames($from?->apply($output) ?? $output, $this->apply($output), $channel);
    }

    /**
     * Frames that put back every value that one of the cases changes, for the restore of the run.
     *
     * @param array<string, mixed> $output the output as set
     * @param list<self>           $cases
     *
     * @return list<Frame>
     */
    public static function restore(array $output, int $channel, array $cases): array
    {
        $keys = [];
        foreach ($cases as $case) {
            $keys = [...$keys, ...array_keys($case->changes)];
        }
        $keys = array_values(array_unique($keys));
        $frames = [];
        $band = \is_array($output['peq'][0] ?? null) ? $output['peq'][0] : [];
        foreach ($keys as $key) {
            $frames[] = match ($key) {
                'peq' => Commands::peq($channel, 0, self::int($band, 'gain', Scale::PEQ_GAIN_UNITY), self::int($band, 'freq', 100), self::int($band, 'q', 25), self::int($band, 'type'), (bool) ($band['bypass'] ?? false)),
                'hpf', 'lpf' => self::filter($key, $output, $channel),
                'delay' => Commands::delay($channel, self::int($output, 'delay')),
                'phase' => Commands::phase($channel, (bool) ($output['phase'] ?? false)),
            };
        }
        if (\in_array('peq', $keys, true) && ($output['eq_bypass'] ?? false)) {
            $frames[] = Commands::eqBypass($channel, true);
        }

        return $frames;
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     *
     * @return list<Frame>
     */
    private static function frames(array $old, array $new, int $channel): array
    {
        $frames = [];
        if (($old['eq_bypass'] ?? false) !== ($new['eq_bypass'] ?? false)) {
            $frames[] = Commands::eqBypass($channel, (bool) ($new['eq_bypass'] ?? false));
        }
        $band = \is_array($new['peq'][0] ?? null) ? $new['peq'][0] : [];
        if (($old['peq'][0] ?? null) != $band) {
            $frames[] = Commands::peq($channel, 0, self::int($band, 'gain', Scale::PEQ_GAIN_UNITY), self::int($band, 'freq', 100), self::int($band, 'q', 25), self::int($band, 'type'), (bool) ($band['bypass'] ?? false));
        }
        foreach (['hpf', 'lpf'] as $key) {
            if (($old[$key] ?? null) != ($new[$key] ?? null)) {
                $frames[] = self::filter($key, $new, $channel);
            }
        }
        if (self::int($old, 'delay') !== self::int($new, 'delay')) {
            $frames[] = Commands::delay($channel, self::int($new, 'delay'));
        }
        if ((bool) ($old['phase'] ?? false) !== (bool) ($new['phase'] ?? false)) {
            $frames[] = Commands::phase($channel, (bool) ($new['phase'] ?? false));
        }

        return $frames;
    }

    /**
     * @param 'hpf'|'lpf'          $key
     * @param array<string, mixed> $output
     */
    private static function filter(string $key, array $output, int $channel): Frame
    {
        $filter = \is_array($output[$key] ?? null) ? $output[$key] : [];
        $freq = self::int($filter, 'freq', 'hpf' === $key ? 0 : Scale::FREQ_STEPS);

        return 'hpf' === $key ? Commands::highPass($channel, $freq, self::int($filter, 'slope')) : Commands::lowPass($channel, $freq, self::int($filter, 'slope'));
    }

    private static function corner(): float
    {
        return Scale::indexToHz(Scale::hzToIndex(self::CORNER));
    }

    private static function hz(float $hz): string
    {
        return $hz >= 1000 ? \sprintf('%.3g kHz', $hz / 1000) : \sprintf('%.3g Hz', $hz);
    }

    /**
     * @param array<mixed> $values
     */
    private static function int(array $values, string $key, int $default = 0): int
    {
        $value = $values[$key] ?? $default;

        return is_numeric($value) ? (int) $value : $default;
    }
}
