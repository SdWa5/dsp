<?php

namespace App\Measurement;

use App\Protocol\Labels;

/**
 * Dual-channel FFT of a recorded channel against a reference channel, for example a mic against the device's
 * own pink noise looped back into a second input of the interface.
 *
 * Welch's method with Hann windows and 50 % overlap gives the auto spectra Gxx and Gyy and the cross spectrum
 * Gxy. The transfer function is H = Gxy / Gxx and the coherence γ² = |Gxy|² / (Gxx · Gyy). Both signals of a
 * segment go through one complex FFT, the reference as the real part and the recording as the imaginary part.
 *
 * Two passes. The first uses LAG_SIZE points and only finds the whole-sample lag, from the peak of the impulse
 * response. A delay that is large against the window would bias |H| and the coherence, so the second pass
 * shifts the reference by that lag and computes H and γ² with SIZE points. The sub-sample delay comes from the
 * envelope of the analytic impulse response, evaluated on a grid of 1/GRID sample around its peak and refined
 * with a parabola. The phase of the analytic impulse response at that peak gives the polarity.
 */
final class TransferFunction
{
    public const LAG_SIZE = 65536;
    public const SIZE = 16384;
    /** The coherence of fewer segments is biased towards 1 */
    public const MIN_SEGMENTS = 8;
    /** The lag needs few segments, and one FFT of LAG_SIZE points takes about 0.65 s in PHP */
    private const LAG_SEGMENTS = 2;
    /** About 2.8 s of the recording, which keeps one pair near 6 s in ddev */
    public const MAX_SEGMENTS = 32;
    /** An impulse response peak less than this far above the envelope's median gives no delay */
    private const PEAK_RATIO_DB = 20.0;
    private const GRID = 8;
    /** A quieter reference gets a warning, the coherence suffers from the interface's noise */
    private const QUIET_DB = -50.0;

    private readonly Fft $lagFft;
    private readonly Fft $fft;
    /** @var array<int, list<float>> */
    private array $windows = [];

    /**
     * The sizes are powers of two. Smaller ones are for tests, since they shorten the lag range to ±lagSize/2.
     */
    public function __construct(int $lagSize = self::LAG_SIZE, int $size = self::SIZE)
    {
        if ($size > $lagSize) {
            throw new \InvalidArgumentException('The first pass needs at least as many points as the second.');
        }
        $this->lagFft = new Fft($lagSize);
        $this->fft = new Fft($size);
    }

    /**
     * @param list<float> $reference what went into the system, for example the loopback of the reference output
     * @param list<float> $measured  what came out, recorded by the same interface at the same time
     */
    public function analyse(array $reference, array $measured, int $rate): Transfer
    {
        $levels = Levels::of($reference);
        if ($levels->peak <= Levels::FLOOR) {
            throw new \InvalidArgumentException('The reference is silent.');
        }
        if ($levels->clipped > 0) {
            throw new \InvalidArgumentException(\sprintf('The reference clips in %d samples, which bends the transfer function. Lower its gain or press PAD.', $levels->clipped));
        }
        $warnings = [];
        if ($levels->rms < self::QUIET_DB) {
            $warnings[] = \sprintf('The reference reads %.1f dBFS RMS, below %d dBFS, so the interface noise lowers the coherence.', $levels->rms, self::QUIET_DB);
        }
        $size = $this->fft->size;
        if (min(\count($reference), \count($measured)) < $this->lagFft->size) {
            throw new \LengthException(\sprintf('The transfer function needs at least %d samples, got %d.', $this->lagFft->size, min(\count($reference), \count($measured))));
        }

        // Pass 1: the whole-sample lag
        $spectra = $this->cross($this->lagFft, $reference, $measured, 0, self::LAG_SEGMENTS);
        [$z] = $this->analytic($spectra, false);
        $lag = $this->peak($this->lagFft->inverse($z[0], $z[1]))[0];

        // Pass 2: magnitude, phase and coherence with the reference shifted by the lag
        $spectra = $this->cross($this->fft, $reference, $measured, $lag, self::MAX_SEGMENTS);
        if ($spectra['segments'] < self::MIN_SEGMENTS) {
            throw new \LengthException(\sprintf('The transfer function needs %d segments of %d samples after the lag of %d samples, the recording gives %d. Record longer.', self::MIN_SEGMENTS, $size, $lag, $spectra['segments']));
        }
        [$z, $h, $coherence] = $this->analytic($spectra, true);
        [$index, $ratio] = $this->peak($this->fft->inverse($z[0], $z[1]));

        $residual = null;
        $peakPhase = null;
        if ($ratio >= self::PEAK_RATIO_DB) {
            [$residual, $peakPhase] = $this->refine($z, $index);
        }
        $half = $size >> 1;
        $freqs = $magnitude = $phase = [];
        $shift = 2 * M_PI * ($residual ?? 0.0) / $size;
        for ($k = 0; $k <= $half; ++$k) {
            $freqs[] = $k * $rate / $size;
            [$re, $im] = $h[$k];
            $magnitude[] = Levels::db(sqrt($re * $re + $im * $im));
            // H · e^(+2πik·τ/N) takes the residual delay τ out of the phase
            $phase[] = rad2deg(self::wrap(atan2($im, $re) + $shift * $k));
        }

        return new Transfer(
            $rate,
            $lag,
            null === $residual ? null : $lag + $residual,
            $lag + ($residual ?? 0.0),
            Polarity::fromPhase($peakPhase),
            $peakPhase,
            $ratio,
            $spectra['segments'],
            $freqs,
            $magnitude,
            $phase,
            $coherence,
            $this->bands($rate, $magnitude, $phase, $coherence),
            $warnings,
        );
    }

