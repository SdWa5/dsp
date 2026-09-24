<?php

namespace App\Tests\Protocol;

use App\Protocol\Commands;
use App\Protocol\Frame;
use App\Protocol\FrameBuffer;
use App\Protocol\Opcode;
use App\Protocol\Scale;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FrameTest extends TestCase
{
    /**
     * Frames the vendor editor sent, taken from the USB captures.
     *
     * @return iterable<string, array{string, Frame}>
     */
    public static function editorFrames(): iterable
    {
        yield 'mute In1' => ['10 02 00 01 03 35 00 01 10 03 37', Commands::mute(0, true)];
        yield 'phase Out1' => ['10 02 00 01 03 36 08 01 10 03 3c', Commands::phase(8, true)];
        yield 'gain Out1 0 dB' => ['10 02 00 01 04 34 08 18 01 10 03 21', Commands::gainDb(8, 0)];
        yield 'route Out3' => ['10 02 00 01 04 3a 0a 0f 00 10 03 3b', Commands::routing(0x0A, 0x0F)];
        yield 'peq Out1' => [
            '10 02 00 01 0a 33 08 00 6e 00 1f 00 19 00 00 10 03 59',
            Commands::peq(8, 0, Scale::dbToPeqGain(-1.0), Scale::hzToIndex(40.3), 0x19, 0, false),
        ];
        yield 'hpf Out1' => ['10 02 00 01 05 32 08 00 00 12 10 03 2d', Commands::highPass(8, Scale::hzToIndex(19.7), 0x12)];
        yield 'meters' => ['10 02 00 01 01 40 10 03 41', Commands::meters()];
    }

    #[DataProvider('editorFrames')]
    public function testFramesMatchEditor(string $expected, Frame $frame): void
    {
        self::assertSame($expected, $frame->hex());
        self::assertEquals($frame, Frame::decode($frame->encode()));
    }

    public function testDecodeIgnoresReportPadding(): void
    {
        $report = str_pad(Commands::mute(3, false)->encode(), 64, "\x00");

        self::assertEquals(Commands::mute(3, false), Frame::decode($report));
    }

    public function testDecodeRejectsBadChecksum(): void
    {
        $raw = Commands::mute(0, true)->encode();
        $raw[\strlen($raw) - 1] = \chr(\ord($raw[\strlen($raw) - 1]) ^ 0xFF);

        self::assertNull(Frame::decode($raw));
    }

    public function testDecodeRejectsTruncatedFrame(): void
    {
        self::assertNull(Frame::decode(substr(Commands::mute(0, true)->encode(), 0, -1)));
        self::assertNull(Frame::decode('garbage'));
    }

    public function testShortHexAndOpcode(): void
    {
        $frame = Commands::gain(8, 0x118);

        self::assertSame('34 08 18 01', $frame->shortHex());
        self::assertSame(Opcode::Gain, $frame->opcode());
        self::assertFalse($frame->isFromDsp());
    }

    public function testBufferSplitsStreamAndSkipsGarbage(): void
    {
        $reply = Frame::reply(Opcode::DeviceInfo, '8x8Matrix V010')->encode();
        $ack = Frame::reply(Opcode::Ack)->encode();
        $buffer = new FrameBuffer();

        $buffer->append("\x00\x10\xff".substr($reply, 0, 5));
        self::assertNull($buffer->next());
        $buffer->append(substr($reply, 5).$ack);

        self::assertSame('8x8Matrix V010', $buffer->next()?->data);
        self::assertSame(Opcode::Ack->value, $buffer->next()?->cmd);
        self::assertNull($buffer->next());
    }

    public function testBufferKeepsHalfStartMarker(): void
    {
        $ack = Frame::reply(Opcode::Ack)->encode();
        $buffer = new FrameBuffer();

        $buffer->append("\xAA\x10");
        self::assertNull($buffer->next());
        $buffer->append(substr($ack, 1));

        self::assertSame(Opcode::Ack->value, $buffer->next()?->cmd);
    }

    public function testBufferResyncsAfterCorruptFrame(): void
    {
        $bad = Commands::mute(0, true)->encode();
        $bad[\strlen($bad) - 1] = "\x00";
        $buffer = new FrameBuffer();

        $buffer->append($bad.Frame::reply(Opcode::Ack)->encode());

        self::assertSame(Opcode::Ack->value, $buffer->next()?->cmd);
    }

    public function testStorePresetRefusesFactoryDefault(): void
    {
        $this->expectException(\OutOfRangeException::class);

        Commands::storePreset(0);
    }

    public function testPinMustBeDigits(): void
    {
        self::assertSame('2f 31 32 30 30', Commands::setPin('12')->shortHex());
        self::assertSame('2d 00 31 32 33 34', Commands::unlock('1234')->shortHex());

        $this->expectException(\InvalidArgumentException::class);
        Commands::setPin('12a');
    }

    public function testNamesArePaddedAndSanitised(): void
    {
        self::assertSame('3d 0f 4f 75 74 38 00 00 00 00', Commands::channelName(0x0F, 'Out8')->shortHex());
        self::assertSame(15, \strlen(Commands::presetName('Bühne')->data) + 1);
        self::assertSame('B?hne         ', Commands::presetName('Bühne')->data);
    }
}
