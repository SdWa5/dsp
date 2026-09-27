<?php

namespace App\Tests\Automation;

use App\Automation\GainRider;
use App\Automation\RiderSettings;
use App\Protocol\Channel;
use App\Protocol\Scale;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The rider against a simulated input whose meter reads after the gain, as on the 8x8.
 */
final class GainRiderTest extends TestCase
{
    private const TICK = 0.3;

    private float $now = 100.0;

    /**
     * Feeds $seconds of meter readings for a source at $sourceDb and applies every write.
     *
     * @return list<int> the raw gains written
     */
    private function feed(GainRider $rider, float $sourceDb, float $seconds, int &$raw): array
    {
        $writes = [];
        for ($t = 0.0; $t < $seconds; $t += self::TICK) {
            $this->now += self::TICK;
            $written = $rider->meter($sourceDb + Scale::gainToDb($raw), $this->now);
            if (null !== $written) {
                $writes[] = $raw = $written;
                self::assertFalse($rider->observeGain($raw, $this->now));
            }
        }

        return $writes;
    }

    private function rider(int $raw, mixed ...$settings): GainRider
    {
        $rider = new GainRider(new RiderSettings(Channel::input(1), ...$settings));
        $rider->observeGain($raw, $this->now);

        return $rider;
    }

    public function testConvergesToTheTargetAtTheSlewRate(): void
    {
        $raw = Scale::GAIN_UNITY;
        $rider = $this->rider($raw, targetDb: -18.0, averageSeconds: 1.0);

        $writes = $this->feed($rider, -10.0, 40.0, $raw);

        self::assertEqualsWithDelta(-8.0, Scale::gainToDb($raw), 1.0 + 1e-9);
        $previous = Scale::GAIN_UNITY;
        foreach ($writes as $written) {
            self::assertLessThanOrEqual(0.5 * self::TICK + 0.1 + 1e-9, abs(Scale::gainToDb($written) - Scale::gainToDb($previous)));
            $previous = $written;
        }
        // Settled: no more writes once inside the deadband
        self::assertSame([], $this->feed($rider, -10.0, 10.0, $raw));
    }

    public function testDoesNotBoostAboveTheMaximum(): void
    {
        $raw = Scale::GAIN_UNITY;
        $rider = $this->rider($raw, targetDb: -18.0);

        self::assertSame([], $this->feed($rider, -30.0, 30.0, $raw));
        self::assertSame(Scale::GAIN_UNITY, $raw);
    }

    public function testBoostsUpToTheMaximumWhenAllowed(): void
    {
        $raw = Scale::GAIN_UNITY;
        $rider = $this->rider($raw, targetDb: -18.0, maxGainDb: 6.0, slewDbPerSecond: 2.0, allowBoost: true);

        $this->feed($rider, -30.0, 30.0, $raw);

        self::assertSame(6.0, Scale::gainToDb($raw));
    }

    public function testHoldsTheGainWhileTheInputIsSilent(): void
    {
        $raw = Scale::dbToGain(-6.0);
        $rider = $this->rider($raw, targetDb: -18.0, silenceDb: -45.0);

        self::assertSame([], $this->feed($rider, -60.0, 30.0, $raw));
        self::assertNull($rider->averageDb());
    }

    public function testLeavesSmallDeviationsAlone(): void
    {
        $raw = Scale::GAIN_UNITY;
        $rider = $this->rider($raw, targetDb: -18.0, deadbandDb: 1.0);

        self::assertSame([], $this->feed($rider, -18.8, 20.0, $raw));
    }

    public function testManualChangePausesAndBecomesTheNewStart(): void
    {
        $raw = Scale::GAIN_UNITY;
        $rider = $this->rider($raw, targetDb: -18.0, overrideHoldSeconds: 10.0);
        self::assertNotSame([], $this->feed($rider, -10.0, 3.0, $raw));

        $raw = Scale::dbToGain(-12.0);
        self::assertTrue($rider->observeGain($raw, $this->now));
        self::assertTrue($rider->isPaused($this->now));
        self::assertSame(-12.0, $rider->gainDb());
        self::assertSame([], $this->feed($rider, -10.0, 9.0, $raw));

        // After the hold it rides again, from -12 dB up towards -8 dB
        $writes = $this->feed($rider, -10.0, 30.0, $raw);
        self::assertNotSame([], $writes);
        self::assertGreaterThan(Scale::dbToGain(-12.0), $writes[0]);
    }

