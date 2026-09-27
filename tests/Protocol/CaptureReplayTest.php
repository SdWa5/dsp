<?php

namespace App\Tests\Protocol;

use App\Protocol\Commands;
use App\Protocol\Frame;
use App\Protocol\Opcode;
use App\Tests\Support\UsbPcapReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Replays every frame the vendor editor sent in captures/*.pcap: the builders in Commands
 * must reproduce each one byte for byte from the decoded arguments.
 */
final class CaptureReplayTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function captures(): iterable
    {
        foreach (glob(\dirname(__DIR__, 2).'/captures/*.pcap') ?: [] as $path) {
            yield basename($path, '.pcap') => [$path];
        }
    }

    #[DataProvider('captures')]
    public function testBuildersReproduceEditorFrames(string $path): void
    {
        self::replay($path);
        // Some captures hold no editor command at all, the frame checks above are the assertions
        $this->addToAssertionCount(1);
    }

    /**
     * Guards against a reader that silently finds nothing: the captures hold 4830 editor frames.
     */
    public function testAllCapturesTogetherCoverTheEditorTraffic(): void
    {
        $checked = array_sum(array_map(static fn (array $capture): int => self::replay($capture[0]), iterator_to_array(self::captures())));

        self::assertGreaterThanOrEqual(4830, $checked);
    }

    /**
     * @return int number of frames rebuilt and compared
     */
    private static function replay(string $path): int
    {
        $checked = 0;
        foreach (UsbPcapReader::reports($path) as $report) {
            $frame = Frame::decode($report['data']) ?? Frame::decode(substr($report['data'], 1));
            self::assertNotNull($frame, 'Every report in the capture carries a valid frame.');
            if ($report['in']) {
                self::assertTrue($frame->isFromDsp());
                continue;
            }
            self::assertNotNull($frame->opcode(), \sprintf('Unknown command %02X in %s.', $frame->cmd, basename($path)));
            $rebuilt = self::rebuild($frame);
            if (null !== $rebuilt) {
                self::assertSame($frame->hex(), $rebuilt->hex(), \sprintf('Builder for %s differs.', $frame->opcode()->name));
                ++$checked;
            }
        }

        return $checked;
    }

    /**
     * Decodes the arguments of a PC → DSP frame and builds it again, null for commands
     * without a builder (queries whose only content is the opcode are rebuilt too).
     */
    private static function rebuild(Frame $f): ?Frame
    {
        $d = $f->data;
        $b = static fn (int $i): int => \ord($d[$i]);
        $w = static fn (int $i): int => \ord($d[$i]) | \ord($d[$i + 1]) << 8;

        return match ($f->opcode()) {
            Opcode::Gain => Commands::gain($b(0), $w(1)),
            Opcode::Mute => Commands::mute($b(0), 0 !== $b(1)),
            Opcode::Phase => Commands::phase($b(0), 0 !== $b(1)),
            Opcode::Routing => Commands::routing($b(0), $w(1)),
            Opcode::HighPass => Commands::highPass($b(0), $w(1), $b(3)),
            Opcode::LowPass => Commands::lowPass($b(0), $w(1), $b(3)),
            Opcode::Peq => Commands::peq($b(0), $b(1), $w(2), $w(4), $b(6), $b(7), 0 !== $b(8)),
            Opcode::EqBypass => Commands::eqBypass($b(0), 0 !== $b(1)),
            Opcode::Gate => Commands::gate($b(0), $w(1), $w(3), $w(5)),
            Opcode::Compressor => Commands::compressor($b(0), $b(1), $b(2), $w(3), $w(5), $w(7)),
            Opcode::Delay => Commands::delay($b(0), $w(1)),
            Opcode::DelayUnit => Commands::delayUnit($b(0)),
            Opcode::Link => Commands::link($b(0), $b(1)),
            Opcode::CopyChannel => Commands::copyChannel($b(0), $b(1)),
            Opcode::ChannelName => Commands::channelName($b(0), rtrim(substr($d, 1), "\x00")),
            Opcode::InputMode => Commands::inputMode($b(0), $b(1)),
            Opcode::Phantom => Commands::phantom($b(0), 0 !== $b(1)),
            Opcode::Fbq => Commands::fbq($b(0), $b(1)),
            Opcode::MixFunction => Commands::mixFunction($b(0), $w(1), $w(3), $w(5), $w(7), 0 !== $w(9)),
            Opcode::AutoMixSlope => Commands::autoMixSlope($b(0)),
            Opcode::Effect => Commands::effect(0 !== $b(0), $b(1)),
            Opcode::Echo => Commands::echo($w(0), $w(2), $w(4), $w(6)),
            Opcode::Reverb => Commands::reverb($w(0), $w(2), $w(4), $w(6)),
            Opcode::SubControl => Commands::subControl($b(0), $b(1), 0 !== $b(2), $b(3), $b(4)),
            Opcode::Group => Commands::group($b(0), $b(1), 0 !== $b(2), $b(3)),
            Opcode::Groups => Commands::readGroups(),
            Opcode::TestTone => Commands::testTone($b(0), $b(1)),
            Opcode::WorkingStatus => Commands::workingStatus($b(0)),
            Opcode::DeviceId => Commands::deviceId($b(0)),
            Opcode::ReadConfigPage => Commands::readConfigPage($b(0)),
            Opcode::PresetName => Commands::readPresetName($b(0)),
            Opcode::Meters => Commands::meters(),
            Opcode::Init => Commands::init(),
            Opcode::Activate => Commands::activate(),
            Opcode::DeviceStatus => Commands::deviceStatus(),
            default => null === $f->opcode() || '' !== $d ? null : Frame::request($f->opcode()),
        };
    }
}
