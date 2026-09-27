<?php

namespace App\AutoEq;

use App\Measurement\Calibration;
use App\Measurement\Response;

/**
 * The response the correction aims for: "flat", "tilt:-1" (dB per octave around 1 kHz) or
 * "file:path" with "frequency dB" lines in the calibration file format.
 */
final class TargetCurve
{
    public static function parse(string $spec): self
    {
        $spec = trim($spec);
        if ('flat' === $spec) {
            return new self(0.0);
        }
        if (preg_match('/^tilt:([-+]?\d+(?:\.\d+)?)$/', $spec, $m)) {
            return new self((float) $m[1]);
        }
        if (str_starts_with($spec, 'file:')) {
            return new self(0.0, Calibration::read(substr($spec, 5)));
        }

        throw new \InvalidArgumentException(\sprintf('Unknown target "%s". Use flat, tilt:<dB per octave> or file:<path>.', $spec));
    }

    public function __construct(
        public readonly float $tiltDbPerOctave = 0.0,
        private readonly ?Response $curve = null,
    ) {
    }

    public function at(float $hz): float
    {
        return $this->curve?->at($hz) ?? $this->tiltDbPerOctave * log($hz / 1000, 2);
    }

    /**
     * @param list<float> $freqs
     */
    public function on(array $freqs): Response
    {
        return new Response($freqs, array_map($this->at(...), $freqs));
    }

    public function describe(): string
    {
        return match (true) {
            null !== $this->curve => 'from file',
            0.0 === $this->tiltDbPerOctave => 'flat',
            default => \sprintf('tilt %+.1f dB/octave', $this->tiltDbPerOctave),
        };
    }
}
