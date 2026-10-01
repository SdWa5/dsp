<?php

namespace App\Tests\Model;

use App\Model\Device;
use App\Model\InputChannel;
use App\Model\OutputChannel;
use App\Protocol\Channel;
use App\Protocol\Commands;
use App\Protocol\Frame;
use App\Protocol\Labels;
use App\Protocol\Opcode;
use App\Protocol\Scale;
use PHPUnit\Framework\TestCase;

/**
 * The model against the vendor editor display (configuration of 2026-09-24, preset U02 "Bulgaria26").
 */
final class DeviceTest extends TestCase
{
    private static string $config;
    private Device $dev;

    public static function setUpBeforeClass(): void
    {
        self::$config = (string) file_get_contents(__DIR__.'/../fixtures/config.bin');
    }

    protected function setUp(): void
    {
        $this->dev = Device::parse(self::$config);
    }

    public function testHeaderAndNames(): void
    {
        self::assertSame('Bulgaria26', $this->dev->presetName);
        self::assertSame(array_map(static fn (int $i): string => 'In'.$i, range(1, 8)), array_map(static fn (InputChannel $c): string => $c->name, $this->dev->inputs));
        self::assertSame(array_map(static fn (int $i): string => 'Out'.$i, range(1, 8)), array_map(static fn (OutputChannel $c): string => $c->name, $this->dev->outputs));
        self::assertSame('Aux', $this->dev->aux->name);
    }

    public function testGains(): void
    {
        self::assertSame([12.0, 12.0, 8.0, 8.0, -60.0, -60.0, -60.0, -60.0], array_map(static fn (InputChannel $c): float => Scale::gainToDb($c->gain), $this->dev->inputs));
        self::assertSame(0.0, Scale::gainToDb($this->dev->outputs[0]->gain));
        self::assertSame(5.0, Scale::gainToDb($this->dev->outputs[2]->gain));
        self::assertSame(10.0, Scale::gainToDb($this->dev->outputs[3]->gain));
        self::assertSame(0.0, Scale::gainToDb($this->dev->aux->gain));
    }

    public function testMutes(): void
    {
        self::assertSame([true, true, false, false, false, false, false, false], array_map(static fn (InputChannel $c): bool => $c->mute, $this->dev->inputs));
        self::assertSame([false, false, false, false, false, false, false, true], array_map(static fn (OutputChannel $c): bool => $c->mute, $this->dev->outputs));
    }

    public function testRouting(): void
    {
        self::assertSame(0xFC, $this->dev->outputs[0]->route);
        self::assertSame(0x0F, $this->dev->outputs[2]->route);
        self::assertSame(0x03, $this->dev->outputs[3]->route);
    }

    public function testFilters(): void
    {
        // Out3: HPF 20.1 Hz LK-48, LPF 119.4 Hz LK-24
        $out3 = $this->dev->outputs[2];
        self::assertSame('20.1Hz', Scale::formatHz(Scale::indexToHz($out3->hpf->freq)));
        self::assertSame('LK -48', Labels::SLOPES[$out3->hpf->slope]);
        self::assertSame('119.4Hz', Scale::formatHz(Scale::indexToHz($out3->lpf->freq)));
        self::assertSame('LK -24', Labels::SLOPES[$out3->lpf->slope]);
        self::assertSame('Bypass', Labels::SLOPES[$this->dev->outputs[0]->hpf->slope]);
    }

    public function testPeq(): void
    {
        // Out4 band 2: 122.1 Hz (the formula gives 122.2 Hz), Q 2.50, -6.2 dB; In1 band 1: 61.1 Hz
        $band = $this->dev->outputs[3]->peq[1];
        self::assertSame(79, $band->freq);
        self::assertSame(2.5, round(Scale::indexToQ($band->q), 1));
        self::assertSame(-6.2, Scale::peqGainToDb($band->gain));
        self::assertSame('Peak', Labels::PEQ_TYPES[$band->type]);
        self::assertSame(61.1, round(Scale::indexToHz($this->dev->inputs[0]->peq[0]->freq), 1));
        self::assertSame(50.8, round(Scale::indexToHz($this->dev->aux->peq[0]->freq), 1));
    }

    public function testDynamics(): void
    {
        $gate = $this->dev->inputs[0]->gate;
        self::assertSame([1, 3000, -90.0], [Scale::rawToMs($gate->attack), Scale::rawToMs($gate->release), Scale::thresholdToDb($gate->threshold)]);
        $c1 = $this->dev->outputs[0]->comp;
        $c3 = $this->dev->outputs[2]->comp;
        self::assertSame('Limit', Labels::COMP_RATIOS[$c1->ratio]);
        self::assertSame(0.0, Scale::thresholdToDb($c1->threshold));
        self::assertSame([1, 3000], [Scale::rawToMs($c1->attack), Scale::rawToMs($c1->release)]);
        self::assertSame('1:1.0', Labels::COMP_RATIOS[$c3->ratio]);
        self::assertSame(20.0, Scale::thresholdToDb($c3->threshold));
        self::assertSame(20.0, Scale::thresholdToDb($this->dev->aux->comp->threshold));
    }

