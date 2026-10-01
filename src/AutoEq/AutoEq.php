<?php

namespace App\AutoEq;

use App\Bridge\BridgeClient;
use App\Bridge\BridgeException;
use App\Measurement\Interrupt;
use App\Measurement\Playback;
use App\Measurement\RecorderFactory;
use App\Measurement\Response;
use App\Measurement\Solo;
use App\Measurement\Spectrum;
use App\Measurement\TestTone;
use App\Measurement\WavReader;
use App\Model\Device;
use App\Protocol\Channel;
use App\Protocol\Commands;
use App\Protocol\Frame;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The steps of the supervised auto EQ, shared by trackdsp:autoeq and the browser editor.
 *
 * Every recording is self-contained: it bypasses the bands the auto EQ owns on the chosen outputs and plays
 * the device's pink noise through Playback, which pauses the gain riders and puts tone, bands and riders back
 * whatever happens. Between recordings the device is exactly as it was. Only apply() changes it for good, and
 * revert() undoes that from the backup taken at the start.
 */
final class AutoEq
{
    public const PINK_NOISE = TestTone::PINK;
    private const SESSION = 'session.json';

    private readonly Playback $playback;

    public function __construct(
        private readonly BridgeClient $bridge,
        #[Autowire('%trackdsp.autoeq_dir%')]
        private readonly string $directory,
        private readonly RecorderFactory $recorders = new RecorderFactory(),
        ?Interrupt $interrupt = null,
    ) {
        $this->playback = new Playback($bridge, interrupt: $interrupt);
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function recorders(): RecorderFactory
    {
        return $this->recorders;
    }

    /**
     * @return array<mixed>
     */
    public function state(): array
    {
        $state = $this->bridge->request('state');
        if (!\is_array($state)) {
            throw new BridgeException('The bridge sent no device state.', 409);
        }

        return $state;
    }

    /**
     * What makes a measurement of these outputs misleading: an output at compressor 1:1.0 with a gain other than
     * 0 dB adds the spectrum mirrored at 24 kHz, which the fit would try to correct (Solo::exaggeratedGain()).
     *
     * @param array<mixed> $state
     * @param list<int>    $outputs
     *
     * @return list<string>
     */
    public static function warnings(array $state, array $outputs): array
    {
        $warnings = [];
        foreach ($outputs as $channel) {
            $output = $state['outputs'][$channel - Channel::INPUTS] ?? null;
            if (\is_array($output) && null !== ($warning = Solo::exaggeratedGain($channel, $output))) {
                $warnings[] = $warning.' Set Limit, or 1:1.1 from +20 dB, before measuring.';
            }
        }

        return $warnings;
    }

    /**
     * Saves the device state as a preset file that also names the corrected outputs, and returns its path.
     *
     * @param array<mixed> $state
     * @param list<int>    $outputs
     */
    public function backup(array $state, array $outputs, string $stamp): string
    {
        $path = $this->path('backup-'.$stamp.'.json');
        $data = ['format' => Device::FILE_FORMAT, 'version' => Device::FILE_VERSION, 'autoeq' => ['outputs' => $outputs], 'device' => $state];
        file_put_contents($path, json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));

        return $path;
    }

    /**
     * Records one mic position with pink noise and the owned bands bypassed, then restores the device.
     *
     * @param array<mixed> $state
     * @param list<int>    $outputs
     */
    public function record(array $state, array $outputs, int $bands, string $device, int $micChannel, int $seconds, float $settle, string $path): string
    {
        $recorder = $this->recorders->create($device, $micChannel);
        $bypass = [];
        foreach ($outputs as $channel) {
            foreach (\array_slice(self::bands($state, $channel), 0, $bands) as $n => $band) {
                $bypass[] = Commands::peq($channel, $n, $band['gain'], $band['freq'], $band['q'], $band['type'], true);
            }
        }
        $original = TestTone::fromState($state);

        return $this->playback->run(
            $bypass, new TestTone(TestTone::PINK, $original->freq), $original, self::peqFrames($state, $outputs, $bands), $settle,
            static fn (): string => $recorder->record($seconds, $path),
        );
    }

