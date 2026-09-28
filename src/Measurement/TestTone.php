<?php

namespace App\Measurement;

use App\Protocol\Commands;
use App\Protocol\Frame;
use App\Protocol\Labels;

/**
 * The device's test tone generator: a mode and the index of the sine frequency.
 *
 * The device keeps the sine frequency while it plays noise or the analog inputs, so every tone here carries
 * one, and switching to noise keeps the frequency the device had.
 */
final readonly class TestTone
{
    public const ANALOG = 0;
    public const PINK = 1;
    public const WHITE = 2;
    public const SINE = 3;

    public function __construct(
        public int $mode,
        public int $freq = 0,
    ) {
        if (!isset(Labels::TONE_MODES[$mode])) {
            throw new \OutOfRangeException(\sprintf('Test tone mode must be 0..%d, got %d.', \count(Labels::TONE_MODES) - 1, $mode));
        }
        if (!isset(Labels::TONE_FREQUENCIES[$freq])) {
            throw new \OutOfRangeException(\sprintf('Test tone frequency index must be 0..%d, got %d.', \count(Labels::TONE_FREQUENCIES) - 1, $freq));
        }
    }

    /**
     * The tone of a device state. A mode or frequency the state lacks or the device does not know reads as the
     * analog inputs or the first frequency.
     *
     * @param array<mixed> $state
     */
    public static function fromState(array $state): self
    {
        $mode = $state['tone_mode'] ?? null;
        $freq = $state['tone_freq'] ?? null;

        return new self(
            \is_int($mode) && isset(Labels::TONE_MODES[$mode]) ? $mode : self::ANALOG,
            \is_int($freq) && isset(Labels::TONE_FREQUENCIES[$freq]) ? $freq : 0,
        );
    }

    /**
     * "pink", "white", "none" (the analog inputs) or "sine:<Hz>" with Hz as "100", "1k" or "1.25kHz".
     * A sine takes the nearest of the device's frequencies and is refused more than 1/6 octave from it.
     * Noise and "none" keep the frequency index $freq.
     */
    public static function parse(string $signal, int $freq = 0): self
    {
        $signal = strtolower(trim($signal));
        if (preg_match('/^sine:(\d+(?:\.\d+)?)(k?)(?:hz)?$/', $signal, $m)) {
            $hz = (float) $m[1] * ('k' === $m[2] ? 1000 : 1);
            $index = self::nearest($hz);
            if (abs(log($hz / self::frequency($index), 2)) > 1 / 6 + 1e-9) {
                throw new \InvalidArgumentException(\sprintf('The test tone has no sine near %g Hz, it plays %s to %s.', $hz, Labels::TONE_FREQUENCIES[0], Labels::TONE_FREQUENCIES[\count(Labels::TONE_FREQUENCIES) - 1]));
            }

            return new self(self::SINE, $index);
        }

        return match ($signal) {
            'pink' => new self(self::PINK, $freq),
            'white' => new self(self::WHITE, $freq),
            'none', 'analog' => new self(self::ANALOG, $freq),
            default => throw new \InvalidArgumentException(\sprintf('Unknown signal "%s" (expected pink, white, none or sine:<Hz>).', $signal)),
        };
    }

    /**
     * Nominal frequency of index $index in Hz: third octaves on the base-ten series, index 17 = 1 kHz.
     */
    public static function frequency(int $index): float
    {
        return 1000 * 10 ** (($index - 17) / 10);
    }

    public function frame(): Frame
    {
        return Commands::testTone($this->mode, $this->freq);
    }

    public function plays(): bool
    {
        return self::ANALOG !== $this->mode;
    }

    /**
     * "Pink Noise", "Sine Wave 100Hz", "Analog Input".
     */
    public function label(): string
    {
        return Labels::TONE_MODES[$this->mode].(self::SINE === $this->mode ? ' '.Labels::TONE_FREQUENCIES[$this->freq] : '');
    }

    private static function nearest(float $hz): int
    {
        $index = (int) round(17 + 10 * log10(max($hz, 1e-3) / 1000));

        return max(0, min(\count(Labels::TONE_FREQUENCIES) - 1, $index));
    }
}
