<?php

namespace App\Bridge;

use App\Protocol\Commands;
use App\Protocol\Frame;

/**
 * Builds write frames from a command name and named arguments, e.g. {"command": "mute",
 * "args": {"channel": 8, "on": true}}. Only the write builders listed here are reachable.
 * Reads, the session commands and the PIN commands are not.
 */
final class CommandMap
{
    public const COMMANDS = [
        'gain', 'gainDb', 'mute', 'phase', 'routing', 'highPass', 'lowPass', 'peq', 'eqBypass', 'gate', 'compressor',
        'delay', 'delayUnit', 'link', 'copyChannel', 'channelName', 'inputMode', 'phantom', 'fbq', 'mixFunction',
        'autoMixSlope', 'effect', 'echo', 'reverb', 'subControl', 'group', 'testTone', 'workingStatus', 'deviceId',
        'loadPreset', 'storePreset', 'presetName', 'activate',
    ];

    /** Channel indices the device knows: In1–8, Out1–8, Aux, Camera */
    private const CHANNELS = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 0x12, 0x13];

    /**
     * @param array<mixed> $args
     *
     * @throws \InvalidArgumentException for an unknown command or unfitting arguments
     */
    public static function build(string $command, array $args): Frame
    {
        if (!\in_array($command, self::COMMANDS, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown command "%s".', $command));
        }
        $method = new \ReflectionMethod(Commands::class, $command);
        $values = [];
        foreach ($method->getParameters() as $parameter) {
            $name = $parameter->getName();
            if (!\array_key_exists($name, $args)) {
                if ($parameter->isDefaultValueAvailable()) {
                    continue;
                }
                throw new \InvalidArgumentException(\sprintf('Command "%s" needs the argument "%s".', $command, $name));
            }
            $values[$name] = self::cast($args[$name], (string) $parameter->getType(), $name);
        }
        if ([] !== $unknown = array_diff(array_keys($args), array_keys($values))) {
            throw new \InvalidArgumentException(\sprintf('Command "%s" has no argument "%s".', $command, implode('", "', $unknown)));
        }

        $frame = self::invoke($method, $values);
        // A builder that sends an argument as one byte drops the high byte: the frame is then
        // the same as with the low byte alone
        foreach ($values as $name => $value) {
            if (\is_int($value) && $value > 0xFF && self::invoke($method, [$name => $value & 0xFF] + $values)->encode() === $frame->encode()) {
                throw new \InvalidArgumentException(\sprintf('Argument "%s" must be 0..255, got %d.', $name, $value));
            }
        }

        return $frame;
    }

    /**
     * @param array<string, int|float|bool|string> $values
     */
    private static function invoke(\ReflectionMethod $method, array $values): Frame
    {
        try {
            $frame = $method->invokeArgs(null, $values);
        } catch (\OutOfRangeException|\LengthException $e) {
            throw new \InvalidArgumentException($e->getMessage(), 0, $e);
        }
        \assert($frame instanceof Frame);

        return $frame;
    }

    private static function cast(mixed $value, string $type, string $name): int|float|bool|string
    {
        $cast = match ($type) {
            'int' => \is_int($value) ? $value : (\is_string($value) && preg_match('/^-?\d+$/', $value) ? (int) $value : null),
            'float' => \is_int($value) || \is_float($value) ? (float) $value : (\is_string($value) && is_numeric($value) ? (float) $value : null),
            'bool' => \is_bool($value) ? $value : (0 === $value || 1 === $value ? 1 === $value : null),
            'string' => \is_string($value) ? $value : null,
            default => null,
        };
        if (null === $cast) {
            throw new \InvalidArgumentException(\sprintf('Argument "%s" must be of type %s.', $name, $type));
        }
        if (\is_int($cast) && ($cast < 0 || $cast > 0xFFFF)) {
            throw new \InvalidArgumentException(\sprintf('Argument "%s" must be 0..65535, got %d.', $name, $cast));
        }
        if (\is_int($cast) && \in_array($name, ['channel', 'source', 'target'], true) && !\in_array($cast, self::CHANNELS, true)) {
            throw new \InvalidArgumentException(\sprintf('Argument "%s" is not a channel index: %d.', $name, $cast));
        }

        return $cast;
    }
}