    public function testLateEchoOfAnOwnWriteIsNoManualChange(): void
    {
        $raw = Scale::GAIN_UNITY;
        $rider = $this->rider($raw, targetDb: -30.0, averageSeconds: 0.5, deadbandDb: 0.0);
        $first = null;
        for ($i = 0; $i < 20 && null === $first; ++$i) {
            $this->now += self::TICK;
            $first = $rider->meter(-10.0, $this->now);
        }
        self::assertNotNull($first);
        $second = null;
        for ($i = 0; $i < 20 && null === $second; ++$i) {
            $this->now += self::TICK;
            $second = $rider->meter(-10.0 + Scale::gainToDb($first), $this->now);
        }
        self::assertNotNull($second);

        self::assertFalse($rider->observeGain($first, $this->now));
        self::assertFalse($rider->observeGain($second, $this->now));
        self::assertFalse($rider->isPaused($this->now));
    }

    public function testGainOutsideTheWindowMovesBackAtTheSlewRate(): void
    {
        $raw = Scale::dbToGain(3.0);
        $rider = $this->rider($raw, targetDb: -30.0, averageSeconds: 0.5);

        $writes = $this->feed($rider, -10.0, 1.0, $raw);

        self::assertNotSame([], $writes);
        self::assertGreaterThan(2.0, Scale::gainToDb($writes[0]));
        // A too quiet signal does not push it further out
        $raw = Scale::dbToGain(3.0);
        $rider = $this->rider($raw, targetDb: 0.0);
        self::assertSame([], $this->feed($rider, -30.0, 10.0, $raw));
    }

    public function testWaitsForTheFirstState(): void
    {
        $rider = new GainRider(new RiderSettings(Channel::input(1)));

        self::assertNull($rider->meter(-10.0, 1.0));
        self::assertNull($rider->meter(-10.0, 2.0));
        self::assertNull($rider->gainDb());
    }

    public function testReportsWhatItIsDoing(): void
    {
        $rider = new GainRider(new RiderSettings(Channel::input(1), targetDb: -18.0, averageSeconds: 0.3));
        self::assertSame(GainRider::WAITING, $rider->status($this->now));

        $raw = Scale::GAIN_UNITY;
        $rider->observeGain($raw, $this->now);
        $this->feed($rider, -60.0, 3.0, $raw);
        self::assertSame(GainRider::SILENT, $rider->status($this->now));

        $this->feed($rider, -10.0, 1.0, $raw);
        self::assertSame(GainRider::RIDING, $rider->status($this->now));

        $this->feed($rider, -10.0, 40.0, $raw);
        self::assertSame(GainRider::STEADY, $rider->status($this->now));

        $rider->observeGain($raw - 20, $this->now);
        self::assertSame(GainRider::PAUSED, $rider->status($this->now));
    }

    public function testStopsAtTheLimit(): void
    {
        $raw = Scale::dbToGain(-19.9);
        $rider = $this->rider($raw, targetDb: -18.0, minGainDb: -20.0);

        $this->feed($rider, 10.0, 5.0, $raw);

        self::assertSame(GainRider::LIMIT, $rider->status($this->now));
        self::assertEqualsWithDelta(-20.0, Scale::gainToDb($raw), 0.1);
    }

    public function testSettingsRoundTripThroughAnArray(): void
    {
        $settings = new RiderSettings(Channel::input(3), -20.0, -12.0, 3.0, 1.5, 2.0, -50.0, 0.5, 10.0, true);

        self::assertEquals($settings, RiderSettings::fromArray(2, $settings->toArray()));
        self::assertEquals(new RiderSettings(2, targetDb: -24.0), RiderSettings::fromArray(2, ['target' => '-24']));
    }

    public function testSettingsFromAnArrayRejectNonNumbers(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RiderSettings::fromArray(0, ['slew' => 'fast']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidSettings(): iterable
    {
        yield 'output channel' => [['channel' => Channel::output(1)]];
        yield 'boost without flag' => [['channel' => 0, 'maxGainDb' => 3.0]];
        yield 'min above max' => [['channel' => 0, 'minGainDb' => -3.0, 'maxGainDb' => -6.0]];
        yield 'below -60 dB' => [['channel' => 0, 'minGainDb' => -70.0]];
        yield 'no slew' => [['channel' => 0, 'slewDbPerSecond' => 0.0]];
        yield 'negative deadband' => [['channel' => 0, 'deadbandDb' => -1.0]];
    }

    /**
     * @param array<string, mixed> $settings
     */
    #[DataProvider('invalidSettings')]
    public function testRejectsInvalidSettings(array $settings): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RiderSettings(...$settings);
    }
}
