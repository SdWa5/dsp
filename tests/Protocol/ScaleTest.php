<?php

namespace App\Tests\Protocol;

use App\Protocol\Channel;
use App\Protocol\Scale;
use PHPUnit\Framework\TestCase;

final class ScaleTest extends TestCase
{
    public function testGainScale(): void
    {
        self::assertSame(280, Scale::dbToGain(0));
        self::assertSame(250, Scale::dbToGain(-3));
        self::assertSame(400, Scale::dbToGain(12));
        self::assertSame(-9.5, Scale::gainToDb(185));
        self::assertSame(-50.0, Scale::gainToDb(20));
        self::assertSame(-60.0, Scale::gainToDb(0));
        for ($raw = 0; $raw <= Scale::GAIN_MAX; ++$raw) {
            self::assertSame($raw, Scale::dbToGain(Scale::gainToDb($raw)));
        }
    }

    public function testGainOutOfRange(): void
    {
        $this->expectException(\OutOfRangeException::class);

        Scale::dbToGain(12.5);
    }

    public function testFrequencyIndex(): void
    {
        self::assertSame(0, Scale::hzToIndex(19.7));
        self::assertSame(31, Scale::hzToIndex(40.3));
        self::assertSame(300, Scale::hzToIndex(20160));
        self::assertSame(300, Scale::hzToIndex(99999));
        self::assertSame(0, Scale::hzToIndex(1));
        self::assertEqualsWithDelta(16001, Scale::indexToHz(290), 0.5);
    }

    public function testFormatHzLikeEditor(): void
    {
        // Index => text as the vendor editor shows it (screenshots)
        $editor = [
            0 => '19.7Hz', 1 => '20.1Hz', 25 => '35.1Hz', 31 => '40.3Hz', 41 => '50.8Hz', 49 => '61.1Hz', 71 => '101.5Hz',
            78 => '119.4Hz', 101 => '203.1Hz', 118 => '300.8Hz', 140 => '500.0Hz', 161 => '812.3Hz', 200 => '2.00KHz',
            240 => '5.04KHz', 255 => '7.13KHz', 270 => '10.08KHz', 300 => '20.16KHz',
        ];

        foreach ($editor as $index => $text) {
            self::assertSame($text, Scale::formatHz(Scale::indexToHz($index)), 'Index '.$index);
        }
    }

    public function testPeqGainAndQ(): void
    {
        self::assertSame(120, Scale::dbToPeqGain(0));
        self::assertSame(110, Scale::dbToPeqGain(-1));
        self::assertSame(-6.2, Scale::peqGainToDb(58));
        self::assertEqualsWithDelta(1.70, Scale::indexToQ(25), 0.01);
        self::assertSame(2.5, round(Scale::indexToQ(32), 1));
        self::assertSame(25, Scale::qToIndex(1.7));
        self::assertSame(0, Scale::qToIndex(0.1));
        self::assertSame(100, Scale::qToIndex(500));
    }

    public function testMeterScale(): void
    {
        self::assertSame(0.0, Scale::meterDb(Scale::METER_ZERO));
        self::assertSame(6.02, Scale::meterDb(Scale::METER_ZERO + 128));
        self::assertSame(-27.8, Scale::meterDb(15315), 'The 1 kHz test tone at unity gain');
        self::assertEqualsWithDelta(-12.0, Scale::meterDb(15059) - Scale::meterDb(15315), 0.05, 'A 12 dB step on the device');
        self::assertSame(-90.0, Scale::meterDb(0x05AD), 'Digital silence, for example gain raw 0');
        self::assertSame(-90.0, Scale::meterDb(0));
        self::assertSame(Scale::METER_ZERO, Scale::dbToMeter(0.0));
        self::assertSame(15315, Scale::dbToMeter(-27.8));
    }

    public function testDynamicsAndDelay(): void
    {
        self::assertSame(-90.0, Scale::thresholdToDb(0));
        self::assertSame(0.0, Scale::thresholdToDb(180));
        self::assertSame(20.0, Scale::thresholdToDb(220));
        self::assertSame(180, Scale::dbToThreshold(0));
        self::assertSame(1, Scale::rawToMs(0));
        self::assertSame(2999, Scale::msToRaw(3000));
        self::assertSame('1.333 ms', Scale::delayLabel(64));
        self::assertSame('0.457 m', Scale::delayLabel(64, 1));
        self::assertSame('1.500 ft', Scale::delayLabel(64, 2));
        self::assertSame(64, Scale::msToSamples(1.3333));
    }

    public function testChannels(): void
    {
        self::assertSame(0, Channel::parse('in1'));
        self::assertSame(7, Channel::parse('IN8'));
        self::assertSame(8, Channel::parse('out1'));
        self::assertSame(15, Channel::parse('out8'));
        self::assertSame(Channel::AUX, Channel::parse('aux'));
        self::assertSame('out3', Channel::name(10));
        self::assertSame('In1', Channel::label(0));
        self::assertSame('Aux', Channel::label(Channel::AUX));
        self::assertSame('#13', Channel::label(Channel::CAMERA));
    }

    public function testUnknownChannel(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Channel::parse('out9');
    }
}
