<?php

namespace App\Tests\Measurement;

use App\Measurement\TestTone;
use App\Protocol\Commands;
use PHPUnit\Framework\TestCase;

final class TestToneTest extends TestCase
{
    public function testParsesTheSignals(): void
    {
        self::assertEquals(new TestTone(TestTone::PINK, 17), TestTone::parse('pink', 17));
        self::assertEquals(new TestTone(TestTone::WHITE, 3), TestTone::parse(' White ', 3));
        self::assertEquals(new TestTone(TestTone::ANALOG, 17), TestTone::parse('none', 17));
        self::assertEquals(new TestTone(TestTone::SINE, 7), TestTone::parse('sine:100'));
        self::assertSame(17, TestTone::parse('sine:1k')->freq);
        self::assertSame(18, TestTone::parse('sine:1.25kHz')->freq);
        // 110 Hz lies nearer to 100 Hz than to 125 Hz, 18 Hz and 22 kHz within 1/6 octave of the ends
        self::assertSame(7, TestTone::parse('sine:110')->freq);
        self::assertSame(0, TestTone::parse('sine:18')->freq);
        self::assertSame(30, TestTone::parse('sine:22k')->freq);
    }

    public function testRefusesWhatTheDeviceCannotPlay(): void
    {
        foreach (['sine:15', 'sine:25k', 'sine:', 'sine:abc', 'noise'] as $signal) {
            try {
                TestTone::parse($signal);
                self::fail($signal.' was accepted.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testFrameLabelAndState(): void
    {
        $tone = TestTone::parse('sine:100');

        self::assertSame(Commands::testTone(3, 7)->shortHex(), $tone->frame()->shortHex());
        self::assertSame('Sine Wave 100Hz', $tone->label());
        self::assertSame('Pink Noise', (new TestTone(TestTone::PINK, 7))->label());
        self::assertTrue($tone->plays());
        self::assertFalse((new TestTone(TestTone::ANALOG))->plays());
        self::assertEquals(new TestTone(TestTone::WHITE, 17), TestTone::fromState(['tone_mode' => 2, 'tone_freq' => 17]));
        self::assertEquals(new TestTone(TestTone::ANALOG, 0), TestTone::fromState(['tone_mode' => 9, 'tone_freq' => 99]));
        self::assertEqualsWithDelta(1000.0, TestTone::frequency(17), 1e-9);
        self::assertEqualsWithDelta(19.95, TestTone::frequency(0), 0.01);
    }

    public function testRejectsAModeOutOfRange(): void
    {
        $this->expectException(\OutOfRangeException::class);

        new TestTone(4);
    }
}
