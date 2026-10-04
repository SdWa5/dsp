<?php

namespace App\Bridge;

use App\Protocol\Frame;

/**
 * Editable live fields and guarded partial replay. Compound commands read untouched values from the
 * current bridge state. Raw bytes, slot metadata and unknown values are never restored from guesses.
 */
final class HistoryPatch
{
    /**
     * @return list<array{command: string, args: array<string, int|string>}>
     */
    public static function units(): array
    {
        $units = [];
        $add = static function (string $command, array $args) use (&$units): void { $units[] = ['command' => $command, 'args' => $args]; };
        foreach (['inputs' => 0, 'outputs' => 8, 'aux' => 18] as $side => $base) {
            foreach ('aux' === $side ? [0] : range(0, 7) as $i) {
                $p = 'aux' === $side ? 'aux' : "$side.$i";
                $ch = $base + $i;
                foreach (['channelName' => ['name' => 'name'], 'gain' => ['raw' => 'gain'], 'mute' => ['on' => 'mute'], 'eqBypass' => ['on' => 'eq_bypass']] as $command => $fields) {
                    $add($command, ['channel' => $ch] + self::paths($p, $fields));
                }
                if ('aux' !== $side) {
                    foreach (['phase' => ['inverted' => 'phase'], 'link' => ['flags' => 'link'], 'highPass' => ['freqIndex' => 'hpf.freq', 'slope' => 'hpf.slope'], 'lowPass' => ['freqIndex' => 'lpf.freq', 'slope' => 'lpf.slope']] as $command => $fields) {
                        $add($command, ['channel' => $ch] + self::paths($p, $fields));
                    }
                }
                for ($b = 0; $b < ('outputs' === $side ? 7 : 5); ++$b) {
                    $add('peq', ['channel' => $ch, 'band' => $b] + self::paths("$p.peq.$b", ['gainRaw' => 'gain', 'freqIndex' => 'freq', 'qIndex' => 'q', 'type' => 'type', 'bypass' => 'bypass']));
                }
                if ('inputs' === $side) {
                    $add('gate', ['channel' => $ch] + self::paths("$p.gate", ['attack' => 'attack', 'release' => 'release', 'threshold' => 'threshold']));
                    $add('inputMode', ['channel' => $ch, 'mode' => "@$p.mode"]);
                    $add('phantom', ['channel' => $ch, 'on' => "@$p.phantom"]);
                    $add('fbq', ['channel' => $ch, 'level' => "@$p.fbq"]);
                } else {
                    $add('routing', ['channel' => $ch, 'mask' => '@'.$p.('aux' === $side ? '.select' : '.route')]);
                    $add('compressor', ['channel' => $ch] + self::paths("$p.comp", ['ratio' => 'ratio', 'knee' => 'knee', 'attack' => 'attack', 'release' => 'release', 'threshold' => 'threshold']));
                    if ('outputs' === $side) {
                        $add('delay', ['channel' => $ch, 'samples' => "@$p.delay"]);
                    }
                }
            }
        }
        foreach (['automix' => 18, 'camera' => 19] as $kind => $ch) {
            $add('mixFunction', ['channel' => $ch] + self::paths("aux.$kind", ['attack' => 'attack', 'release' => 'release', 'threshold' => 'threshold', 'select' => 'select', 'on' => 'on']));
        }
        $add('autoMixSlope', ['index' => '@aux.automix_slope']);
        $add('effect', self::paths('aux.effect', ['on' => 'on', 'gain' => 'gain']));
        $add('echo', self::paths('aux.effect', ['predelay' => 'echo_predelay', 'delay' => 'echo_delay', 'repeat' => 'echo_repeat', 'level' => 'echo']));
        $add('reverb', self::paths('aux.effect', ['predelay' => 'rev_predelay', 'reflect' => 'reflect', 'time' => 'time', 'level' => 'reverb']));
        foreach (range(0, 1) as $i) {
            $add('subControl', ['io' => $i] + self::paths("sub.$i", ['gainPercent' => 'gain', 'muted' => 'mute', 'mask' => 'mask']) + ['extra' => "@sub_extra.$i"]);
        }
        foreach (range(0, 7) as $i) {
            $add('group', ['index' => $i] + self::paths("groups.$i", ['gainPercent' => 'gain', 'muted' => 'mute', 'mask' => 'mask']));
        }
        $add('testTone', ['mode' => '@tone_mode', 'freqIndex' => '@tone_freq']);
        $add('delayUnit', ['unit' => '@delay_unit']);
        $add('workingStatus', ['mode' => '@working_status']);
        $add('deviceId', ['id' => '@device_id']);

        return $units;
    }

    /**
     * @param array<string, string> $fields command argument => state field below $prefix
     *
     * @return array<string, string>
     */
    private static function paths(string $prefix, array $fields): array
    {
        return array_map(static fn (string $field): string => "@$prefix.$field", $fields);
    }