    /**
     * Welch sums of Gxx, Gyy and Gxy for the bins 0..size/2, with the reference read $shift samples earlier
     * than the recording.
     *
     * @param list<float> $x reference
     * @param list<float> $y recording
     *
     * @return array{gxx: list<float>, gyy: list<float>, re: list<float>, im: list<float>, segments: int}
     */
    private function cross(Fft $fft, array $x, array $y, int $shift, int $limit): array
    {
        $size = $fft->size;
        $half = $size >> 1;
        $window = $this->window($size);
        $gxx = $gyy = $gre = $gim = array_fill(0, $half + 1, 0.0);
        $segments = 0;
        for ($start = max(0, $shift); $segments < $limit && $start + $size <= \count($y) && $start - $shift + $size <= \count($x); $start += $half) {
            $real = $imag = [];
            for ($i = 0; $i < $size; ++$i) {
                $real[] = $x[$start - $shift + $i] * $window[$i];
                $imag[] = $y[$start + $i] * $window[$i];
            }
            [$zr, $zi] = $fft->transform($real, $imag);
            for ($k = 0; $k <= $half; ++$k) {
                // Z = X + jY for real x and y: X[k] = (Z[k] + conj Z[N-k]) / 2, Y[k] = (Z[k] - conj Z[N-k]) / 2j
                $m = ($size - $k) & ($size - 1);
                $xr = ($zr[$k] + $zr[$m]) / 2;
                $xi = ($zi[$k] - $zi[$m]) / 2;
                $yr = ($zi[$k] + $zi[$m]) / 2;
                $yi = ($zr[$m] - $zr[$k]) / 2;
                $gxx[$k] += $xr * $xr + $xi * $xi;
                $gyy[$k] += $yr * $yr + $yi * $yi;
                // conj(X) · Y
                $gre[$k] += $xr * $yr + $xi * $yi;
                $gim[$k] += $xr * $yi - $xi * $yr;
            }
            ++$segments;
        }

        // array_values() costs nothing on a packed array and tells PHPStan that it stays a list
        return ['gxx' => array_values($gxx), 'gyy' => array_values($gyy), 're' => array_values($gre), 'im' => array_values($gim), 'segments' => $segments];
    }

    /**
     * H and γ² per bin, and the one-sided spectrum Z of the analytic impulse response. With $weighted every bin
     * of Z counts by its coherence, so that bins without signal, for example above the reference's band, add
     * no noise to the impulse response.
     *
     * @param array{gxx: list<float>, gyy: list<float>, re: list<float>, im: list<float>, segments: int} $spectra
     *
     * @return array{array{list<float>, list<float>}, list<array{float, float}>, list<float>}
     */
    private function analytic(array $spectra, bool $weighted): array
    {
        $half = \count($spectra['gxx']) - 1;
        $size = $half << 1;
        $zr = $zi = array_fill(0, $size, 0.0);
        $h = $coherence = [];
        $floor = 0.0;
        foreach ($spectra['gxx'] as $gxx) {
            $floor = max($floor, $gxx * 1e-20);
        }
        for ($k = 0; $k <= $half; ++$k) {
            [$gxx, $gyy, $re, $im] = [$spectra['gxx'][$k], $spectra['gyy'][$k], $spectra['re'][$k], $spectra['im'][$k]];
            if ($gxx <= $floor) {
                $h[] = [0.0, 0.0];
                $coherence[] = 0.0;
                continue;
            }
            $h[] = [$re / $gxx, $im / $gxx];
            $coherence[] = $gyy > 0 ? min(1.0, ($re * $re + $im * $im) / ($gxx * $gyy)) : 0.0;
            $weight = (0 === $k || $half === $k ? 1.0 : 2.0) * ($weighted ? $coherence[$k] : 1.0);
            $zr[$k] = $weight * $re / $gxx;
            $zi[$k] = $weight * $im / $gxx;
        }

        return [[array_values($zr), array_values($zi)], $h, $coherence];
    }

