<?php

namespace App\Bridge;

use App\Automation\RiderSettings;

/** Canonical rider settings make JSON integer/float spelling irrelevant and preserve unrelated riders. */
final class HistoryRiders
{
    /**
     * @param list<array<string, mixed>> $current rider settings as Bridge::riderSettings() returns them
     *
     * @return list<array<string, mixed>>
     */
    public static function prepare(array $current, mixed $patch): array
    {
        if (!\is_array($patch) || !\is_array($patch['before'] ?? null) || !\is_array($patch['after'] ?? null)) {
            throw new BridgeException('Invalid history riders.', 400);
        }
        $channels = $patch['channels'] ?? range(0, 7);
        if (!\is_array($channels) || !array_is_list($channels) || [] === $channels || \count($channels) > 8 || array_any($channels, static fn (mixed $c): bool => !\is_int($c) || $c < 0 || $c > 7) || \count(array_unique($channels)) !== \count($channels)) {
            throw new BridgeException('Invalid history riders.', 400);
        }
        $now = self::normalize($current, range(0, 7));
        $before = self::normalize($patch['before'], $channels);
        $after = self::normalize($patch['after'], $channels);
        if (array_intersect_key($now, array_fill_keys($channels, true)) !== $before) {
            throw new BridgeException('History conflict in gain riders.', 409);
        }
        $next = array_diff_key($now, array_fill_keys($channels, true)) + $after;
        ksort($next);

        return array_values($next);
    }

    /**
     * @param array<array-key, mixed> $list
     * @param array<array-key, mixed> $channels
     *
     * @return array<int, array<string, mixed>>
     */
    private static function normalize(array $list, array $channels): array
    {
        if (!array_is_list($list)) {
            throw new BridgeException('Invalid history riders.', 400);
        }
        $result = [];
        foreach ($list as $settings) {
            if (!\is_array($settings) || !\is_int($channel = $settings['channel'] ?? null) || !\in_array($channel, $channels, true) || isset($result[$channel])) {
                throw new BridgeException('Invalid history riders.', 400);
            }
            try {
                $result[$channel] = RiderSettings::fromArray($channel, $settings)->toArray();
            } catch (\InvalidArgumentException $e) {
                throw new BridgeException($e->getMessage(), 400, $e);
            }
        }
        ksort($result);

        return $result;
    }
}
