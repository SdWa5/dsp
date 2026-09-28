<?php

namespace App\Measurement;

use App\Model\Links;
use App\Protocol\Channel;
use App\Protocol\Commands;
use App\Protocol\Frame;
use App\Protocol\Labels;
use App\Protocol\Scale;

/**
 * The outputs a measurement plays, and the writes that set them up and put them back, planned from one
 * device state before anything is written.
 *
 * With outputs given they play alone: each one brings its whole link group, every other unmuted output is
 * muted and a muted played output is unmuted. Without outputs every output plays as it is set. The reference
 * output always plays, unmuted and untrimmed, so that every recording also holds the generator's loopback.
 * The trim moves the gain of every other played output from its own value, clamped to -60..+12 dB.
 *
 * The setup mutes the other outputs first, then trims, then unmutes, so nothing plays before its trim. The
 * restore mutes the played outputs that were muted first, then puts the gains back, then unmutes the others.
 * Every target appears once in each list, so a restore that the write queue merges into a setup not yet sent
 * still ends in the snapshot. The test tone is not part of either list, see Playback.
 */
final readonly class Solo
{
    /**
     * @param list<int>                                 $played   output channels that play, the reference included
     * @param array<int, array{from: float, to: float}> $gains    trimmed outputs, their gain before and during the recording in dB
     * @param list<Frame>                               $setup
     * @param list<Frame>                               $restore
     * @param list<string>                              $notes
     * @param list<string>                              $warnings
     */
    public function __construct(
        public array $played,
        public ?int $reference,
        public bool $solo,
        public TestTone $original,
        public array $gains,
        public array $setup,
        public array $restore,
        public array $notes,
        public array $warnings,
    ) {
    }

    /**
     * @param array<mixed>   $state
     * @param list<int>|null $outputs   output channels to play alone, null for every output as set
     * @param float          $trim      dB added to the gain of every played output except the reference
     * @param int|null       $reference output channel that keeps playing untrimmed, null for none
     */
    public static function plan(array $state, ?array $outputs, float $trim = 0.0, ?int $reference = null): self
    {
        $channels = self::channels($state);
        $links = Links::outputs($state);
        if (null !== $reference) {
            self::check($reference);
            $members = self::linked($links, $reference);
            if (\count($members) > 1) {
                throw new \InvalidArgumentException(\sprintf('The reference output %s is linked (%s). Unlink it or choose another reference output.', Channel::label($reference), self::labels($members)));
            }
        }

        $notes = [];
        if (null === $outputs) {
            $asked = array_values(array_filter(array_keys($channels), static fn (int $ch): bool => $ch !== $reference && !self::muted($channels[$ch]) && 0 !== self::int($channels[$ch], 'route')));
        } else {
            $asked = [];
            foreach ($outputs as $channel) {
                self::check($channel);
                $members = self::linked($links, $channel);
                if (\count($members) > 1 && [] !== array_diff($members, $outputs)) {
                    $notes[] = \sprintf('%s are linked and play together.', self::labels($members));
                }
                array_push($asked, ...$members);
            }
            $asked = array_values(array_unique($asked));
            sort($asked);
            if (null !== $reference && \in_array($reference, $asked, true)) {
                throw new \InvalidArgumentException(\sprintf('%s is the reference output. Choose another reference output or none to play it.', Channel::label($reference)));
            }
        }
        $played = null === $reference ? $asked : [...$asked, $reference];
        sort($played);

        $mute = $unmuteOthers = $trims = $gainsBack = $unmute = $remute = $gains = [];
        if (null !== $outputs) {
            foreach ($channels as $channel => $output) {
                if (!\in_array($channel, $played, true) && !self::muted($output)) {
                    $mute[] = Commands::mute($channel, true);
                    $unmuteOthers[] = Commands::mute($channel, false);
                }
            }
        }
        if (0.0 !== $trim) {
            foreach ($asked as $channel) {
                $raw = self::int($channels[$channel], 'gain', Scale::GAIN_UNITY);
                $from = Scale::gainToDb($raw);
                $trimmed = Scale::dbToGain(max(-60.0, min(12.0, $from + $trim)));
                $gains[$channel] = ['from' => $from, 'to' => Scale::gainToDb($trimmed)];
                if ($trimmed !== $raw) {
                    $trims[] = Commands::gain($channel, $trimmed);
                    $gainsBack[] = Commands::gain($channel, $raw);
                }
            }
        }
        foreach ($played as $channel) {
            if (self::muted($channels[$channel])) {
                $unmute[] = Commands::mute($channel, false);
                $remute[] = Commands::mute($channel, true);
                $notes[] = \sprintf('%s was muted and plays for the recording.', Channel::label($channel));
            }
        }

        $original = TestTone::fromState($state);

        return new self(
            $played, $reference, null !== $outputs, $original, $gains,
            [...$mute, ...$trims, ...$unmute], [...$remute, ...$gainsBack, ...$unmuteOthers],
            array_values(array_unique($notes)), self::warnings($state, $channels, $asked, $played, $reference, $original),
        );
    }

    /**
     * @param array<mixed>             $state
     * @param array<int, array<mixed>> $channels
     * @param list<int>                $asked
     * @param list<int>                $played
     *
     * @return list<string>
     */
    private static function warnings(array $state, array $channels, array $asked, array $played, ?int $reference, TestTone $original): array
    {
        $warnings = [];
        if ([] === $asked) {
            $warnings[] = 'No output is routed and unmuted, only the reference output plays.';
        }
        foreach ($played as $channel) {
            if (0 === self::int($channels[$channel], 'route')) {
                $warnings[] = \sprintf('%s has no input routed, so it plays nothing.', Channel::label($channel));
            }
        }
        foreach ($asked as $channel) {
            $comp = \is_array($channels[$channel]['comp'] ?? null) ? $channels[$channel]['comp'] : [];
            $ratio = self::int($comp, 'ratio');
            if (0 !== $ratio) {
                $warnings[] = \sprintf(
                    '%s has its compressor on (%s from %.1f dB), which can hold the level down.',
                    Channel::label($channel), Labels::COMP_RATIOS[$ratio] ?? (string) $ratio, Scale::thresholdToDb(self::int($comp, 'threshold', 180)),
                );
            }
        }
        $controls = [];
        foreach (\is_array($state['groups'] ?? null) ? array_values($state['groups']) : [] as $index => $group) {
            if ($index >= 4 && \is_array($group)) {
                $controls[\sprintf('Output group %d', $index - 3)] = $group;
            }
        }
        if (\is_array($state['sub'] ?? null) && \is_array($state['sub'][1] ?? null)) {
            $controls['Sub control'] = $state['sub'][1];
        }
        foreach ($controls as $name => $control) {
            $mask = self::int($control, 'mask');
            $members = array_values(array_filter($played, static fn (int $ch): bool => (bool) (($mask >> ($ch - 8)) & 1)));
            if ([] === $members) {
                continue;
            }
            if (true === ($control['mute'] ?? false)) {
                $warnings[] = \sprintf('%s mutes %s.', $name, self::labels($members));
            } elseif (($gain = self::int($control, 'gain', 100)) < 100) {
                $warnings[] = \sprintf('%s holds %s at %d %%.', $name, self::labels($members), $gain);
            }
        }
        $aux = \is_array($state['aux'] ?? null) ? $state['aux'] : [];
        if (0 !== ($select = self::int($aux, 'select')) && true !== ($aux['mute'] ?? false)) {
            $warnings[] = \sprintf('The Aux takes %s and is not muted. If the test tone enters at the inputs, the Aux plays it too.', self::inputs($select));
        }
        if ($original->plays()) {
            $warnings[] = \sprintf('The test tone was already on (%s). It goes back on after the recording.', $original->label());
        }
        if (null !== $reference && [] !== ($coloured = self::coloured($channels[$reference]))) {
            $warnings[] = \sprintf('The reference output %s is not flat (%s), so its loopback carries these settings.', Channel::label($reference), implode(', ', $coloured));
        }

        return $warnings;
    }

    /**
     * What an output does to the signal beyond its gain.
     *
     * @param array<mixed> $output
     *
     * @return list<string>
     */
    private static function coloured(array $output): array
    {
        $reasons = [];
        if (true !== ($output['eq_bypass'] ?? false) && \is_array($output['peq'] ?? null)) {
            foreach (array_values($output['peq']) as $n => $band) {
                if (\is_array($band) && true !== ($band['bypass'] ?? false) && (self::int($band, 'type') >= 3 || Scale::PEQ_GAIN_UNITY !== self::int($band, 'gain', Scale::PEQ_GAIN_UNITY))) {
                    $reasons[] = \sprintf('PEQ %d', $n + 1);
                }
            }
        }
        foreach (['hpf' => 'HPF', 'lpf' => 'LPF'] as $key => $name) {
            if (\is_array($output[$key] ?? null) && 0 !== self::int($output[$key], 'slope')) {
                $reasons[] = $name;
            }
        }
        if (\is_array($output['comp'] ?? null) && 0 !== self::int($output['comp'], 'ratio')) {
            $reasons[] = 'compressor';
        }
        if (0 !== self::int($output, 'delay')) {
            $reasons[] = 'delay';
        }
        if (true === ($output['phase'] ?? false)) {
            $reasons[] = 'phase inverted';
        }

        return $reasons;
    }

    /**
     * @param array<mixed> $state
     *
     * @return array<int, array<mixed>> output channel => output
     */
    private static function channels(array $state): array
    {
        $channels = [];
        foreach (\is_array($state['outputs'] ?? null) ? array_values($state['outputs']) : [] as $i => $output) {
            if ($i < Channel::OUTPUTS && \is_array($output)) {
                $channels[Channel::output($i + 1)] = $output;
            }
        }
        if (Channel::OUTPUTS !== \count($channels)) {
            throw new \InvalidArgumentException('The device state has no outputs.');
        }

        return $channels;
    }

    private static function check(int $channel): void
    {
        if (!Channel::isOutput($channel)) {
            throw new \InvalidArgumentException(\sprintf('Only outputs play the test tone, got %s.', Channel::label($channel)));
        }
    }

    /**
     * @return non-empty-list<int> output channels linked with $channel, itself included
     */
    private static function linked(Links $links, int $channel): array
    {
        return array_map(static fn (int $i): int => Channel::output($i + 1), $links->members($channel - 8));
    }

    /**
     * @param array<mixed> $output
     */
    private static function muted(array $output): bool
    {
        return true === ($output['mute'] ?? false);
    }

    /**
     * @param array<mixed> $values
     */
    private static function int(array $values, string $key, int $default = 0): int
    {
        return \is_int($values[$key] ?? null) ? $values[$key] : $default;
    }

    /**
     * @param list<int> $channels
     */
    private static function labels(array $channels): string
    {
        return implode(', ', array_map(Channel::label(...), $channels));
    }

    private static function inputs(int $mask): string
    {
        $inputs = array_filter(range(0, 7), static fn (int $i): bool => (bool) (($mask >> $i) & 1));

        return [] === $inputs ? 'no input' : implode('+', array_map(static fn (int $i): string => Channel::label($i), $inputs));
    }
}
