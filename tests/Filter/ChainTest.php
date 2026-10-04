<?php

namespace App\Tests\Filter;

use App\Filter\Biquad;
use App\Filter\Chain;
use App\Filter\Crossover;
use App\Model\OutputChannel;
use App\Protocol\Scale;
use PHPUnit\Framework\TestCase;

final class ChainTest extends TestCase
{
    public function testAFlatOutput(): void
    {
        $chain = Chain::output((new OutputChannel())->toArray());

        self::assertSame([], $chain->sections);
        foreach ([20, 1000, 20000] as $hz) {
            self::assertSame([1.0, 0.0], $chain->at($hz));
        }
    }

    public function testAnOutputOfTheBridgeState(): void
    {
        $output = (new OutputChannel())->toArray();
        $output['peq'][2] = ['gain' => Scale::dbToPeqGain(6.0), 'freq' => Scale::hzToIndex(1000), 'q' => Scale::qToIndex(2.0), 'type' => 0, 'bypass' => false];
        $output['peq'][4] = ['gain' => Scale::dbToPeqGain(-9.0), 'freq' => Scale::hzToIndex(200), 'q' => 25, 'type' => 0, 'bypass' => true];
        $output['hpf'] = ['freq' => Scale::hzToIndex(80), 'slope' => 10];
        $output['lpf'] = ['freq' => Scale::hzToIndex(8000), 'slope' => 3];
        $chain = Chain::output($output);

        self::assertCount(3, $chain->sections);
        [$peak, $high, $low] = $chain->sections;
        self::assertInstanceOf(Biquad::class, $peak);
        self::assertInstanceOf(Crossover::class, $high);
        self::assertInstanceOf(Crossover::class, $low);
        self::assertTrue($high->high);
        self::assertFalse($low->high);
        self::assertSame([10, 3], [$high->slope, $low->slope]);
        $hz = Scale::indexToHz(Scale::hzToIndex(1000));
        self::assertEqualsWithDelta($peak->db($hz) + $high->db($hz) + $low->db($hz), $chain->db($hz), 1e-9);

        $output['eq_bypass'] = true;
        self::assertCount(2, Chain::output($output)->sections);
    }

    public function testPolarityAndDelay(): void
    {
        $output = (new OutputChannel())->toArray();
        $output['phase'] = true;
        self::assertEqualsWithDelta(180.0, abs(Chain::output($output)->degrees(1000)), 1e-9);

        $output['phase'] = false;
        $output['delay'] = 48;
        $delayed = Chain::output($output);
        self::assertEqualsWithDelta(-90.0, $delayed->degrees(250), 1e-9);
        self::assertEqualsWithDelta(0.0, $delayed->db(250), 1e-9);
    }
}
