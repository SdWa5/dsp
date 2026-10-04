<?php

namespace App\Tests\Filter;

use App\Filter\BesselNorm;
use App\Filter\Complex;
use App\Filter\Crossover;
use App\Filter\Prototype;
use App\Protocol\Labels;
use PHPUnit\Framework\TestCase;

final class CrossoverTest extends TestCase
{
    public function testBypass(): void
    {
        foreach ([true, false] as $high) {
            $bypass = new Crossover(0, 1000, $high);
            self::assertSame(0, $bypass->order);
            self::assertSame([1.0, 0.0], $bypass->at(10));
            self::assertSame([1.0, 0.0], $bypass->at(20000));
        }
    }

    public function testTheOrders(): void
    {
        foreach (Labels::SLOPES as $slope => $label) {
            if (0 === $slope) {
                continue;
            }
            $filter = new Crossover($slope, 1000, false);
            self::assertSame(substr($label, 0, 2), $filter->family, $label);
            self::assertSame(-(int) substr($label, 3) / 6, $filter->order, $label);
        }
    }

    public function testButterworthIsDownThreeDecibelsAtTheCorner(): void
    {
        foreach ([1 => 1, 3 => 2, 6 => 3, 8 => 4, 11 => 5, 13 => 6, 16 => 7, 18 => 8] as $slope => $order) {
            foreach ([true, false] as $high) {
                $filter = new Crossover($slope, 1000, $high);
                self::assertEqualsWithDelta(-3.01, $filter->db(1000), 0.01, Labels::SLOPES[$slope]);
                // 45° per order at the corner, leading for the high pass
                self::assertEqualsWithDelta(0.0, self::wrap($filter->degrees(1000) - ($high ? 45 : -45) * $order), 1e-6, Labels::SLOPES[$slope]);
            }
            // 6 dB per octave per order, far below the corner of the high pass
            $high = new Crossover($slope, 1000, true);
            self::assertEqualsWithDelta(6.02 * $order, $high->db(100) - $high->db(50), 0.1, Labels::SLOPES[$slope]);
        }
    }

    public function testLinkwitzRileySumsToAnAllpass(): void
    {
        foreach ([5 => 2, 10 => 4, 15 => 6, 20 => 8] as $slope => $order) {
            $low = new Crossover($slope, 1000, false);
            $high = new Crossover($slope, 1000, true);
            self::assertEqualsWithDelta(-6.02, $low->db(1000), 0.01, Labels::SLOPES[$slope]);
            self::assertEqualsWithDelta(-6.02, $high->db(1000), 0.01, Labels::SLOPES[$slope]);
            // LR2 and LR6 sum flat with the high pass inverted, LR4 and LR8 as they are
            $sign = 0 === $order / 2 % 2 ? 1.0 : -1.0;
            foreach ([100, 500, 1000, 2000, 8000] as $hz) {
                [$l, $h] = [$low->at($hz), $high->at($hz)];
                self::assertEqualsWithDelta(0.0, Complex::db([$l[0] + $sign * $h[0], $l[1] + $sign * $h[1]]), 1e-6, Labels::SLOPES[$slope]." at $hz Hz");
            }
        }
    }

    public function testBesselPoles(): void
    {
        // SciPy's besselap() for orders 2 and 4
        self::assertPoles([[-1.5, 0.866025]], Prototype::poles('BL', 2, BesselNorm::Delay));
        self::assertPoles([[-0.866025, 0.5]], Prototype::poles('BL', 2, BesselNorm::Phase));
        self::assertPoles([[-1.101601, 0.636010]], Prototype::poles('BL', 2, BesselNorm::Magnitude));
        self::assertPoles([[-0.657211, 0.830161], [-0.904759, 0.270919]], Prototype::poles('BL', 4, BesselNorm::Phase));
        self::assertPoles([[-0.995209, 1.257106], [-1.370068, 0.410250]], Prototype::poles('BL', 4, BesselNorm::Magnitude));
        self::assertPoles([[-1.0, 0.0]], Prototype::poles('BL', 1, BesselNorm::Phase));
    }

    public function testBesselNorms(): void
    {
        foreach ([4 => 2, 9 => 4, 14 => 6, 19 => 8] as $slope => $order) {
            $magnitude = new Crossover($slope, 1000, false, bessel: BesselNorm::Magnitude);
            self::assertEqualsWithDelta(-3.01, $magnitude->db(1000), 0.01, Labels::SLOPES[$slope]);
            self::assertLessThan(-3.02, (new Crossover($slope, 1000, false, bessel: BesselNorm::Phase))->db(1000));
            self::assertGreaterThan(-3.0, (new Crossover($slope, 1000, false, bessel: BesselNorm::Delay))->db(1000));
            // Phase norm: the product of the poles is 1, so the phase approaches a Butterworth's far above the corner
            $product = array_reduce(Prototype::poles('BL', $order, BesselNorm::Phase), static fn (float $p, array $pole): float => $p * hypot($pole[0], $pole[1]), 1.0);
            self::assertEqualsWithDelta(1.0, $product, 1e-9);
            // Delay norm: the group delay at 0 Hz is 1 / (2π·fc)
            $delay = new Crossover($slope, 100, false, bessel: BesselNorm::Delay);
            $groupDelay = -deg2rad($delay->degrees(1.0) - $delay->degrees(0.5)) / (2 * M_PI * 0.5);
            self::assertEqualsWithDelta(1 / (2 * M_PI * 100), $groupDelay, 1e-5, Labels::SLOPES[$slope]);
        }
        // First order is the same for every family and norm
        foreach (BesselNorm::cases() as $norm) {
            self::assertEqualsWithDelta((new Crossover(1, 1000, true))->at(700), (new Crossover(2, 1000, true, bessel: $norm))->at(700), 1e-12);
        }
    }

    public function testTheEnds(): void
    {
        self::assertSame([0.0, 0.0], (new Crossover(8, 1000, true))->at(0));
        self::assertSame([1.0, 0.0], (new Crossover(8, 1000, false))->at(0));
        self::assertLessThan(-100.0, (new Crossover(8, 1000, false))->db(24000));
        // The bilinear transform bends the low pass towards zero at half the rate
        self::assertLessThan(-24.0 * log(22000 / 1000, 2), (new Crossover(8, 1000, false))->db(22000));
    }

    public function testInvalidSlopes(): void
    {
        foreach ([static fn () => new Crossover(21, 1000, true), static fn () => Prototype::poles('LK', 3), static fn () => Prototype::poles('BW', 9)] as $make) {
            try {
                $make();
                self::fail('Expected an error');
            } catch (\OutOfRangeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * @param list<array{float, float}> $expected one of each conjugate pair, with a positive imaginary part
     * @param list<array{float, float}> $poles
     */
    private static function assertPoles(array $expected, array $poles): void
    {
        $upper = array_values(array_filter($poles, static fn (array $p): bool => $p[1] >= 0));
        usort($upper, static fn (array $a, array $b): int => $b[1] <=> $a[1]);
        usort($expected, static fn (array $a, array $b): int => $b[1] <=> $a[1]);
        self::assertCount(\count($expected), $upper);
        foreach ($expected as $i => [$re, $im]) {
            self::assertEqualsWithDelta($re, $upper[$i][0], 1e-5);
            self::assertEqualsWithDelta($im, $upper[$i][1], 1e-5);
        }
    }

    private static function wrap(float $degrees): float
    {
        return fmod(fmod($degrees, 360) + 540, 360) - 180;
    }
}