    /**
     * Spectrum of one recording on Response::grid(), without calibration.
     */
    public static function spectrum(string $wav, int $micChannel): Response
    {
        $recording = WavReader::read($wav, $micChannel);

        return (new Spectrum())->analyse($recording['samples'], $recording['rate'], Response::grid());
    }

    /**
     * Power average of the spectra, minus the mic calibration.
     *
     * @param list<Response> $spectra
     */
    public static function average(array $spectra, ?Response $calibration): Response
    {
        if ([] === $spectra) {
            throw new \InvalidArgumentException('Nothing to analyse. Record or upload at least one measurement.');
        }
        $average = Response::average($spectra);

        return null === $calibration ? $average : $average->minus($calibration);
    }

    /**
     * The fit with the curves the editor draws, all on Response::grid(): the measurement at the target's
     * level, the target, the EQ and the predicted result.
     *
     * @return array<string, mixed>
     */
    public static function describe(Response $measured, TargetCurve $target, FitSettings $settings, FitResult $result): array
    {
        $grid = Response::grid();
        $measured = $measured->resample($grid);
        $goal = $target->on($grid);
        $offset = $goal->mean($settings->low, $settings->high) - $measured->mean($settings->low, $settings->high);
        $eq = array_map(static fn (float $f): float => array_sum(array_map(static fn (FittedBand $b): float => $b->responseAt($f), $result->bands)), $grid);
        $round = static fn (array $values): array => array_map(static fn (float $v): float => round($v, 2), $values);

        return [
            'target' => $target->describe(),
            'bands' => array_map(static fn (FittedBand $b): array => $b->toArray(), $result->bands),
            'freqs' => $round($grid),
            'measured' => $round(array_map(static fn (float $v): float => $v + $offset, $measured->db)),
            'goal' => $round($goal->db),
            'eq' => $round($eq),
            'after' => $round(array_map(static fn (float $v, float $e): float => $v + $offset + $e, $measured->db, $eq)),
            'rms_before' => round(FitResult::rms($result->before), 2),
            'peak_before' => round(FitResult::peak($result->before), 2),
            'rms_after' => round(FitResult::rms($result->after), 2),
            'peak_after' => round(FitResult::peak($result->after), 2),
        ];
    }

    /**
     * Writes the fitted bands from band 1 up. The other owned bands are set flat and bypassed.
     *
     * @param array<mixed>     $state
     * @param list<int>        $outputs
     * @param list<FittedBand> $fitted
     */
    public function apply(array $state, array $outputs, int $bands, array $fitted): void
    {
        $frames = [];
        foreach ($outputs as $channel) {
            foreach (\array_slice(self::bands($state, $channel), 0, $bands) as $n => $band) {
                $fit = $fitted[$n] ?? null;
                $frames[] = self::hex(null === $fit
                    ? Commands::peq($channel, $n, 120, $band['freq'], $band['q'], $band['type'], true)
                    : Commands::peq($channel, $n, $fit->gain, $fit->freq, $fit->q, 0, false));
            }
        }
        $this->write($frames);
    }

    /**
     * Restores all PEQ bands of the outputs from a backup: the outputs given, else the ones the backup names,
     * else all outputs. Returns the outputs restored.
     *
     * @param list<int>|null $outputs
     *
     * @return list<int>
     */
    public function revert(string $path, ?array $outputs = null): array
    {
        $data = json_decode((string) @file_get_contents($path), true, 64, \JSON_THROW_ON_ERROR);
        if (!\is_array($data) || Device::FILE_FORMAT !== ($data['format'] ?? null) || !\is_array($data['device'] ?? null)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a trackdsp backup.', basename($path)));
        }
        $named = \is_array($data['autoeq'] ?? null) ? array_values(array_filter((array) ($data['autoeq']['outputs'] ?? []), 'is_int')) : [];
        $outputs ??= [] !== $named ? $named : range(Channel::output(1), Channel::output(Channel::OUTPUTS));
        $this->write(self::restoreFrames($data['device'], $outputs, 7));

        return $outputs;
    }