    public function testDelayPhaseLink(): void
    {
        self::assertSame('1.333 ms', Scale::delayLabel($this->dev->outputs[4]->delay));
        self::assertSame(0, $this->dev->outputs[0]->delay);
        self::assertTrue($this->dev->outputs[0]->phase);
        self::assertSame([3, 0, 12, 0, 48, 0, 192, 0], array_map(static fn (InputChannel $c): int => $c->link, $this->dev->inputs));
    }

    public function testJsonRoundTrip(): void
    {
        $this->dev->inputs[0]->phantom = true;
        $this->dev->workingStatus = 1;

        $copy = Device::fromJson($this->dev->toJson());

        self::assertSame($this->dev->raw, $copy->raw);
        self::assertTrue($copy->inputs[0]->phantom);
        self::assertSame(1, $copy->workingStatus);
        self::assertEquals($this->dev->outputs[3]->peq[1], $copy->outputs[3]->peq[1]);
    }

    public function testJsonMatchesPythonFileFormat(): void
    {
        $data = json_decode($this->dev->toJson(), true);

        self::assertSame('trackdsp-8x8', $data['format']);
        self::assertSame(1, $data['version']);
        self::assertSame(
            ['preset_name', 'inputs', 'outputs', 'aux', 'footer', 'sub', 'groups', 'sub_extra', 'tone_mode', 'tone_freq',
                'delay_unit', 'working_status', 'device_id', 'ip', 'active_preset', 'preset_names', 'raw'],
            array_keys($data['device']),
        );
        self::assertSame(['name', 'gate', 'peq', 'hpf', 'lpf', 'gain', 'phase', 'link', 'mute', 'mode', 'phantom', 'fbq', 'eq_bypass'], array_keys($data['device']['inputs'][0]));
        self::assertArrayHasKey('rev_predelay', $data['device']['aux']['effect']);
        self::assertCount(63, $data['device']['footer']);
    }

    public function testUploadEndsWithActivateAndSkipsMissingGroups(): void
    {
        $frames = $this->dev->allFrames();
        $last = end($frames);
        self::assertInstanceOf(Frame::class, $last);
        self::assertSame(Commands::activate()->hex(), $last->hex());
        $groupFrames = static fn (Device $d): int => \count(array_filter($d->allFrames(), static fn (Frame $f): bool => Opcode::Group === $f->opcode()));
        self::assertSame(8, $groupFrames(Device::fromJson($this->dev->toJson())));

        $data = json_decode($this->dev->toJson(), true);
        unset($data['device']['groups']);
        self::assertSame(0, $groupFrames(Device::fromJson((string) json_encode($data))));
    }

    public function testRejectsForeignJson(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Device::fromJson('{"format": "other"}');
    }

    public function testAllFramesMatchEditorFormat(): void
    {
        $frames = array_map(static fn (Frame $f): string => $f->hex(), $this->dev->allFrames());

        // Frames the editor sent during the tests (initial values)
        foreach ([
            Commands::compressor(0x0F, 0, 0, 0x31, 0xC7, 0xDC),
            Commands::gate(0, 0, 0xBB7, 0),
            Commands::peq(Channel::AUX, 0, 0x78, 0x29, 0x19, 0, false),
            Commands::lowPass(0, 0x12C, 0),
            Commands::channelName(0x0F, 'Out8'),
        ] as $expected) {
            self::assertContains($expected->hex(), $frames);
        }
    }

    public function testGroupsAndTone(): void
    {
        $this->dev->setGroups((string) hex2bin('6400c064000364000c64000055000c640000640000640000'));

        self::assertSame([100, 0xC0], [$this->dev->groups[0]->gain, $this->dev->groups[0]->mask]);
        self::assertSame(0x03, $this->dev->groups[1]->mask);
        self::assertSame([85, 0x0C], [$this->dev->groups[4]->gain, $this->dev->groups[4]->mask]);
        self::assertSame([2, 0], [$this->dev->toneMode, $this->dev->toneFreq]);
    }

    public function testToneFrequencyFromTheFooter(): void
    {
        $config = self::$config;
        $config[Device::FOOTER_BASE + 50] = \chr(12);
        self::assertSame(12, Device::parse($config)->toneFreq);
        // Past the 31 frequencies the model keeps its default of 1 kHz
        $config[Device::FOOTER_BASE + 50] = \chr(31);
        self::assertSame(17, Device::parse($config)->toneFreq);
    }

    public function testFooterStates(): void
    {
        $channels = [...$this->dev->inputs, ...$this->dev->outputs];
        self::assertSame(array_fill(0, 8, 0), array_map(static fn (InputChannel $c): ?int => $c->mode, $this->dev->inputs));
        self::assertSame(array_fill(0, 8, 0), array_map(static fn (InputChannel $c): ?int => $c->fbq, $this->dev->inputs));
        foreach ($channels as $c) {
            self::assertFalse($c->eqBypass);
            foreach ($c->peq as $band) {
                self::assertFalse($band->bypass);
            }
        }
        self::assertFalse($this->dev->aux->mute);
        self::assertSame([100, 100, 0], [$this->dev->sub[0]->gain, $this->dev->sub[1]->gain, $this->dev->sub[1]->mask]);
        self::assertSame(0, $this->dev->delayUnit);
        self::assertFalse($this->dev->aux->effect->on);
        self::assertSame(0, $this->dev->aux->automixSlope);
    }

