<?php

namespace App\Tests\Measurement;

use App\Measurement\FilterCase;
use App\Measurement\Playback;
use App\Model\OutputChannel;
use App\Protocol\Channel;
use App\Protocol\Commands;
use App\Protocol\Frame;
use App\Protocol\Labels;
use App\Protocol\Scale;
use PHPUnit\Framework\TestCase;

final class FilterCaseTest extends TestCase
{
    public function testTheCatalogue(): void
    {
        $cases = FilterCase::catalogue();
        $ids = array_map(static fn (FilterCase $case): string => $case->id, $cases);

        self::assertSame(FilterCase::BASE, $ids[0]);
        self::assertSame([], $cases[0]->changes);
        self::assertCount(3 + 11 + 2 * (\count(Labels::SLOPES) - 1), $cases);
        self::assertSame($ids, array_values(array_unique($ids)));
        self::assertContains('hpf-lk24', $ids);
        self::assertContains('lpf-bl6', $ids);
        $types = array_map(static fn (FilterCase $case): int => $case->changes['peq']['type'] ?? -1, array_filter($cases, static fn (FilterCase $case): bool => 'peq' === $case->group));
        self::assertSame(range(0, \count(Labels::PEQ_TYPES) - 1), array_values(array_unique($types)));
    }

    public function testSelect(): void
    {
        self::assertSame(['base', 'delay', 'invert'], self::ids(FilterCase::select('timing')));
        self::assertSame(['base', 'peak-1k', 'hpf-lk24'], self::ids(FilterCase::select('hpf-lk24, PEAK-1K')));
        self::assertCount(20 + 1, FilterCase::select('lpf'));
        self::assertCount(\count(FilterCase::catalogue()), FilterCase::select('all'));
        self::assertCount(\count(FilterCase::catalogue()), FilterCase::select(''));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown case "hpf-lk18"');
        FilterCase::select('peq,hpf-lk18');
    }

    public function testApply(): void
    {
        $output = (new OutputChannel(eqBypass: true))->toArray();
        $peak = self::find('peak-1k');
        $applied = $peak->apply($output);

        self::assertSame(self::band($peak), $applied['peq'][0]);
        self::assertSame($output['peq'][1], $applied['peq'][1]);
        self::assertFalse($applied['eq_bypass']);
        self::assertSame(48, self::find('delay')->apply($output)['delay']);
        self::assertSame(['freq' => Scale::hzToIndex(1000), 'slope' => 10], self::find('lpf-lk24')->apply($output)['lpf']);
    }

    public function testTransitionsWriteOnlyWhatChanges(): void
    {
        $channel = Channel::output(7);
        $output = (new OutputChannel())->toArray();

        self::assertSame([], self::find(FilterCase::BASE)->transition($output, $channel));
        self::assertSame([self::hex(Commands::highPass($channel, Scale::hzToIndex(1000), 1))], self::hexes(self::find('hpf-bw6')->transition($output, $channel)));
        // From one slope to the other: the HPF goes back, the LPF gets its slope
        self::assertSame(
            [self::hex(Commands::highPass($channel, 0, 0)), self::hex(Commands::lowPass($channel, Scale::hzToIndex(1000), 1))],
            self::hexes(self::find('lpf-bw6')->transition($output, $channel, self::find('hpf-bw6'))),
        );
        self::assertSame(
            [self::hex(Commands::delay($channel, 0)), self::hex(Commands::phase($channel, true))],
            self::hexes(self::find('invert')->transition($output, $channel, self::find('delay'))),
        );
        $band = self::band(self::find('peak-1k'));
        self::assertSame(
            [self::hex(Commands::eqBypass($channel, false)), self::hex(Commands::peq($channel, 0, $band['gain'], $band['freq'], $band['q'], 0, false))],
            self::hexes(self::find('peak-1k')->transition((new OutputChannel(eqBypass: true))->toArray(), $channel)),
        );
    }

    public function testRestorePutsBackEveryChangedValue(): void
    {
        $channel = Channel::output(3);
        $output = (new OutputChannel(delay: 12, phase: true, eqBypass: true))->toArray();
        $band = $output['peq'][0];

        self::assertSame([], FilterCase::restore($output, $channel, FilterCase::select('base')));
        self::assertSame([
            self::hex(Commands::delay($channel, 12)),
            self::hex(Commands::phase($channel, true)),
            self::hex(Commands::peq($channel, 0, $band['gain'], $band['freq'], $band['q'], $band['type'], false)),
            self::hex(Commands::highPass($channel, 0, 0)),
            self::hex(Commands::lowPass($channel, Scale::FREQ_STEPS, 0)),
            self::hex(Commands::eqBypass($channel, true)),
        ], self::hexes(FilterCase::restore($output, $channel, FilterCase::catalogue())));
    }

    /**
     * @param list<FilterCase> $cases
     *
     * @return list<string>
     */
    private static function ids(array $cases): array
    {
        return array_map(static fn (FilterCase $case): string => $case->id, $cases);
    }

    private static function find(string $id): FilterCase
    {
        foreach (FilterCase::catalogue() as $case) {
            if ($id === $case->id) {
                return $case;
            }
        }
        self::fail("No case $id");
    }

    /**
     * @return array{gain: int, freq: int, q: int, type: int, bypass: bool}
     */
    private static function band(FilterCase $case): array
    {
        self::assertArrayHasKey('peq', $case->changes);

        return $case->changes['peq'];
    }

    /**
     * @param list<Frame> $frames
     *
     * @return list<string>
     */
    private static function hexes(array $frames): array
    {
        return array_map(self::hex(...), $frames);
    }

    private static function hex(Frame $frame): string
    {
        return Playback::hex($frame);
    }
}
