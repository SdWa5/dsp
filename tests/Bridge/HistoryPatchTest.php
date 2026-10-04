<?php

namespace App\Tests\Bridge;

use App\Bridge\BridgeException;
use App\Bridge\HistoryPatch;
use App\Model\Device;
use App\Model\DeviceState;
use App\Protocol\Commands;
use PHPUnit\Framework\TestCase;

final class HistoryPatchTest extends TestCase
{
    /** @return array<string, mixed> */
    private function state(): array
    {
        return Device::parse((string) file_get_contents(__DIR__.'/../../resources/demo_config.bin'))->toArray();
    }

    public function testCompoundAndMaskedReplayPreserveUnrelatedSettings(): void
    {
        $s = $this->state();
        $frames = HistoryPatch::frames($s, [
            ['path' => 'outputs.0.comp.threshold', 'before' => $s['outputs'][0]['comp']['threshold'], 'after' => 100],
            ['path' => 'outputs.0.route', 'before' => $s['outputs'][0]['route'] & 1, 'after' => ($s['outputs'][0]['route'] ^ 1) & 1, 'mask' => 1],
        ]);
        $c = $s['outputs'][0]['comp'];
        self::assertSame(Commands::compressor(8, $c['ratio'], $c['knee'], $c['attack'], $c['release'], 100)->encode(), $frames[0]->encode());
        self::assertSame(Commands::routing(8, $s['outputs'][0]['route'] ^ 1)->encode(), $frames[1]->encode());
    }

    public function testEveryRegistryUnitBuildsAndAppliesWithoutTouchingMetadata(): void
    {
        $raw = (string) file_get_contents(__DIR__.'/../../resources/demo_config.bin');
        $cache = new DeviceState($raw);
        $cache->apply(Commands::phantom(0, false));
        $s = $cache->toDevice()->toArray();
        $count = 0;
        foreach (HistoryPatch::units() as $unit) {
            $paths = array_values(array_filter($unit['args'], static fn (mixed $v): bool => \is_string($v) && str_starts_with($v, '@')));
            if (array_any($paths, static fn (string $p): bool => null === HistoryPatch::get($s, substr($p, 1)))) {
                continue;
            }
            $path = substr($paths[0], 1);
            $value = HistoryPatch::get($s, $path);
            $frames = HistoryPatch::frames($s, [['path' => $path, 'before' => $value, 'after' => $value]]);
            self::assertNotEmpty($frames, $unit['command']);
            foreach ($frames as $frame) {
                $cache->apply($frame);
            }
            self::assertSame($value, HistoryPatch::get($cache->toDevice()->toArray(), $path), $unit['command']);
            ++$count;
        }
        self::assertGreaterThan(250, $count);
        self::assertSame($s['active_preset'], $cache->toDevice()->activePreset);
    }

    public function testRoutingBoundaryBitsReplaceOnlyTheirOwnBit(): void
    {
        $s = $this->state();
        foreach (['outputs.3.route' => 11, 'aux.select' => 18] as $path => $channel) {
            foreach ([0, 7, 8] as $bit) {
                $mask = 1 << $bit;
                // An unrelated external bit next to the replayed one must survive the rebuilt command
                $now = (HistoryPatch::get($s, $path) & ~$mask) | (1 << (8 === $bit ? 1 : $bit + 1));
                $state = $s;
                if (18 === $channel) {
                    $state['aux']['select'] = $now;
                } else {
                    $state['outputs'][3]['route'] = $now;
                }
                $frames = HistoryPatch::frames($state, [['path' => $path, 'before' => 0, 'after' => $mask, 'mask' => $mask]]);
                self::assertCount(1, $frames, "$path bit $bit");
                self::assertSame(Commands::routing($channel, $now | $mask)->encode(), $frames[0]->encode(), "$path bit $bit");
            }
        }
    }

    public function testChangedLinkMembershipRejectsTheWholeReplay(): void
    {
        $s = $this->state();
        foreach (range(0, 7) as $i) {
            $s['outputs'][$i]['link'] = 1 << $i;
        }
        $change = ['path' => 'outputs.0.gain', 'before' => $s['outputs'][0]['gain'], 'after' => 100];
        self::assertCount(1, HistoryPatch::frames($s, [$change], [['path' => 'outputs.0.link', 'value' => 1, 'membership' => true]]));
        // Out1 has since been linked with Out2: the old single-channel action no longer applies as recorded
        $s['outputs'][0]['link'] = $s['outputs'][1]['link'] = 3;
        try {
            HistoryPatch::frames($s, [$change], [['path' => 'outputs.0.link', 'value' => 1, 'membership' => true]]);
            self::fail('Changed link membership accepted.');
        } catch (BridgeException $e) {
            self::assertSame(409, $e->getCode());
            self::assertSame('History conflict at outputs.0.link.', $e->getMessage());
        }
    }

    public function testConflictRejectsAllFields(): void
    {
        $s = $this->state();
        $this->expectException(BridgeException::class);
        $this->expectExceptionCode(409);
        HistoryPatch::frames($s, [
            ['path' => 'outputs.0.gain', 'before' => $s['outputs'][0]['gain'], 'after' => 100],
            ['path' => 'outputs.1.gain', 'before' => -1, 'after' => 100],
        ]);
    }

    public function testUnsupportedAndUnknownFieldsAreRejected(): void
    {
        foreach ([['path' => 'raw', 'before' => '', 'after' => 'x'], ['path' => 'inputs.0.phantom', 'before' => null, 'after' => true], ['path' => 'outputs.0.route', 'before' => 0, 'after' => 0, 'mask' => 65536]] as $change) {
            try {
                HistoryPatch::frames($this->state(), [$change]);
                self::fail('Invalid patch accepted.');
            } catch (BridgeException $e) {
                self::assertSame(400, $e->getCode());
            }
        }
    }
}
