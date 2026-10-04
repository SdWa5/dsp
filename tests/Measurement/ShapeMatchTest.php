<?php

namespace App\Tests\Measurement;

use App\Filter\Biquad;
use App\Filter\Chain;
use App\Filter\Crossover;
use App\Measurement\ShapeMatch;
use App\Measurement\Transfer;
use App\Measurement\TransferFunction;
use App\Protocol\Scale;
use App\Tests\Support\Signals;
use PHPUnit\Framework\TestCase;

/**
 * The comparison of a filter setting with its model, on synthetic loopbacks. The base stands for the
 * output as set, 6 dB down and 30 samples late against the reference.
 */
final class ShapeMatchTest extends TestCase
{
    private const RATE = Scale::SAMPLE_RATE;

    /** @var list<float>|null */
    private static ?array $reference = null;

    public function testAPeakFitsItsModel(): void
    {
        $peak = Biquad::peq(0, 1000, 6.0, 1.4);
        $match = self::match(static fn (array $s): array => Signals::biquad($s, $peak));

        self::assertEqualsWithDelta(0.0, (float) $match->delay, 0.2);
        $fit = $match->against($peak);
        self::assertTrue($fit['fits'], json_encode($fit, \JSON_THROW_ON_ERROR));
        self::assertLessThan(0.1, $fit['db']);
        self::assertLessThan(1.0, $fit['phase']);
        self::assertGreaterThan(50, $fit['points']);
        self::assertFalse($match->against(Biquad::peq(0, 1000, 5.0, 1.4))['fits']);
        self::assertFalse($match->against(Biquad::peq(0, 1100, 6.0, 1.4))['fits']);
    }

    public function testADelayShowsAsPhase(): void
    {
        $match = self::match(static fn (array $s): array => Signals::delay($s, 48));

        self::assertEqualsWithDelta(48.0, (float) $match->delay, 0.1);
        self::assertTrue($match->against(new Chain(delay: 48))['fits']);
        self::assertFalse($match->against(new Chain(delay: 47))['fits']);
        self::assertFalse($match->against(new Chain())['fits']);
    }

    public function testACrossoverIsComparedAboveTheFloor(): void
    {
        $slope = new Crossover(10, 1000, true);
        $match = self::match(static fn (array $s): array => Signals::filter($s, $slope));
        $fit = $match->against($slope);

        self::assertTrue($fit['fits'], json_encode($fit, \JSON_THROW_ON_ERROR));
        // LK-24 is -20·log10(1 + (fc/f)^4) dB, which reaches -30 dB at 425 Hz
        self::assertEqualsWithDelta(440, (float) $fit['from'], 30);
        self::assertFalse($match->against(new Crossover(8, 1000, true))['fits']);
    }

    public function testTheBaseModelDivides(): void
    {
        $base = Biquad::peq(1, 200, -4.0, 0.7);
        $case = Biquad::peq(0, 3000, 3.0, 2.0);
        $match = self::match(static fn (array $s): array => Signals::biquad(Signals::biquad($s, $base), $case), static fn (array $s): array => Signals::biquad($s, $base));

        self::assertTrue($match->against(new Chain([$base, $case]), $base)['fits']);
        self::assertFalse($match->against(new Chain([$base, $case]))['fits']);
    }

    public function testNothingToCompare(): void
    {
        $fit = (new ShapeMatch([['hz' => 1000.0, 'db' => 0.0, 'phase' => 0.0, 'coherence' => 0.2]]))->against(new Chain());

        self::assertSame(['db' => null, 'phase' => null, 'from' => null, 'to' => null, 'points' => 0, 'fits' => false], $fit);
    }

    public function testRoundTrip(): void
    {
        $match = self::match(static fn (array $s): array => Signals::delay($s, 5));
        $again = ShapeMatch::fromArray($match->toArray());

        self::assertEqualsWithDelta($match->delay, $again->delay, 0.001);
        self::assertCount(\count($match->points), $again->points);
        self::assertEqualsWithDelta($match->points[40]['phase'], $again->points[40]['phase'], 0.01);
        // The JSON rounds the points to 0.001 dB and 0.01°
        $fit = $again->against(new Chain(delay: 5));
        self::assertTrue($fit['fits']);
        self::assertSame($match->against(new Chain(delay: 5))['points'], $fit['points']);
        self::assertLessThan(0.01, $fit['phase']);
    }

    public function testTheSizesMustAgree(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ShapeMatch::measure(self::transfer(self::reference(), new TransferFunction(8192, 2048)), self::transfer(self::reference(), new TransferFunction(8192, 4096)));
    }

    /**
     * @param \Closure(list<float>): list<float>        $case
     * @param (\Closure(list<float>): list<float>)|null $base
     */
    private static function match(\Closure $case, ?\Closure $base = null): ShapeMatch
    {
        $function = new TransferFunction(8192, 4096);

        return ShapeMatch::measure(
            self::transfer(self::asSet(null === $base ? self::reference() : $base(self::reference())), $function),
            self::transfer(self::asSet($case(self::reference())), $function),
        );
    }

    /**
     * @param list<float> $samples
     *
     * @return list<float>
     */
    private static function asSet(array $samples): array
    {
        return array_map(static fn (float $v): float => 0.5 * $v, Signals::delay($samples, 30));
    }

    /**
     * @param list<float> $measured
     */
    private static function transfer(array $measured, TransferFunction $function): Transfer
    {
        return $function->analyse(self::reference(), $measured, self::RATE);
    }

    /**
     * @return list<float>
     */
    private static function reference(): array
    {
        return self::$reference ??= Signals::pinkNoise(2 * self::RATE, 5);
    }
}