    /**
     * The signed index of the envelope's peak and the peak over the envelope's median in dB.
     *
     * @param array{list<float>, list<float>} $impulse analytic impulse response
     *
     * @return array{int, float}
     */
    private function peak(array $impulse): array
    {
        $size = \count($impulse[0]);
        $envelope = [0.0];
        foreach ($impulse[0] as $n => $re) {
            $envelope[$n] = $re * $re + $impulse[1][$n] * $impulse[1][$n];
        }
        $index = (int) array_search(max($envelope), $envelope, true);
        $peak = $envelope[$index];
        sort($envelope);
        $median = $envelope[$size >> 1];
        $ratio = $median > 0 ? 10 * log10($peak / $median) : 200.0;

        return [$index >= $size >> 1 ? $index - $size : $index, $ratio];
    }

    /**
     * The envelope peak to a fraction of a sample, and the phase there in degrees.
     *
     * @param array{list<float>, list<float>} $z
     *
     * @return array{float, float}
     */
    private function refine(array $z, int $index): array
    {
        $envelope = [];
        for ($m = -self::GRID; $m <= self::GRID; ++$m) {
            [$re, $im] = $this->at($z, $index + $m / self::GRID);
            $envelope[$m] = sqrt($re * $re + $im * $im);
        }
        $best = (int) array_search(max($envelope), $envelope, true);
        $offset = 0.0;
        if (isset($envelope[$best - 1], $envelope[$best + 1])) {
            [$l, $c, $r] = [$envelope[$best - 1], $envelope[$best], $envelope[$best + 1]];
            $curve = $l - 2 * $c + $r;
            $offset = $curve < 0 ? 0.5 * ($l - $r) / $curve : 0.0;
        }
        $t = $index + ($best + $offset) / self::GRID;
        [$re, $im] = $this->at($z, $t);

        return [$t, rad2deg(atan2($im, $re))];
    }

    /**
     * The analytic impulse response at time $t in samples, from its one-sided spectrum.
     *
     * @param array{list<float>, list<float>} $z
     *
     * @return array{float, float}
     */
    private function at(array $z, float $t): array
    {
        $size = \count($z[0]);
        [$stepRe, $stepIm] = [cos(2 * M_PI * $t / $size), sin(2 * M_PI * $t / $size)];
        [$er, $ei, $sumRe, $sumIm] = [1.0, 0.0, 0.0, 0.0];
        for ($k = 0; $k <= $size >> 1; ++$k) {
            $sumRe += $z[0][$k] * $er - $z[1][$k] * $ei;
            $sumIm += $z[0][$k] * $ei + $z[1][$k] * $er;
            [$er, $ei] = [$er * $stepRe - $ei * $stepIm, $er * $stepIm + $ei * $stepRe];
        }

        return [$sumRe / $size, $sumIm / $size];
    }

    /**
     * Third octaves with the edges of Spectrum::bands(): the power mean of |H|, the phase of the coherence
     * weighted mean of H/|H| and the mean coherence. The phase reads null where the coherence is too low.
     *
     * @param list<float> $magnitude
     * @param list<float> $phase
     * @param list<float> $coherence
     *
     * @return list<array{hz: float, label: string, db: float, phase: float|null, coherence: float}>
     */
    private function bands(int $rate, array $magnitude, array $phase, array $coherence): array
    {
        $resolution = $rate / $this->fft->size;
        $last = \count($magnitude) - 1;
        $edge = 10 ** (1 / 20);
        $bands = [];
        foreach (Labels::TONE_FREQUENCIES as $i => $label) {
            $hz = TestTone::frequency($i);
            $from = max(0, (int) ceil($hz / $edge / $resolution));
            $to = min($last, (int) ceil($hz * $edge / $resolution) - 1);
            if ($to < $from) {
                $from = $to = max(0, min($last, (int) round($hz / $resolution)));
            }
            [$power, $re, $im, $sum] = [0.0, 0.0, 0.0, 0.0];
            for ($k = $from; $k <= $to; ++$k) {
                $power += 10 ** ($magnitude[$k] / 10);
                $re += $coherence[$k] * cos(deg2rad($phase[$k]));
                $im += $coherence[$k] * sin(deg2rad($phase[$k]));
                $sum += $coherence[$k];
            }
            $bins = $to - $from + 1;
            $mean = $sum / $bins;
            $bands[] = [
                'hz' => $hz,
                'label' => $label,
                'db' => max(Levels::FLOOR, 10 * log10(max($power / $bins, 1e-30))),
                'phase' => $mean < Transfer::PHASE_COHERENCE ? null : rad2deg(atan2($im, $re)),
                'coherence' => $mean,
            ];
        }

        return $bands;
    }

    /**
     * @return list<float>
     */
    private function window(int $size): array
    {
        if (!isset($this->windows[$size])) {
            $window = [];
            for ($i = 0; $i < $size; ++$i) {
                $window[] = 0.5 - 0.5 * cos(2 * M_PI * $i / ($size - 1));
            }
            $this->windows[$size] = $window;
        }

        return $this->windows[$size];
    }

    private static function wrap(float $radians): float
    {
        return atan2(sin($radians), cos($radians));
    }
}
