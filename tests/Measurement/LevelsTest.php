<?php

namespace App\Tests\Measurement;

use App\Measurement\Levels;
use App\Tests\Support\Signals;
use PHPUnit\Framework\TestCase;

final class LevelsTest extends TestCase
{
    public function testAFullScaleSine(): void
    {
        $levels = Levels::of(Signals::sine(1000.0, 48000, 1.0));

        self::assertEqualsWithDelta(-3.01, $levels->rms, 0.005);
        self::assertEqualsWithDelta(0.0, $levels->peak, 1e-9);
        self::assertEqualsWithDelta(3.01, $levels->crest(), 0.005);
        // Only the crests of every period reach full scale
        self::assertSame(2000, $levels->clipped);
        self::assertSame(48000, $levels->samples);
    }

    public function testRmsPeakAndClippedSamples(): void
    {
        $levels = Levels::of([0.5, -0.5, 1.0, -1.0]);

        self::assertEqualsWithDelta(20 * log10(sqrt(0.625)), $levels->rms, 1e-9);
        self::assertSame(0.0, $levels->peak);
        self::assertSame(2, $levels->clipped);
    }

    public function testSilenceReadsTheFloor(): void
    {
        foreach ([[], [0.0, 0.0]] as $samples) {
            $levels = Levels::of($samples);

            self::assertSame(Levels::FLOOR, $levels->rms);
            self::assertSame(Levels::FLOOR, $levels->peak);
            self::assertSame(0, $levels->clipped);
        }
        self::assertSame(['rms' => -200.0, 'peak' => -200.0, 'crest' => 0.0, 'clipped' => 0, 'samples' => 2], Levels::of([0.0, 0.0])->toArray());
        self::assertSame(Levels::FLOOR, Levels::db(1e-12));
    }
}
