<?php

namespace App\Tests\Filter;

use App\AutoEq\FittedBand;
use App\Filter\Biquad;
use App\Protocol\Scale;
use PHPUnit\Framework\TestCase;

final class BiquadTest extends TestCase
{
    public function testAPeak(): void
    {
        $peak = Biquad::peq(0, 1000, 6.0, 1.4);

        self::assertEqualsWithDelta(6.0, $peak->db(1000), 1e-9);
        self::assertEqualsWithDelta(0.0, $peak->degrees(1000), 1e-9);
        self::assertEqualsWithDelta(0.0, $peak->db(20), 0.01);
        self::assertGreaterThan(5.0, $peak->degrees(700));
        self::assertLessThan(-5.0, $peak->degrees(1400));
        $cut = Biquad::peq(0, 1000, -6.0, 1.4);
        self::assertEqualsWithDelta(-$peak->db(700), $cut->db(700), 1e-9);
        self::assertEqualsWithDelta(-$peak->degrees(700), $cut->degrees(700), 1e-9);
        foreach ([20, 1000, 20000] as $hz) {
            self::assertSame([1.0, 0.0], Biquad::peq(0, 1000, 0.0, 1.4)->at($hz));
        }
    }

    public function testABoostHasItsPolesAtTheBand(): void
    {
        // H = 1 + (g - 1) · s/Q / (s² + s/Q + 1), and that band pass is 3 dB down at its analog edges
        $g = 10 ** (6.0 / 20);
        $peak = Biquad::peq(0, 1000, 6.0, 1.4);
        foreach ([1, -1] as $side) {
            $edge = self::digital(1000, sqrt(1 + 1 / (4 * 1.4 ** 2)) + $side / (2 * 1.4));
            $h = $peak->at($edge);
            self::assertEqualsWithDelta(($g - 1) / sqrt(2), hypot($h[0] - 1, $h[1]), 1e-9, "$edge Hz");
        }
    }

    public function testAPeakIsTheRbjPeakWithALowerQ(): void
    {
        foreach ([[1000, 6.0, 1.42], [10080, -9.0, 4.02], [99, 6.0, 0.71], [5000, 12.0, 8.0]] as [$hz, $db, $q]) {
            $a = 10 ** ($db / 40);
            $w0 = 2 * M_PI * $hz / Scale::SAMPLE_RATE;
            $alpha = sin($w0) / (2 * $q * 10 ** (-abs($db) / 40));
            $rbj = new Biquad(...self::normalise([1 + $alpha * $a, -2 * cos($w0), 1 - $alpha * $a], [1 + $alpha / $a, -2 * cos($w0), 1 - $alpha / $a]));
            foreach ([50, 0.8 * $hz, $hz, 1.3 * $hz, 20000] as $f) {
                self::assertEqualsWithDelta($rbj->db($f), Biquad::peq(0, $hz, $db, $q)->db($f), 1e-9, "$hz Hz $db dB at $f Hz");
                self::assertEqualsWithDelta($rbj->degrees($f), Biquad::peq(0, $hz, $db, $q)->degrees($f), 1e-9);
            }
        }
    }

    public function testTheShelves(): void
    {
        $low = Biquad::peq(1, 1000, 6.0, 0.707);
        $high = Biquad::peq(2, 1000, 6.0, 0.707);

        self::assertEqualsWithDelta(6.0, $low->db(10), 0.01);
        self::assertEqualsWithDelta(0.0, $low->db(20000), 0.05);
        self::assertEqualsWithDelta(0.0, $high->db(10), 0.01);
        self::assertEqualsWithDelta(6.0, $high->db(23999), 0.05);
        self::assertLessThan(0.0, $low->degrees(1000));
        self::assertGreaterThan(0.0, $high->degrees(1000));
        // Half the gain 10^(|G|/80) times towards the flat side, for a boost and for a cut
        foreach ([[1, 6.0, 1], [1, -6.0, 1], [2, 6.0, -1], [2, -6.0, -1], [1, 12.0, 1], [2, -9.0, -1]] as [$type, $db, $side]) {
            $half = self::digital(1000, 10 ** ($side * abs($db) / 80));
            foreach ([0.5, 0.707, 2.0] as $q) {
                self::assertEqualsWithDelta($db / 2, Biquad::peq($type, 1000, $db, $q)->db($half), 1e-9, "type $type $db dB Q $q");
            }
        }
        // A cut is the inverse of the boost
        foreach ([30, 300, 1000, 3000, 20000] as $hz) {
            self::assertEqualsWithDelta(-$low->db($hz), Biquad::peq(1, 1000, -6.0, 0.707)->db($hz), 1e-9);
            self::assertEqualsWithDelta(-$high->degrees($hz), Biquad::peq(2, 1000, -6.0, 0.707)->degrees($hz), 1e-9);
        }
    }

