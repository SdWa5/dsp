<?php

namespace App\Tests\AutoEq;

use App\AutoEq\FitResult;
use App\AutoEq\FitSettings;
use App\AutoEq\FittedBand;
use App\AutoEq\PeqFitter;
use App\AutoEq\TargetCurve;
use App\Measurement\Response;
use App\Protocol\Scale;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The fitter against synthetic responses made of known peaking filters.
 */
final class PeqFitterTest extends TestCase
{
    private static function band(float $hz, float $db, float $q): FittedBand
    {
        return new FittedBand(Scale::hzToIndex($hz), Scale::dbToPeqGain($db), Scale::qToIndex($q));
    }

    /**
     * A flat system at $offset dB with the given filters in it.
     */
    private static function measured(float $offset, FittedBand ...$bands): Response
    {
        $grid = Response::grid();

        return new Response($grid, array_map(static fn (float $f): float => $offset + array_sum(array_map(static fn (FittedBand $b): float => $b->responseAt($f), $bands)), $grid));
    }

    public function testCutsAResonance(): void
    {
        $result = (new PeqFitter())->fit(self::measured(-37.0, self::band(1000, 6.0, 2.0)), new TargetCurve(), new FitSettings());

        self::assertNotSame([], $result->bands);
        $first = $result->bands[0];
        self::assertEqualsWithDelta(1000, $first->hz(), 100);
        self::assertLessThan(-4.0, $first->db());
        self::assertGreaterThan(2.0, FitResult::peak($result->before));
        self::assertLessThan(1.0, FitResult::peak($result->after));
    }

    public function testDoesNotFillANarrowDip(): void
    {
        $result = (new PeqFitter())->fit(self::measured(0.0, self::band(300, -12.0, 12.0)), new TargetCurve(), new FitSettings());

        foreach ($result->bands as $band) {
            if ($band->db() > 0) {
                self::assertLessThanOrEqual(2.0, $band->db(), 'Only the 1-octave share of the dip may be boosted');
                self::assertLessThanOrEqual(2.0 + 1e-9, $band->qValue());
            }
        }
        // The null itself stays in the predicted result
        self::assertGreaterThan(8.0, $result->after->at(300));
    }

    public function testBoostsABroadDipWithinTheLimit(): void
    {
        $settings = new FitSettings(maxBoostDb: 3.0);
        $result = (new PeqFitter())->fit(self::measured(0.0, self::band(3000, -5.0, 0.7)), new TargetCurve(), $settings);

        $boost = array_values(array_filter($result->bands, static fn (FittedBand $b): bool => $b->db() > 0));
        self::assertNotSame([], $boost);
        self::assertLessThanOrEqual(2.0 + 1e-9, max(array_map(static fn (FittedBand $b): float => $b->qValue(), $boost)));
        $grid = Response::grid(60, 16000);
        $total = max([0.0, ...array_map(static fn (float $f): float => array_sum(array_map(static fn (FittedBand $b): float => $b->responseAt($f), $result->bands)), $grid)]);
        self::assertLessThanOrEqual(3.3, $total);
    }

    public function testLeavesAFlatResponseAlone(): void
    {
        $result = (new PeqFitter())->fit(self::measured(-20.0), new TargetCurve(), new FitSettings());

        self::assertSame([], $result->bands);
        self::assertEqualsWithDelta(0.0, FitResult::rms($result->after), 1e-9);
    }

    public function testFollowsATilt(): void
    {
        $target = TargetCurve::parse('tilt:-1');
        $result = (new PeqFitter())->fit(self::measured(0.0), $target, new FitSettings(bands: 7, maxBoostDb: 6.0, maxCutDb: 12.0));

        self::assertLessThan(FitResult::rms($result->before), FitResult::rms($result->after));
    }

    public function testRespectsTheBandCountAndRange(): void
    {
        $measured = self::measured(0.0, self::band(200, 6.0, 3.0), self::band(800, 5.0, 3.0), self::band(2500, 6.0, 3.0), self::band(8000, 4.0, 3.0), self::band(40, 8.0, 2.0));
        $result = (new PeqFitter())->fit($measured, new TargetCurve(), new FitSettings(bands: 2, low: 100.0));

        self::assertCount(2, $result->bands);
        foreach ($result->bands as $band) {
            self::assertGreaterThanOrEqual(100 * 0.97, $band->hz());
            self::assertLessThanOrEqual(Scale::dbToPeqGain(3.0), $band->gain);
            self::assertGreaterThanOrEqual(Scale::dbToPeqGain(-9.0), $band->gain);
        }
    }

    public function testTargetSpecs(): void
    {
        self::assertSame('flat', TargetCurve::parse('flat')->describe());
        self::assertSame('tilt -1.5 dB/octave', TargetCurve::parse('tilt:-1.5')->describe());
        self::assertEqualsWithDelta(1.5, TargetCurve::parse('tilt:-1.5')->at(500), 1e-9);

        $this->expectException(\InvalidArgumentException::class);
        TargetCurve::parse('house');
    }

    public function testFittedBandResponse(): void
    {
        $band = self::band(1000, 6.0, 2.0);

        self::assertEqualsWithDelta(6.0, $band->responseAt($band->hz()), 0.01);
        self::assertEqualsWithDelta(0.0, $band->responseAt(50), 0.1);
        self::assertSame(0.0, self::band(1000, 0.0, 2.0)->responseAt(1000));
        self::assertSame(['freq' => $band->freq, 'gain' => 180, 'q' => $band->q, 'hz' => round($band->hz(), 1), 'db' => 6.0, 'q_value' => round($band->qValue(), 2)], $band->toArray());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidSettings(): iterable
    {
        yield 'no bands' => [['bands' => 0]];
        yield 'eight bands' => [['bands' => 8]];
        yield 'range reversed' => [['low' => 1000.0, 'high' => 100.0]];
        yield 'boost too big' => [['maxBoostDb' => 15.0]];
        yield 'boost Q above cut Q' => [['maxQ' => 2.0, 'maxBoostQ' => 3.0]];
        yield 'no tolerance' => [['toleranceDb' => 0.0]];
    }

    /**
     * @param array<string, mixed> $settings
     */
    #[DataProvider('invalidSettings')]
    public function testRejectsInvalidSettings(array $settings): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new FitSettings(...$settings);
    }
}
