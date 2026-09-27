<?php

namespace App\Tests\Measurement;

use App\AutoEq\FittedBand;
use App\Measurement\Calibration;
use App\Measurement\Fft;
use App\Measurement\Response;
use App\Measurement\Spectrum;
use App\Measurement\WavReader;
use App\Protocol\Scale;
use App\Tests\Support\Signals;
use PHPUnit\Framework\TestCase;

/**
 * FFT, WAV reading, the fractional-octave spectrum and calibration files.
 */
final class MeasurementTest extends TestCase
{
    public function testFftFindsASineInItsBin(): void
    {
        $fft = new Fft(1024);
        // Bin 64 of 1024 at 48 kHz = 3 kHz
        $power = $fft->power(Signals::sine(3000.0, 1024));

        self::assertCount(513, $power);
        self::assertSame(64, array_search(max($power), $power, true));
        // Amplitude 0.5 over 1024 samples: |X| = 0.5 · 1024 / 2 = 256
        self::assertEqualsWithDelta(256 ** 2, $power[64], 1.0);
        self::assertLessThan(1e-6, $power[10]);
    }

    public function testFftRejectsOtherSizes(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Fft(1000);
    }

    public function testFftNeedsTheExactLength(): void
    {
        $this->expectException(\LengthException::class);

        (new Fft(8))->power([1.0, 2.0]);
    }

    public function testWavFormats(): void
    {
        $left = [0.0, 0.5, -0.5, 1.0];
        $right = [0.25, -0.25, 0.0, -1.0];
        foreach ([[16, false, 1 / 32767], [24, false, 1 / 8388607], [32, false, 1e-9], [32, true, 1e-7]] as [$bits, $float, $delta]) {
            $wav = WavReader::parse(Signals::wav([$left, $right], $bits, $float), 2);

            self::assertSame(48000, $wav['rate'], "$bits bit");
            self::assertSame(2, $wav['channels']);
            self::assertEqualsWithDelta($right, $wav['samples'], 2 * $delta, "$bits bit".($float ? ' float' : ''));
        }
    }

    public function testWavSkipsOtherChunksAndToleratesAnOpenDataSize(): void
    {
        $wav = Signals::wav([[0.5, -0.5]]);
        // A LIST chunk before the data, and a data size of 0 as arecord leaves it when stopped
        $wav = substr($wav, 0, 36).'LIST'.pack('V', 3)."abc\x00".'data'.pack('V', 0).substr($wav, 44);

        self::assertEqualsWithDelta([0.5, -0.5], WavReader::parse($wav)['samples'], 1e-4);
    }

    public function testWavErrors(): void
    {
        foreach (['not a wav' => 'Not a WAV', Signals::wav([[0.1]]) => 'Channel 2'] as $data => $message) {
            try {
                WavReader::parse((string) $data, 2);
                self::fail('Expected an error');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testPinkNoiseReadsFlat(): void
    {
        $samples = Signals::pinkNoise(5 * Scale::SAMPLE_RATE);
        $response = (new Spectrum())->analyse($samples, Scale::SAMPLE_RATE, Response::grid(100.0, 10000.0, 6));

        $mean = $response->mean(100, 10000);
        foreach ($response->db as $i => $db) {
            self::assertEqualsWithDelta($mean, $db, 1.5, \sprintf('%.0f Hz', $response->freqs[$i]));
        }
    }

    public function testFilteredPinkNoiseShowsThePeak(): void
    {
        $band = new FittedBand(Scale::hzToIndex(1000), Scale::dbToPeqGain(6.0), Scale::qToIndex(2.0));
        $samples = Signals::peak(Signals::pinkNoise(5 * Scale::SAMPLE_RATE), $band);
        $response = (new Spectrum())->analyse($samples, Scale::SAMPLE_RATE, Response::grid(100.0, 10000.0, 12));

        self::assertEqualsWithDelta(6.0, $response->at(1000) - $response->mean(100, 200), 1.5);
    }

    public function testSpectrumNeedsOneSegment(): void
    {
        $this->expectException(\LengthException::class);

        (new Spectrum(1024))->analyse([0.0, 0.1], 48000, [1000.0]);
    }

    public function testResponseHelpers(): void
    {
        $grid = Response::grid(100.0, 800.0, 1);
        self::assertSame([100.0, 200.0, 400.0, 800.0], $grid);

        $a = new Response($grid, [0.0, 6.0, 0.0, -6.0]);
        self::assertEqualsWithDelta(3.0, $a->at(sqrt(2) * 100), 1e-9);
        self::assertSame(0.0, $a->at(10.0));
        self::assertSame(-6.0, $a->at(20000.0));
        self::assertSame([6.0, 12.0, 6.0, 0.0], $a->shift(6.0)->db);
        self::assertSame(0.0, $a->mean(100, 800));
        self::assertEqualsWithDelta([3.0, 2.0, 0.0, -3.0], $a->smooth(2)->db, 1e-9);
        self::assertSame([0.0, 0.0, 0.0, 0.0], $a->minus($a)->db);
        // Equal power average of 0 dB and -inf-ish is -3 dB
        $quiet = new Response($grid, [-200.0, -200.0, -200.0, -200.0]);
        self::assertEqualsWithDelta(-3.0103, Response::average([new Response($grid, [0.0, 0.0, 0.0, 0.0]), $quiet])->db[0], 1e-3);
    }

    public function testResponseNeedsMatchingLengths(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Response([1.0, 2.0], [0.0]);
    }

    public function testCalibrationFile(): void
    {
        $cal = Calibration::parse("\"Sens Factor =-1.23dB, SERNO: 7000001\"\n* comment\n20.0 -2.5 12\n1000\t0.0\n20000, 1.5\n");

        self::assertSame([20.0, 1000.0, 20000.0], $cal->freqs);
        self::assertSame([-2.5, 0.0, 1.5], $cal->db);
    }

    public function testCalibrationNeedsTwoPoints(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Calibration::parse("Sens Factor = 1\n1000 0\n");
    }
}