    /**
     * The backups in the directory, newest first.
     *
     * @return list<array{file: string, time: int, outputs: list<int>}>
     */
    public function backups(int $limit = 20): array
    {
        $files = glob($this->directory.'/backup-*.json') ?: [];
        rsort($files);
        $backups = [];
        foreach (\array_slice($files, 0, $limit) as $file) {
            $data = json_decode((string) @file_get_contents($file), true);
            $outputs = \is_array($data) && \is_array($data['autoeq'] ?? null) ? array_values(array_filter((array) ($data['autoeq']['outputs'] ?? []), 'is_int')) : [];
            $backups[] = ['file' => basename($file), 'time' => (int) filemtime($file), 'outputs' => $outputs];
        }

        return $backups;
    }

    /**
     * A file of the directory by its base name, null for anything else.
     */
    public function file(string $name): ?string
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $name)) {
            return null;
        }
        $path = $this->directory.'/'.$name;

        return is_file($path) ? $path : null;
    }

    /**
     * A new path in the directory, which is created when missing.
     */
    public function path(string $name): string
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0o775, true) && !is_dir($this->directory)) {
            throw new \RuntimeException(\sprintf('Cannot create "%s".', $this->directory));
        }

        return $this->directory.'/'.$name;
    }

    public function session(): ?Session
    {
        $file = $this->directory.'/'.self::SESSION;
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);
        try {
            return \is_array($data) ? Session::fromArray($data) : null;
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    public function saveSession(Session $session): void
    {
        file_put_contents($this->path(self::SESSION), json_encode($session->toArray(), \JSON_THROW_ON_ERROR));
    }

    public function clearSession(): void
    {
        @unlink($this->directory.'/'.self::SESSION);
    }

    /**
     * @param list<string> $frames
     */
    public function write(array $frames): void
    {
        if ([] !== $frames) {
            $this->bridge->request('write', ['frames' => $frames]);
        }
    }

    /**
     * The PEQ bands of an output from a device state.
     *
     * @param array<mixed> $state
     *
     * @return list<array{gain: int, freq: int, q: int, type: int, bypass: bool}>
     */
    public static function bands(array $state, int $channel): array
    {
        $peq = \is_array($state['outputs'] ?? null) && \is_array($state['outputs'][$channel - 8] ?? null) ? ($state['outputs'][$channel - 8]['peq'] ?? null) : null;
        if (!\is_array($peq)) {
            throw new \InvalidArgumentException(\sprintf('The device state has no PEQ for %s.', Channel::label($channel)));
        }
        $bands = [];
        foreach ($peq as $band) {
            if (!\is_array($band)) {
                continue;
            }
            $bands[] = [
                'gain' => \is_int($band['gain'] ?? null) ? $band['gain'] : 120,
                'freq' => \is_int($band['freq'] ?? null) ? $band['freq'] : 100,
                'q' => \is_int($band['q'] ?? null) ? $band['q'] : 25,
                'type' => \is_int($band['type'] ?? null) ? $band['type'] : 0,
                'bypass' => true === ($band['bypass'] ?? false),
            ];
        }

        return $bands;
    }

    /**
     * @param array<mixed> $state
     * @param list<int>    $outputs
     *
     * @return list<string>
     */
    public static function restoreFrames(array $state, array $outputs, int $bands): array
    {
        return array_map(self::hex(...), self::peqFrames($state, $outputs, $bands));
    }

    public static function hex(Frame $frame): string
    {
        return Playback::hex($frame);
    }

    /**
     * The owned PEQ bands of the outputs as the state holds them.
     *
     * @param array<mixed> $state
     * @param list<int>    $outputs
     *
     * @return list<Frame>
     */
    private static function peqFrames(array $state, array $outputs, int $bands): array
    {
        $frames = [];
        foreach ($outputs as $channel) {
            foreach (\array_slice(self::bands($state, $channel), 0, $bands) as $n => $band) {
                $frames[] = Commands::peq($channel, $n, $band['gain'], $band['freq'], $band['q'], $band['type'], $band['bypass']);
            }
        }

        return $frames;
    }
}
