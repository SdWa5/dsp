<?php

namespace App\Tests\Measurement;

use App\AutoEq\FittedBand;
use App\Measurement\Polarity;
use App\Measurement\Transfer;
use App\Measurement\TransferFunction;
use App\Protocol\Scale;
use App\Tests\Support\Signals;
use PHPUnit\Framework\TestCase;

/**
 * The dual-channel FFT on synthetic signals. Most cases run with smaller FFTs than the real ones, which only
 * shortens the lag range, so that the suite stays fast. One case runs at the real sizes.
 */
final class TransferFunctionTest extends TestCase
{
    private const RATE = Scale::SAMPLE_RATE;
    private const FREQS = [100, 300, 1000, 3000, 8000];

    /** @var list<float>|null */
    private static ?array $reference = null;

    public function testAPureDelay(): void
    {
        $transfer = self::small(Signals::delay(self::reference(), 480));

        self::assertSame(480, $transfer->lag);
        self::assertEqualsWithDelta(480.0, $transfer->delay, 0.1);
        self::assertEqualsWithDelta(10.0, $transfer->delayMs(), 0.002);
        self::assertSame(Polarity::Normal, $transfer->polarity);
        self::assertGreaterThan(0.99, $transfer->meanCoherence());
        foreach (self::FREQS as $hz) {
            self::assertEqualsWithDelta(0.0, $transfer->at($hz)['db'], 0.2, "$hz Hz");
            self::assertEqualsWithDelta(0.0, $transfer->at($hz)['phase'], 2.0, "$hz Hz");
        }
        foreach ($transfer->bands as $band) {
            if ($band['hz'] >= 50 && $band['hz'] <= 16000) {
                self::assertEqualsWithDelta(0.0, $band['db'], 0.2, $band['label']);
                self::assertEqualsWithDelta(0.0, $band['phase'], 2.0, $band['label']);
            }
        }
    }

    public function testAFractionalDelay(): void
    {
        $transfer = self::small(Signals::delay(self::reference(), 100.3));

        self::assertSame(100, $transfer->lag);
        self::assertEqualsWithDelta(100.3, $transfer->delay, 0.1);
        // The phase leaves out the measured delay, so it stays near 0° up high as well
        self::assertEqualsWithDelta(0.0, $transfer->at(8000)['phase'], 2.0);
    }

    public function testTheMeasuredChannelAhead(): void
    {
        $transfer = self::small(Signals::delay(self::reference(), -200));

        self::assertSame(-200, $transfer->lag);
        self::assertEqualsWithDelta(-200.0, $transfer->delay, 0.1);
    }

    public function testAnInvertedSignal(): void
    {
        $transfer = self::small(array_map(static fn (float $v): float => -0.5 * $v, Signals::delay(self::reference(), 48)));

        self::assertSame(Polarity::Inverted, $transfer->polarity);
        self::assertEqualsWithDelta(180.0, abs((float) $transfer->peakPhase), 2.0);
        self::assertEqualsWithDelta(1.0, $transfer->delayMs(), 0.002);
        self::assertEqualsWithDelta(-6.02, $transfer->at(1000)['db'], 0.2);
        self::assertEqualsWithDelta(180.0, abs($transfer->at(1000)['phase']), 2.0);
    }

    public function testAPeakingFilter(): void
    {
        $band = new FittedBand(Scale::hzToIndex(1000), Scale::dbToPeqGain(6.0), Scale::qToIndex(1.4));
        $transfer = self::small(Signals::peak(self::reference(), $band));

        self::assertEqualsWithDelta(0.0, $transfer->delay, 0.1);
        self::assertSame(Polarity::Normal, $transfer->polarity);
        foreach ([200, 500, 700, 1000, 1400, 2000, 3000] as $hz) {
            $bin = $transfer->freqs[(int) round($hz / $transfer->freqs[1])];
            self::assertEqualsWithDelta($band->responseAt($bin), $transfer->at($hz)['db'], 0.2, "$hz Hz");
            self::assertEqualsWithDelta($band->phaseAt($bin), $transfer->at($hz)['phase'], 2.0, "$hz Hz");
        }
    }

    public function testUnrelatedSignals(): void
    {
        $transfer = self::small(Signals::pinkNoise(2 * self::RATE, 9));

        self::assertLessThan(0.3, $transfer->meanCoherence());
        self::assertNull($transfer->delay);
        self::assertNull($transfer->delayMs());
        self::assertSame(Polarity::Unclear, $transfer->polarity);
        self::assertSame([], array_filter(array_column($transfer->bands, 'phase'), static fn (?float $p): bool => null !== $p));
    }

    public function testTheCoherentRangeOfABandLimitedWay(): void
    {
        // A second-order band pass around 1 kHz, like one way of a speaker behind its crossover
        $band = new FittedBand(Scale::hzToIndex(1000), Scale::dbToPeqGain(0.0), Scale::qToIndex(2.0));
        $transfer = self::small(self::bandPass(self::reference(), $band->hz(), 2.0));
        $range = $transfer->coherentRange();

        self::assertNotNull($range);
        // The noise 20 dB down wins where the band pass has fallen by more than that, about 2.2 octaves out
        self::assertEqualsWithDelta(4.4, $range['octaves'], 0.5);
        self::assertGreaterThanOrEqual(0.5, $range['coherence']);
        self::assertNull(self::small(Signals::pinkNoise(2 * self::RATE, 9))->coherentRange());
    }

