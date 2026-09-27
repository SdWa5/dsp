<?php

namespace App\Bridge;

use App\Protocol\Frame;
use App\Protocol\Opcode;

/**
 * Pending writes to the device. A write replaces a queued write to the same target (a fader that moves
 * faster than the device answers sends only its latest position), as long as no barrier lies between them.
 *
 * Barriers are commands whose effect depends on what was sent before them (copy, link, presets):
 * they are never merged, and nothing after them is merged into a write before them.
 */
final class WriteQueue
{
    private const BARRIERS = [
        Opcode::Activate, Opcode::CopyChannel, Opcode::Link, Opcode::LoadPreset, Opcode::StorePreset,
        Opcode::StorePresetName, Opcode::Unlock, Opcode::SetPin,
    ];

    /** Commands with one target for the whole device */
    private const GLOBAL = [
        Opcode::DelayUnit, Opcode::TestTone, Opcode::AutoMixSlope, Opcode::Effect, Opcode::Echo, Opcode::Reverb,
        Opcode::WorkingStatus, Opcode::DeviceId,
    ];

    /** @var list<array{key: ?string, frame: Frame}> */
    private array $entries = [];

    public function push(Frame $frame): void
    {
        $key = self::key($frame);
        if (null !== $key) {
            for ($i = \count($this->entries) - 1; $i >= 0; --$i) {
                $entryKey = $this->entries[$i]['key'];
                if (null === $entryKey) {
                    break;
                }
                if ($entryKey === $key) {
                    $this->entries[$i]['frame'] = $frame;

                    return;
                }
            }
        }
        $this->entries[] = ['key' => $key, 'frame' => $frame];
    }

    public function shift(): ?Frame
    {
        $entry = array_shift($this->entries);

        return $entry['frame'] ?? null;
    }

    public function count(): int
    {
        return \count($this->entries);
    }

    public function clear(): void
    {
        $this->entries = [];
    }

    public static function isBarrier(Frame $frame): bool
    {
        $op = $frame->opcode();

        return null === $op || \in_array($op, self::BARRIERS, true);
    }

    /**
     * What the write sets: the command plus the bytes that select the target. Null for barriers.
     */
    public static function key(Frame $frame): ?string
    {
        if (self::isBarrier($frame)) {
            return null;
        }
        $op = $frame->opcode();
        $selector = match (true) {
            \in_array($op, self::GLOBAL, true) => 0,
            // Channel and PEQ band
            Opcode::Peq === $op => 2,
            default => 1,
        };

        return \sprintf('%02x:%s', $frame->cmd, bin2hex(substr($frame->data, 0, $selector)));
    }
}