    public function testFooterOffsetsFromMapping(): void
    {
        // Offsets from the editor tests (captures/state_map.json), applied to a copy
        $f = Device::FOOTER_BASE;
        $patch = [
            16 + 10 => 2,           // In1 FBQ 2
            16 + 62 + 9 => 1,       // In2 Mic
            $f + 56 => 0b11,        // EQ bypass In1 + In2
            $f + 8 => 0b10,         // In2 band 2 bypass
            $f + 58 => 0x80,        // EQ bypass Out8
            $f + 38 => 0b100,       // Out8 band 3 bypass
            $f + 2 => 1,            // Aux mute
            512 + 10 => 1,          // effect on
            512 + 16 => 1,          // AutoMix -4 dB
            $f + 52 => 1,           // delay in m
            $f + 45 => 1,           // sub out mute
        ];
        $config = self::$config;
        foreach ($patch as $offset => $value) {
            $config[$offset] = \chr($value);
        }

        $d = Device::parse($config);

        self::assertSame(2, $d->inputs[0]->fbq);
        self::assertSame(1, $d->inputs[1]->mode);
        self::assertTrue($d->inputs[0]->eqBypass && $d->inputs[1]->eqBypass && $d->outputs[7]->eqBypass);
        self::assertTrue($d->inputs[1]->peq[1]->bypass && $d->outputs[7]->peq[2]->bypass);
        self::assertTrue($d->aux->mute && $d->aux->effect->on && $d->sub[1]->mute);
        self::assertSame([1, 1], [$d->aux->automixSlope, $d->delayUnit]);
    }

    public function testShortConfigIsRejected(): void
    {
        $this->expectException(\LengthException::class);

        Device::parse(str_repeat("\x00", 100));
    }

    private static function vendorFile(): string
    {
        return (string) file_get_contents(__DIR__.'/../fixtures/vendor/SdWa5_Default_Preset_3_DSPs.prs');
    }

    private static function factory(): string
    {
        return (string) file_get_contents(__DIR__.'/../../resources/factory_config.bin');
    }

    public function testReadsAVendorPresetFile(): void
    {
        $file = self::vendorFile();
        self::assertTrue(Device::isVendorPreset($file));
        self::assertFalse(Device::isVendorPreset('{"format":"trackdsp-8x8"}'));

        $d = Device::fromVendorPreset($file, self::factory());

        self::assertSame('Default Preset', $d->presetName);
        self::assertSame(['LoopInL', 'LoopInR', 'Con1L', 'Con1R', 'Con2L', 'Con2R', 'Con3L', 'Con3R'], array_map(static fn (InputChannel $c): string => $c->name, $d->inputs));
        self::assertSame(['BusL', 'BusR', 'SubBR', 'SubFH', 'DSP2_L', 'DSP2_R', 'DSP3_L', 'DSP3_R'], array_map(static fn (OutputChannel $c): string => $c->name, $d->outputs));
        self::assertSame('Aux', $d->aux->name);
        // Out1 takes the left channels In3, In5, In7 and Out2 the right ones
        self::assertSame(0b01010100, $d->outputs[0]->route);
        self::assertSame(0b10101000, $d->outputs[1]->route);
        self::assertSame(370, $d->outputs[2]->gain);
        self::assertSame(240, $d->outputs[2]->delay);
        // In1+In2, In3+In4, … are linked
        self::assertSame([3, 0, 12, 0, 48, 0, 192, 0], array_map(static fn (InputChannel $c): int => $c->link, $d->inputs));
    }

    public function testAVendorPresetLeavesWhatItDoesNotCarry(): void
    {
        $d = Device::fromVendorPreset(self::vendorFile(), self::factory());

        self::assertNull($d->toneMode);
        self::assertNull($d->delayUnit);
        self::assertNull($d->aux->eqBypass);
        foreach ([...$d->inputs, ...$d->outputs] as $channel) {
            self::assertNull($channel->eqBypass);
        }
        $opcodes = array_map(static fn (Frame $f): ?Opcode => $f->opcode(), $d->allFrames());
        self::assertNotContains(Opcode::EqBypass, $opcodes);
        self::assertNotContains(Opcode::DelayUnit, $opcodes);
    }

    public function testRejectsAWrongVendorPresetFile(): void
    {
        $file = self::vendorFile();
        foreach (['magic' => 'x'.substr($file, 1), 'short' => substr($file, 0, 1000), 'long' => $file.'tail'] as $case => $broken) {
            try {
                Device::fromVendorPreset($broken, self::factory());
                self::fail('Accepted a file that is '.$case);
            } catch (\InvalidArgumentException|\LengthException) {
                self::addToAssertionCount(1);
            }
        }
        // Without the trailing CR LF the file is still whole
        self::assertSame('Default Preset', Device::fromVendorPreset(substr($file, 0, -2), self::factory())->presetName);
    }
}
