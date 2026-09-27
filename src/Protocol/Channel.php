<?php

namespace App\Protocol;

/**
 * Channel indices on the wire: In1–In8 = 0x00–0x07, Out1–Out8 = 0x08–0x0F, Aux = 0x12.
 * Command 0x64 addresses AutoMix as 0x12 and Camera as 0x13.
 */
final class Channel
{
    public const INPUTS = 8;
    public const OUTPUTS = 8;
    public const AUX = 0x12;
    public const CAMERA = 0x13;

    public static function input(int $number): int
    {
        if ($number < 1 || $number > self::INPUTS) {
            throw new \OutOfRangeException(\sprintf('Input must be 1..8, got %d.', $number));
        }

        return $number - 1;
    }

    public static function output(int $number): int
    {
        if ($number < 1 || $number > self::OUTPUTS) {
            throw new \OutOfRangeException(\sprintf('Output must be 1..8, got %d.', $number));
        }

        return 7 + $number;
    }

    /**
     * "in1".."in8", "out1".."out8" or "aux" (case-insensitive), or a channel index 0..15.
     */
    public static function parse(string|int $channel): int
    {
        if (\is_int($channel)) {
            if ($channel < 0 || $channel > 15) {
                throw new \OutOfRangeException(\sprintf('Channel index must be 0..15, got %d.', $channel));
            }

            return $channel;
        }
        $name = strtolower(trim($channel));
        if ('aux' === $name) {
            return self::AUX;
        }
        if (!preg_match('/^(in|out)([1-8])$/', $name, $m)) {
            throw new \InvalidArgumentException(\sprintf('Unknown channel "%s" (expected in1..in8, out1..out8 or aux).', $channel));
        }

        return 'in' === $m[1] ? self::input((int) $m[2]) : self::output((int) $m[2]);
    }

    /**
     * Lower-case CLI name ("in1", "out3", "aux").
     */
    public static function name(int $index): string
    {
        return strtolower(self::label($index));
    }

    /**
     * Display label as in the vendor editor ("In1", "Out3", "Aux").
     */
    public static function label(int $index): string
    {
        return match (true) {
            $index < 8 => 'In'.($index + 1),
            $index < 16 => 'Out'.($index - 7),
            self::AUX === $index => 'Aux',
            default => \sprintf('#%02X', $index),
        };
    }

    public static function isInput(int $index): bool
    {
        return $index >= 0 && $index < 8;
    }

    public static function isOutput(int $index): bool
    {
        return $index >= 8 && $index < 16;
    }
}