    public function testALongDelayAtTheRealSizes(): void
    {
        $transfer = (new TransferFunction())->analyse(self::reference(), Signals::delay(self::reference(), 1400), self::RATE);

        self::assertSame(1400, $transfer->lag);
        self::assertEqualsWithDelta(1400.0, $transfer->delay, 0.1);
        self::assertCount(TransferFunction::SIZE / 2 + 1, $transfer->freqs);
        self::assertGreaterThanOrEqual(TransferFunction::MIN_SEGMENTS, $transfer->segments);
        foreach (self::FREQS as $hz) {
            self::assertEqualsWithDelta(0.0, $transfer->at($hz)['db'], 0.1, "$hz Hz");
        }
    }

    public function testTheReferenceIsChecked(): void
    {
        $quiet = array_map(static fn (float $v): float => 0.001 * $v, self::reference());
        $transfer = (new TransferFunction(8192, 2048))->analyse($quiet, $quiet, self::RATE);
        self::assertStringContainsString('below -50 dBFS', $transfer->warnings[0] ?? '');

        $clipped = self::reference();
        $clipped[1000] = 1.0;
        foreach ([[$clipped, 'clips in 1 samples'], [array_fill(0, 2 * self::RATE, 0.0), 'silent']] as [$reference, $message]) {
            try {
                self::small(array_values($reference), array_values($reference));
                self::fail('Expected an error');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testTheRecordingMustBeLongEnough(): void
    {
        $this->expectException(\LengthException::class);
        $this->expectExceptionMessage('8 segments');

        // Long enough for the first pass, too short for eight segments once the lag is taken off
        $reference = \array_slice(self::reference(), 0, 10000);
        self::small(Signals::delay($reference, 1000), $reference);
    }

    public function testTheSizesAreChecked(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TransferFunction(4096, 16384);
    }

    public function testToArray(): void
    {
        $data = self::small(Signals::delay(self::reference(), 48))->toArray();

        self::assertSame(48, $data['lag']);
        self::assertSame(48.0, $data['delay']);
        self::assertSame(1.0, $data['delay_ms']);
        self::assertSame('normal', $data['polarity']);
        self::assertCount(31, $data['bands']);
        self::assertSame(['hz', 'label', 'db', 'phase', 'coherence'], array_keys($data['bands'][17]));
        self::assertIsArray($data['coherent']);
        self::assertSame(['octaves', 'coherence', 'from', 'to'], array_keys($data['coherent']));
        self::assertSame(['20Hz', '20kHz'], [$data['coherent']['from'], $data['coherent']['to']]);
        self::assertEqualsWithDelta(1.0, $data['coherence'], 0.01);
    }

    public function testPolarityFromPhase(): void
    {
        foreach ([[0.0, Polarity::Normal], [44.0, Polarity::Normal], [-44.0, Polarity::Normal], [360.0, Polarity::Normal], [90.0, Polarity::Unclear], [180.0, Polarity::Inverted], [-179.0, Polarity::Inverted], [540.0, Polarity::Inverted]] as [$degrees, $polarity]) {
            self::assertSame($polarity, Polarity::fromPhase($degrees), "{$degrees}°");
        }
        self::assertSame(Polarity::Unclear, Polarity::fromPhase(null));
    }

    /**
     * @param list<float>      $measured
     * @param list<float>|null $reference
     */
    private static function small(array $measured, ?array $reference = null): Transfer
    {
        return (new TransferFunction(8192, 2048))->analyse($reference ?? self::reference(), $measured, self::RATE);
    }

    /**
     * RBJ band pass with 0 dB peak gain, plus a little independent noise, which the coherence needs to fall off
     * outside the band.
     *
     * @param list<float> $samples
     *
     * @return list<float>
     */
    private static function bandPass(array $samples, float $hz, float $q): array
    {
        $w0 = 2 * M_PI * $hz / self::RATE;
        $alpha = sin($w0) / (2 * $q);
        $a0 = 1 + $alpha;
        [$b0, $b2, $a1, $a2] = [$alpha / $a0, -$alpha / $a0, -2 * cos($w0) / $a0, (1 - $alpha) / $a0];
        [$x1, $x2, $y1, $y2] = [0.0, 0.0, 0.0, 0.0];
        $noise = Signals::pinkNoise(\count($samples), 11);
        $out = [];
        foreach ($samples as $i => $x) {
            $y = $b0 * $x + $b2 * $x2 - $a1 * $y1 - $a2 * $y2;
            [$x2, $x1, $y2, $y1] = [$x1, $x, $y1, $y];
            $out[] = $y + 0.1 * $noise[$i];
        }

        return $out;
    }

    /**
     * @return list<float>
     */
    private static function reference(): array
    {
        return self::$reference ??= Signals::pinkNoise(2 * self::RATE, 3);
    }
}
