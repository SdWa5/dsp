<?php

namespace App\Tests\Bridge;

use App\Bridge\WriteQueue;
use App\Measurement\Solo;
use App\Measurement\TestTone;
use App\Protocol\Channel;
use App\Protocol\Commands;
use App\Protocol\Frame;
use App\Transport\DemoTransport;
use PHPUnit\Framework\TestCase;

final class WriteQueueTest extends TestCase
{
    /**
     * @return list<string>
     */
    private static function drain(WriteQueue $queue): array
    {
        $out = [];
        while (null !== $frame = $queue->shift()) {
            $out[] = $frame->shortHex();
        }

        return $out;
    }

    public function testFaderMovesCollapseToTheLatestValue(): void
    {
        $queue = new WriteQueue();
        foreach ([200, 210, 220, 230] as $raw) {
            $queue->push(Commands::gain(8, $raw));
        }
        $queue->push(Commands::gain(9, 100));

        self::assertSame([Commands::gain(8, 230)->shortHex(), Commands::gain(9, 100)->shortHex()], self::drain($queue));
    }

    public function testMergeKeepsThePositionOfTheFirstWrite(): void
    {
        $queue = new WriteQueue();
        $queue->push(Commands::gain(8, 1));
        $queue->push(Commands::mute(8, true));
        $queue->push(Commands::gain(8, 2));

        self::assertSame([Commands::gain(8, 2)->shortHex(), Commands::mute(8, true)->shortHex()], self::drain($queue));
    }

    public function testPeqBandsAreSeparateTargets(): void
    {
        $queue = new WriteQueue();
        $queue->push(Commands::peq(8, 0, 120, 100, 25, 0, false));
        $queue->push(Commands::peq(8, 1, 120, 100, 25, 0, false));
        $queue->push(Commands::peq(8, 0, 130, 100, 25, 0, false));

        self::assertSame(2, $queue->count());
    }

    public function testGlobalCommandsHaveOneTarget(): void
    {
        $queue = new WriteQueue();
        $queue->push(Commands::testTone(3, 1));
        $queue->push(Commands::testTone(1, 0));
        $queue->push(Commands::echo(1, 2, 3, 4));
        $queue->push(Commands::echo(5, 6, 7, 8));

        self::assertSame([Commands::testTone(1, 0)->shortHex(), Commands::echo(5, 6, 7, 8)->shortHex()], self::drain($queue));
    }

    public function testNothingMergesAcrossABarrier(): void
    {
        $queue = new WriteQueue();
        $queue->push(Commands::gain(8, 1));
        $queue->push(Commands::copyChannel(8, 9));
        $queue->push(Commands::gain(8, 2));
        $queue->push(Commands::copyChannel(8, 9));

        self::assertSame(4, $queue->count());
        self::assertSame(Commands::gain(8, 1)->shortHex(), $queue->shift()?->shortHex());
    }

    public function testBarriers(): void
    {
        foreach ([Commands::copyChannel(0, 1), Commands::link(0, 3), Commands::loadPreset(1), Commands::storePreset(1), Commands::presetName('x'), Commands::activate(), Frame::request(0x99)] as $frame) {
            self::assertTrue(WriteQueue::isBarrier($frame), $frame->shortHex());
            self::assertNull(WriteQueue::key($frame));
        }
        self::assertFalse(WriteQueue::isBarrier(Commands::mute(0, true)));
    }

    /**
     * A measurement that fails before the device took its setup queues the restore behind it. Whatever part
     * of the setup was sent already, the device ends as it was.
     */
    public function testARestoreQueuedBeforeTheFlushLeavesTheOriginalValues(): void
    {
        $demo = __DIR__.'/../../resources/demo_config.bin';
        $before = DemoTransport::fromFile($demo)->state()->toDevice()->toArray();
        $solo = Solo::plan($before, [Channel::output(3)], -20.0, Channel::output(8));
        $setup = [...$solo->setup, (new TestTone(TestTone::PINK, 17))->frame()];
        $restore = [$solo->original->frame(), ...$solo->restore];

        foreach (range(0, \count($setup)) as $sent) {
            $device = DemoTransport::fromFile($demo)->state();
            $queue = new WriteQueue();
            foreach ($setup as $frame) {
                $queue->push($frame);
            }
            for ($i = 0; $i < $sent; ++$i) {
                $device->apply($queue->shift() ?? throw new \LogicException('The queue ran dry.'));
            }
            foreach ($restore as $frame) {
                $queue->push($frame);
            }
            while (null !== $frame = $queue->shift()) {
                $device->apply($frame);
            }

            self::assertSame($before, $device->toDevice()->toArray(), \sprintf('%d of %d setup writes sent', $sent, \count($setup)));
        }
    }

    public function testClear(): void
    {
        $queue = new WriteQueue();
        $queue->push(Commands::mute(0, true));
        $queue->clear();

        self::assertSame(0, $queue->count());
        self::assertNull($queue->shift());
    }
}