    public function testThePasses(): void
    {
        $low = Biquad::peq(3, 1000, 6.0, 2.0);
        $high = Biquad::peq(4, 1000, 6.0, 2.0);

        // The gain does not count, and the corner lifts by the Q
        self::assertEqualsWithDelta(20 * log10(2.0), $low->db(1000), 1e-9);
        self::assertEqualsWithDelta(20 * log10(2.0), $high->db(1000), 1e-9);
        self::assertEqualsWithDelta(-90.0, $low->degrees(1000), 1e-9);
        self::assertEqualsWithDelta(90.0, $high->degrees(1000), 1e-9);
        self::assertEqualsWithDelta(0.0, $low->db(10), 0.01);
        self::assertEqualsWithDelta(0.0, $high->db(23999), 0.01);
        // 12 dB per octave far from the corner
        self::assertEqualsWithDelta(12.0, $high->db(100) - $high->db(50), 0.1);
    }

    public function testTheAllpasses(): void
    {
        $first = Biquad::peq(5, 1000, 6.0, 2.0);
        $second = Biquad::peq(6, 1000, 6.0, 2.0);

        foreach ([20, 200, 1000, 5000, 20000] as $hz) {
            self::assertEqualsWithDelta(0.0, $first->db($hz), 1e-9, "$hz Hz");
            self::assertEqualsWithDelta(0.0, $second->db($hz), 1e-9, "$hz Hz");
        }
        self::assertEqualsWithDelta(-90.0, $first->degrees(1000), 1e-9);
        self::assertEqualsWithDelta(180.0, abs($second->degrees(1000)), 1e-6);
        self::assertEqualsWithDelta(0.0, $first->degrees(0.1), 0.1);
        self::assertEqualsWithDelta(0.0, $second->degrees(0.1), 0.1);
        // A higher Q keeps the phase near 0° for longer and turns it faster around the frequency. The analog
        // allpass gives -2·atan((f/f0) / (Q·(1 - (f/f0)²))), which the bilinear transform bends a little
        self::assertEqualsWithDelta(-134.4, $second->degrees(900), 0.5);
        self::assertEqualsWithDelta(-61.1, Biquad::peq(6, 1000, 0.0, 8.0)->degrees(900), 0.5);
    }

    public function testTheRateCounts(): void
    {
        $at48 = Biquad::peq(0, 10000, 6.0, 2.0);
        $at96 = Biquad::peq(0, 10000, 6.0, 2.0, 96000);

        self::assertEqualsWithDelta(6.0, $at96->db(10000), 1e-9);
        self::assertGreaterThan(0.5, abs($at48->db(14000) - $at96->db(14000)));
    }

    public function testFittedBandUsesThePeak(): void
    {
        $band = new FittedBand(Scale::hzToIndex(500), Scale::dbToPeqGain(-4.5), Scale::qToIndex(3.0));
        $peak = Biquad::peq(0, $band->hz(), $band->db(), $band->qValue());

        foreach ([100, 400, 500, 700, 5000] as $hz) {
            self::assertSame($peak->db($hz), $band->responseAt($hz));
            self::assertSame($peak->degrees($hz), $band->phaseAt($hz));
        }
    }

    public function testAnUnknownType(): void
    {
        $this->expectException(\OutOfRangeException::class);

        Biquad::peq(7, 1000, 0.0, 1.0);
    }

    /**
     * The digital frequency that the bilinear transform prewarped at $hz maps the analog $omega to.
     */
    private static function digital(float $hz, float $omega): float
    {
        return Scale::SAMPLE_RATE / M_PI * atan($omega * tan(M_PI * $hz / Scale::SAMPLE_RATE));
    }

    /**
     * @param array{float, float, float} $b
     * @param array{float, float, float} $a
     *
     * @return array{float, float, float, float, float}
     */
    private static function normalise(array $b, array $a): array
    {
        return [$b[0] / $a[0], $b[1] / $a[0], $b[2] / $a[0], $a[1] / $a[0], $a[2] / $a[0]];
    }
}