    /** @param array<array-key, mixed> $state */
    public static function get(array $state, string $path): mixed
    {
        $value = $state;
        foreach (explode('.', $path) as $key) {
            $value = \is_array($value) ? ($value[$key] ?? null) : null;
        }

        return $value;
    }

    /**
     * Checks every precondition and validates every frame before the caller enqueues anything.
     * Guards are link fields; changes are editable scalar fields. Masks compare and replace only selected bits.
     *
     * @param array<string, mixed>    $state   current bridge state as DeviceState::toArray() returns it
     * @param array<array-key, mixed> $changes list of {path, before, after, mask?}
     * @param array<array-key, mixed> $guards  list of {path, value, membership?}
     *
     * @return list<Frame>
     */
    public static function frames(array $state, array $changes, array $guards = []): array
    {
        if (!array_is_list($changes) || !array_is_list($guards) || \count($changes) > 1500 || \count($guards) > 16) {
            throw new BridgeException('Invalid history patch.', 400);
        }
        $units = self::units();
        $owners = [];
        foreach ($units as $i => $unit) {
            foreach ($unit['args'] as $arg) {
                if (\is_string($arg) && str_starts_with($arg, '@')) {
                    $owners[substr($arg, 1)] = $i;
                }
            }
        }
        foreach ($guards as $guard) {
            if (!\is_array($guard) || !\is_string($guard['path'] ?? null) || !preg_match('/^(inputs|outputs)\.[0-7]\.link$/', $guard['path']) || !\is_int($guard['value'] ?? null)) {
                throw new BridgeException('Invalid history guard.', 400);
            }
            $value = self::get($state, $guard['path']);
            if (true === ($guard['membership'] ?? false)) {
                [$side, $index] = explode('.', $guard['path']);
                $value = 1 << (int) $index;
                foreach ($state[$side] as $channel) {
                    $mask = $channel['link'];
                    if (($mask & $value) && $mask && ($mask & ($mask - 1))) {
                        $value = $mask;
                        break;
                    }
                }
            }
            if ($value !== $guard['value']) {
                throw new BridgeException('History conflict at '.$guard['path'].'.', 409);
            }
        }
        $changed = [];
        $seen = [];
        foreach ($changes as $change) {
            if (!\is_array($change) || !\is_string($path = $change['path'] ?? null) || !isset($owners[$path]) || isset($seen[$path]) || !array_key_exists('before', $change) || !array_key_exists('after', $change)) {
                throw new BridgeException('Invalid history field.', 400);
            }
            $seen[$path] = true;
            $now = self::get($state, $path);
            $before = $change['before'];
            $after = $change['after'];
            if (null === $now || null === $before || null === $after || \gettype($now) !== \gettype($before) || \gettype($now) !== \gettype($after)) {
                throw new BridgeException('Unknown or invalid history value at '.$path.'.', 400);
            }
            if (array_key_exists('mask', $change)) {
                $mask = $change['mask'];
                if (!preg_match('/\.(route|select|mask)$/', $path) || !\is_int($mask) || $mask < 1 || $mask > 65535 || !\is_int($now) || !\is_int($before) || !\is_int($after)) {
                    throw new BridgeException('Invalid history mask.', 400);
                }
                if (($now & $mask) !== ($before & $mask)) {
                    throw new BridgeException('History conflict at '.$path.'.', 409);
                }
                $after = ($now & ~$mask) | ($after & $mask);
            } elseif ($now !== $before) {
                throw new BridgeException('History conflict at '.$path.'.', 409);
            }
            $keys = explode('.', $path);
            $last = array_pop($keys);
            $node = &$state;
            foreach ($keys as $key) {
                $node = &$node[$key];
            }
            $node[$last] = $after;
            unset($node);
            $changed[$owners[$path]] = true;
        }
        $frames = [];
        $links = [];
        foreach (array_keys($changed) as $i) {
            $unit = $units[$i];
            $args = [];
            foreach ($unit['args'] as $key => $arg) {
                $args[$key] = \is_string($arg) && str_starts_with($arg, '@') ? self::get($state, substr($arg, 1)) : $arg;
                if (null === $args[$key]) {
                    throw new BridgeException('Unknown history command value.', 400);
                }
            }
            try {
                $frame = CommandMap::build($unit['command'], $args);
            } catch (\InvalidArgumentException $e) {
                throw new BridgeException($e->getMessage(), 400, $e);
            }
            if ('link' === $unit['command']) {
                $links[] = $frame;
            } else {
                $frames[] = $frame;
            }
        }

        // Restore per-member settings first. Link flags never invoke the UI's copy-to-new-slave operation.
        return [...$frames, ...$links, ...([] !== $links ? [\App\Protocol\Commands::activate()] : [])];
    }
}
