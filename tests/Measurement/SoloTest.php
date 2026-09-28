<?php

namespace App\Tests\Measurement;

use App\Measurement\Solo;
use App\Protocol\Channel;
use App\Protocol\Commands;
use App\Protocol\Frame;
use App\Protocol\Scale;
use App\Transport\DemoTransport;
use PHPUnit\Framework\TestCase;

/**
 * Solo plans on the demo state: Out1+Out2 and Out5+Out6 linked, Out8 muted, the test tone on white noise.
 */
final class SoloTest extends TestCase
{
    private const DEMO = __DIR__.'/../../resources/demo_config.bin';

    /**
     * @return array<mixed>
     */
    private static function demo(): array
    {
        return DemoTransport::fromFile(self::DEMO)->state()->toDevice()->toArray();
    }

    /**
     * @param list<Frame> $frames
     *
     * @return list<string>
     */
    private static function hex(array $frames): array
    {
        return array_map(static fn (Frame $frame): string => $frame->shortHex(), $frames);
    }

    /**
     * @param list<Frame> $frames
     *
     * @return list<string>
     */
    private static function expected(array $frames): array
    {
        return self::hex($frames);
    }

    public function testAnOutputPlaysWithItsLinkGroupAndTheReference(): void
    {
        $solo = Solo::plan(self::demo(), [Channel::output(2)], -20.0, Channel::output(8));

        self::assertSame([8, 9, 15], $solo->played);
        self::assertTrue($solo->solo);
        self::assertSame(15, $solo->reference);
        self::assertSame([8 => ['from' => 0.0, 'to' => -20.0], 9 => ['from' => 0.0, 'to' => -20.0]], $solo->gains);
        self::assertSame(self::expected([
            Commands::mute(10, true), Commands::mute(11, true), Commands::mute(12, true), Commands::mute(13, true), Commands::mute(14, true),
            Commands::gain(8, Scale::dbToGain(-20.0)), Commands::gain(9, Scale::dbToGain(-20.0)),
            Commands::mute(15, false),
        ]), self::hex($solo->setup));
        self::assertSame(self::expected([
            Commands::mute(15, true),
            Commands::gain(8, 280), Commands::gain(9, 280),
            Commands::mute(10, false), Commands::mute(11, false), Commands::mute(12, false), Commands::mute(13, false), Commands::mute(14, false),
        ]), self::hex($solo->restore));
        self::assertSame(['Out1, Out2 are linked and play together.', 'Out8 was muted and plays for the recording.'], $solo->notes);
        self::assertSame([
            'Out1 has its compressor on (Limit from 0.0 dB), which can hold the level down.',
            'Out2 has its compressor on (Limit from 0.0 dB), which can hold the level down.',
            'The test tone was already on (White Noise). It goes back on after the recording.',
        ], $solo->warnings);
    }

    public function testWithoutOutputsEveryOutputPlaysAsSet(): void
    {
        $solo = Solo::plan(self::demo(), null, 0.0, Channel::output(8));

        self::assertSame([8, 9, 10, 11, 12, 13, 14, 15], $solo->played);
        self::assertFalse($solo->solo);
        self::assertSame([], $solo->gains);
        self::assertSame(self::expected([Commands::mute(15, false)]), self::hex($solo->setup));
        self::assertSame(self::expected([Commands::mute(15, true)]), self::hex($solo->restore));

        $solo = Solo::plan(self::demo(), null);
        self::assertSame([8, 9, 10, 11, 12, 13, 14], $solo->played, 'Out8 stays muted without a reference');
        self::assertSame([], $solo->setup);
    }

    public function testTheTrimStartsFromEachGainAndStopsAtTheLimits(): void
    {
        // Out4 at +10 dB reaches +12 only, Out5 and Out6 go from -6 to -1 dB
        $solo = Solo::plan(self::demo(), [Channel::output(4), Channel::output(5)], 5.0);

        self::assertSame([11, 12, 13], $solo->played);
        self::assertSame([11 => ['from' => 10.0, 'to' => 12.0], 12 => ['from' => -6.0, 'to' => -1.0], 13 => ['from' => -6.0, 'to' => -1.0]], $solo->gains);
        self::assertContains(Commands::gain(11, Scale::GAIN_MAX)->shortHex(), self::hex($solo->setup));

        $solo = Solo::plan(self::demo(), [Channel::output(7)], -70.0);
        self::assertSame([14 => ['from' => 0.0, 'to' => -60.0]], $solo->gains);
    }

    public function testSetupThenRestoreEndsInTheSnapshot(): void
    {
        $before = self::demo();
        $solo = Solo::plan($before, [Channel::output(3), Channel::output(6)], -20.0, Channel::output(8));
        $device = DemoTransport::fromFile(self::DEMO)->state();

        foreach ($solo->setup as $frame) {
            $device->apply($frame);
        }
        $during = $device->toDevice()->toArray();
        foreach ($solo->restore as $frame) {
            $device->apply($frame);
        }

        self::assertIsArray($during['outputs']);
        self::assertSame([true, true, false, true, false, false, true, false], array_column($during['outputs'], 'mute'));
        self::assertSame($before, $device->toDevice()->toArray());
    }

    public function testWarnsAboutWhatHoldsTheLevelOrColoursTheReference(): void
    {
        $state = self::demo();
        \assert(\is_array($state['outputs']) && \is_array($state['outputs'][2]) && \is_array($state['outputs'][7]) && \is_array($state['outputs'][7]['peq']) && \is_array($state['outputs'][7]['peq'][1]));
        \assert(\is_array($state['groups']) && \is_array($state['sub']) && \is_array($state['aux']));
        $state['outputs'][2]['route'] = 0;
        $state['groups'][4] = ['gain' => 80, 'mute' => false, 'mask' => 0b100];
        $state['sub'][1] = ['gain' => 100, 'mute' => true, 'mask' => 0b1000000];
        $state['aux']['select'] = 3;
        $state['outputs'][7]['delay'] = 48;
        $state['outputs'][7]['peq'][1]['gain'] = 130;
        $state['tone_mode'] = 0;

        $solo = Solo::plan($state, [Channel::output(3), Channel::output(7)], 0.0, Channel::output(8));

        self::assertSame([
            'Out3 has no input routed, so it plays nothing.',
            'Output group 1 holds Out3 at 80 %.',
            'Sub control mutes Out7.',
            'The Aux takes In1+In2 and is not muted. If the test tone enters at the inputs, the Aux plays it too.',
            'The reference output Out8 is not flat (PEQ 2, delay), so its loopback carries these settings.',
        ], $solo->warnings);
    }

    public function testRefusesWhatCannotPlayAlone(): void
    {
        $cases = [
            'linked (Out1, Out2)' => static fn () => Solo::plan(self::demo(), [Channel::output(3)], 0.0, Channel::output(1)),
            'Out8 is the reference output' => static fn () => Solo::plan(self::demo(), [Channel::output(8)], 0.0, Channel::output(8)),
            'Only outputs' => static fn () => Solo::plan(self::demo(), [Channel::input(1)]),
            'no outputs' => static fn () => Solo::plan([], null),
        ];
        foreach ($cases as $message => $plan) {
            try {
                $plan();
                self::fail('Accepted: '.$message);
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }
}
