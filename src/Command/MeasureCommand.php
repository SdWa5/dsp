<?php

namespace App\Command;

use App\Bridge\BridgeClient;
use App\Bridge\BridgeException;
use App\Measurement\Calibration;
use App\Measurement\Interrupt;
use App\Measurement\Jit;
use App\Measurement\Levels;
use App\Measurement\Playback;
use App\Measurement\RecorderFactory;
use App\Measurement\Response;
use App\Measurement\Solo;
use App\Measurement\Spectrum;
use App\Measurement\TestTone;
use App\Measurement\Transfer;
use App\Measurement\TransferFunction;
use App\Measurement\WavReader;
use App\Protocol\Channel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Plays the device's test tone on some outputs through a running bridge, records inputs of the interface and
 * prints their levels and third-octave bands.
 *
 * The outputs given play alone (see Solo), and the reference output keeps playing for the loopback. Every
 * recording puts mutes, gains and the tone back afterwards, also when it fails (see Playback). The recording
 * and a JSON file with the results stay in --dir. --wav analyses an existing recording without the bridge.
 * --reference names the input that records the reference loopback, and every other channel then also gets its
 * transfer function against it (see TransferFunction): delay, polarity, coherence and bands with phase.
 * --restore replays what a crashed measurement left in its restore file. Ctrl+C stops before the recording and
 * puts the device back.
 */
#[AsCommand('trackdsp:measure', 'Play the test tone on outputs, record the interface and print levels and third-octave bands (needs a running bridge)')]
final class MeasureCommand extends Command implements SignalableCommandInterface
{
    private const MIN_SECONDS = 2;

    /**
     * Seconds at the start of a recording that the analysis leaves out. The UMC1820 sometimes starts a capture
     * with a click of a few milliseconds on a line input, up to -16 dBFS on an input that is silent afterwards.
     */
    private const SKIP = 0.1;

    /** A way coherent over fewer octaves gets a note that its delay and polarity come from a band-limited peak */
    private const BROADBAND_OCTAVES = 3;

    private ?Interrupt $interrupt = null;

    public function __construct(
        #[Autowire('%trackdsp.bridge_socket%')]
        private readonly string $defaultSocket,
        #[Autowire('%trackdsp.measure_dir%')]
        private readonly string $defaultDirectory,
        private readonly RecorderFactory $recorders = new RecorderFactory(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('outputs', InputArgument::OPTIONAL, 'Comma-separated outputs to play alone, e.g. out3 or out1,out2. Without it every output plays as set')
            ->addOption('channels', 'c', InputOption::VALUE_REQUIRED, 'Comma-separated inputs of the interface to record and analyse (1-based)', '1')
            ->addOption('device', 'd', InputOption::VALUE_REQUIRED, 'ALSA capture device, e.g. plughw:CARD=UMC1820,DEV=0', $this->recorders->defaultDevice())
            ->addOption('signal', null, InputOption::VALUE_REQUIRED, 'pink, white, sine:<Hz> or none (the analog inputs play)', 'pink')
            ->addOption('trim', null, InputOption::VALUE_REQUIRED, 'dB added to the gain of every played output except the reference', '0')
            ->addOption('allow-boost', null, InputOption::VALUE_NONE, 'Allow a positive --trim')
            ->addOption('reference-output', null, InputOption::VALUE_REQUIRED, 'Output that keeps playing untrimmed for the loopback, or none', 'out8')
            ->addOption('seconds', null, InputOption::VALUE_REQUIRED, 'Recording length in whole seconds', '10')
            ->addOption('settle', null, InputOption::VALUE_REQUIRED, 'Seconds of tone before the recording', '2')
            ->addOption('cal', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Mic calibration file for the first channel, or <channel>:<file> (repeatable, corrects the bands only)')
            ->addOption('relative', null, InputOption::VALUE_REQUIRED, 'Show the other channels relative to this one')
            ->addOption('reference', null, InputOption::VALUE_REQUIRED, 'Input that records the reference loopback, for the transfer function of every other channel')
            ->addOption('wav', null, InputOption::VALUE_REQUIRED, 'Analyse this recording instead of measuring, without the bridge')
            ->addOption('save', null, InputOption::VALUE_REQUIRED, 'Base name of the recording and its JSON in --dir (default: measure-<time>)')
            ->addOption('restore', null, InputOption::VALUE_NONE, 'Replay the restore file a crashed measurement left behind, and stop')
            ->addOption('socket', 's', InputOption::VALUE_REQUIRED, 'Bridge socket (default: $TRACKDSP_BRIDGE_SOCKET or var/bridge.sock)')
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Directory for recordings and results (default: var/measure)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Jit::enable();
        $io = new SymfonyStyle($input, $output);
        $this->interrupt = new Interrupt();
        if ($input->getOption('restore')) {
            return $this->restore(self::optional($input, 'socket') ?? $this->defaultSocket, $io);
        }
        try {
            $channels = self::channels($input);
            $relative = null === ($value = self::optional($input, 'relative')) ? null : self::input($value, '--relative');
            if (null !== $relative && !\in_array($relative, $channels, true)) {
                throw new \InvalidArgumentException(\sprintf('--relative=%d is not among the recorded channels %s.', $relative, implode(',', $channels)));
            }
            $reference = null === ($value = self::optional($input, 'reference')) ? null : self::input($value, '--reference');
            if (null !== $reference && (!\in_array($reference, $channels, true) || 1 === \count($channels))) {
                throw new \InvalidArgumentException(\sprintf('--reference=%d must be one of at least two recorded channels, got %s.', $reference, implode(',', $channels)));
            }
            $calibrations = self::calibrations($input, $channels);
            $wav = self::optional($input, 'wav');
            $name = self::optional($input, 'save');
            if (null !== $name) {
                $name = preg_replace('/\.(wav|json)$/i', '', $name) ?? $name;
                if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $name)) {
                    throw new \InvalidArgumentException('--save takes a file name without a directory, of letters, digits, dot, dash and underscore.');
                }
            }
            $plan = null === $wav ? $this->plan($input) : null;
            if (null !== $wav && null !== $input->getArgument('outputs')) {
                throw new \InvalidArgumentException('--wav analyses an existing recording. Leave out the outputs.');
            }
        } catch (\InvalidArgumentException|\OutOfRangeException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        $directory = self::optional($input, 'dir') ?? $this->defaultDirectory;
        try {
            $meta = [];
            if (null === $plan) {
                $json = null === $name ? null : self::path($directory, $name.'.json');
            } else {
                $name ??= 'measure-'.date('Ymd-His');
                $wav = self::path($directory, $name.'.wav');
                $json = self::path($directory, $name.'.json');
                $meta = $this->record($plan, $channels, $wav, $io);
            }
            [$analysis, $samples] = self::analyse($wav, $channels, $calibrations);
            self::report($analysis, $relative, $io);
            $transfers = null === $reference ? [] : self::transfers($samples, $reference, $io);
            if (null !== $json) {
                file_put_contents($json, json_encode(self::json($wav, $meta, $analysis, $calibrations, $relative, $reference, $transfers), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));
                $io->writeln(\sprintf('Saved %s%s.', null === $plan ? '' : $wav.' and ', $json));
            }
        } catch (BridgeException|\RuntimeException|\InvalidArgumentException|\LengthException|\JsonException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * @return list<int>
     */
    public function getSubscribedSignals(): array
    {
        return \defined('SIGINT') ? [\SIGINT, \SIGTERM] : [];
    }

    /**
     * Lets Playback stop before the recording and put the device back. arecord gets the signal too.
     */
    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->interrupt?->trigger();

        return false;
    }

    private function restore(string $socket, SymfonyStyle $io): int
    {
        try {
            $saved = (new Playback(new BridgeClient($socket)))->recover();
        } catch (BridgeException|\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        if (null === $saved) {
            $io->writeln('Nothing to restore.');
        } else {
            $riders = array_map(static fn (array $rider): string => Channel::label((int) ($rider['channel'] ?? -1)), $saved['riders']);
            $io->success(\sprintf('Put back %d frame%s from the measurement of %s%s.', \count($saved['frames']), 1 === \count($saved['frames']) ? '' : 's', $saved['time'], [] === $riders ? '' : ' and started the riders on '.implode(', ', $riders).' again'));
        }

        return Command::SUCCESS;
    }

    /**
     * The playback options, checked before anything talks to the bridge.
     *
     * @return array{outputs: list<int>|null, trim: float, reference: int|null, signal: string, seconds: int, settle: float, device: string, socket: string}
     */
    private function plan(InputInterface $input): array
    {
        $outputs = null;
        $argument = $input->getArgument('outputs');
        if (\is_string($argument) && '' !== trim($argument)) {
            $outputs = [];
            foreach (explode(',', $argument) as $name) {
                $channel = Channel::parse($name);
                if (!Channel::isOutput($channel)) {
                    throw new \InvalidArgumentException(\sprintf('Only outputs play the test tone, got %s.', Channel::label($channel)));
                }
                $outputs[] = $channel;
            }
            $outputs = array_values(array_unique($outputs));
        }
        $trim = self::number($input, 'trim');
        if ($trim > 0 && !$input->getOption('allow-boost')) {
            throw new \InvalidArgumentException('A positive --trim needs --allow-boost.');
        }
        if ($trim < -60 || $trim > 12) {
            throw new \InvalidArgumentException('--trim must be -60..+12 dB.');
        }
        $reference = strtolower((string) self::optional($input, 'reference-output'));
        $reference = '' === $reference || 'none' === $reference ? null : Channel::parse($reference);
        if (null !== $reference && !Channel::isOutput($reference)) {
            throw new \InvalidArgumentException(\sprintf('--reference-output must be an output or none, got %s.', Channel::label($reference)));
        }
        $signal = (string) self::optional($input, 'signal');
        TestTone::parse($signal);
        $seconds = self::integer($input, 'seconds');
        if ($seconds < self::MIN_SECONDS) {
            throw new \InvalidArgumentException(\sprintf('--seconds must be %d or more, the band analysis needs 1.4 s.', self::MIN_SECONDS));
        }
        $settle = self::number($input, 'settle');
        if ($settle < 0) {
            throw new \InvalidArgumentException('--settle must not be negative.');
        }

        return [
            'outputs' => $outputs, 'trim' => $trim, 'reference' => $reference, 'signal' => $signal, 'seconds' => $seconds,
            'settle' => $settle, 'device' => (string) self::optional($input, 'device'), 'socket' => self::optional($input, 'socket') ?? $this->defaultSocket,
        ];
    }

    /**
     * Plays and records, and returns what the JSON keeps about it.
     *
     * @param array{outputs: list<int>|null, trim: float, reference: int|null, signal: string, seconds: int, settle: float, device: string, socket: string} $plan
     * @param non-empty-list<int>                                                                                                                           $channels
     *
     * @return array<string, mixed>
     */
    private function record(array $plan, array $channels, string $wav, SymfonyStyle $io): array
    {
        $bridge = new BridgeClient($plan['socket']);
        $state = $bridge->request('state');
        if (!\is_array($state)) {
            throw new BridgeException('The bridge sent no device state.', 409);
        }
        $solo = Solo::plan($state, $plan['outputs'], $plan['trim'], $plan['reference']);
        $tone = TestTone::parse($plan['signal'], $solo->original->freq);
        $played = array_map(static fn (int $ch): string => Channel::label($ch).($ch === $solo->reference ? ' (reference)' : ''), $solo->played);
        $io->writeln(\sprintf('%s on %s%s.', $tone->label(), [] === $played ? 'no output' : implode(', ', $played), $solo->solo ? ', the other outputs muted' : ''));
        if ([] !== $solo->notes) {
            $io->note($solo->notes);
        }
        if ([] !== $solo->warnings) {
            $io->warning($solo->warnings);
        }
        if ([] !== $solo->gains) {
            $io->table(['Output', 'Gain', 'Recording', 'Trim'], array_map(
                static fn (int $ch, array $g): array => [Channel::label($ch), \sprintf('%+.1f dB', $g['from']), \sprintf('%+.1f dB', $g['to']), \sprintf('%+.1f dB', $g['to'] - $g['from'])],
                array_keys($solo->gains), $solo->gains,
            ));
        }
        $recorder = $this->recorders->create($plan['device'], max($channels));
        $io->writeln(\sprintf('Recording %d s from %s…', $plan['seconds'], $plan['device']));
        (new Playback($bridge, interrupt: $this->interrupt))->run($solo->setup, $tone, $solo->original, $solo->restore, $plan['settle'], static fn (): string => $recorder->record($plan['seconds'], $wav));

        return [
            'time' => date(\DATE_ATOM),
            'device' => $plan['device'],
            'signal' => $tone->label(),
            'played' => array_map(Channel::label(...), $solo->played),
            'reference' => null === $solo->reference ? null : Channel::label($solo->reference),
            'solo' => $solo->solo,
            'gains' => array_combine(array_map(Channel::label(...), array_keys($solo->gains)), array_values($solo->gains)),
            'notes' => $solo->notes,
            'warnings' => $solo->warnings,
            'seconds' => $plan['seconds'],
            'settle' => $plan['settle'],
        ];
    }

    /**
     * Levels and bands of every channel, without the first SKIP seconds.
     *
     * @param list<int>                                        $channels
     * @param array<int, array{file: string, curve: Response}> $calibrations
     *
     * @return array{array<int, array{levels: Levels, bands: list<array{hz: float, label: string, db: float}>}>, array<int, array{samples: list<float>, rate: int}>}
     */
    private static function analyse(string $wav, array $channels, array $calibrations): array
    {
        $spectrum = new Spectrum(65536);
        $analysis = $kept = [];
        foreach ($channels as $channel) {
            $recording = WavReader::read($wav, $channel);
            $samples = \array_slice($recording['samples'], (int) round($recording['rate'] * self::SKIP));
            $bands = $spectrum->bands($samples, $recording['rate']);
            if (isset($calibrations[$channel])) {
                $curve = $calibrations[$channel]['curve'];
                $bands = array_map(static fn (array $band): array => ['db' => $band['db'] <= Levels::FLOOR ? $band['db'] : $band['db'] - $curve->at($band['hz'])] + $band, $bands);
            }
            $analysis[$channel] = ['levels' => Levels::of($samples), 'bands' => $bands];
            $kept[$channel] = ['samples' => $samples, 'rate' => $recording['rate']];
        }

        return [$analysis, $kept];
    }

    /**
     * The transfer function of every channel against the reference channel, printed as a summary and a band
     * table. A reference the analysis refuses, for example because it clips, gives a warning and no transfers,
     * so that the levels and the bands above still count.
     *
     * @param array<int, array{samples: list<float>, rate: int}> $samples
     *
     * @return array<int, Transfer>
     */
    private static function transfers(array $samples, int $reference, SymfonyStyle $io): array
    {
        $function = new TransferFunction();
        $transfers = [];
        try {
            foreach ($samples as $channel => $recording) {
                if ($channel !== $reference) {
                    $transfers[$channel] = $function->analyse($samples[$reference]['samples'], $recording['samples'], $recording['rate']);
                }
            }
        } catch (\InvalidArgumentException|\LengthException $e) {
            $io->warning(\sprintf('No transfer function against input %d. %s', $reference, $e->getMessage()));

            return [];
        }
        if ([] === $transfers) {
            return [];
        }
        $io->writeln(\sprintf('Transfer function against input %d:', $reference));
        $io->table(['Input', 'Delay', 'Polarity', 'Coherent', 'γ²'], array_map(static fn (int $channel, Transfer $t): array => [
            $channel,
            null === $t->delay ? \sprintf('no clear peak (lag %d samples)', $t->lag) : \sprintf('%.2f samples, %.3f ms', $t->delay, $t->delayMs()),
            $t->polarity->value,
            null === ($range = $t->coherentRange()) ? 'nowhere' : $range['from'].' to '.$range['to'],
            \sprintf('%.2f', $range['coherence'] ?? $t->meanCoherence()),
        ], array_keys($transfers), $transfers));
        $narrow = array_keys(array_filter($transfers, static fn (Transfer $t): bool => null !== $t->delay && ($t->coherentRange()['octaves'] ?? 0.0) < self::BROADBAND_OCTAVES));
        if ([] !== $narrow) {
            $io->note(\sprintf('%s %s coherent over less than %d octaves. There the delay is the group delay of that band and the polarity only a hint, so align such a way by its phase in the crossover region.', 1 === \count($narrow) ? 'Input' : 'Inputs', implode(', ', $narrow).(1 === \count($narrow) ? ' is' : ' are'), self::BROADBAND_OCTAVES));
        }
        $rows = [];
        foreach ($transfers[array_key_first($transfers)]->bands as $i => $band) {
            $row = [$band['label']];
            foreach ($transfers as $t) {
                $b = $t->bands[$i];
                array_push($row, \sprintf('%+.1f', $b['db']), null === $b['phase'] ? '–' : \sprintf('%+.0f°', $b['phase']), \sprintf('%.2f', $b['coherence']));
            }
            $rows[] = $row;
        }
        $headers = ['Band'];
        foreach (array_keys($transfers) as $channel) {
            array_push($headers, 'In'.$channel.' dB', 'Phase', 'γ²');
        }
        $io->writeln(\sprintf('Transfer function bands, the phase without the delay and hidden below γ² %.1f:', Transfer::PHASE_COHERENCE));
        $io->table($headers, $rows);
        foreach ($transfers as $t) {
            if ([] !== $t->warnings) {
                $io->warning($t->warnings);
                break;
            }
        }

        return $transfers;
    }

    /**
     * @param array<int, array{levels: Levels, bands: list<array{hz: float, label: string, db: float}>}> $analysis
     */
    private static function report(array $analysis, ?int $relative, SymfonyStyle $io): void
    {
        $base = null === $relative ? null : $analysis[$relative];
        $io->table(
            array_merge(['Input', 'RMS', 'Peak', 'Crest', 'Clipped'], null === $relative ? [] : ['RMS vs In'.$relative]),
            array_map(static fn (int $channel, array $a): array => array_merge(
                [$channel, self::db($a['levels']->rms), self::db($a['levels']->peak), \sprintf('%.1f dB', $a['levels']->crest()), $a['levels']->clipped],
                null === $base ? [] : [self::db($a['levels']->rms, $base['levels']->rms)],
            ), array_keys($analysis), $analysis),
        );
        $columns = array_values(array_filter(array_keys($analysis), static fn (int $channel): bool => $channel !== $relative));
        if ([] !== $columns) {
            $rows = [];
            foreach ($analysis[$columns[0]]['bands'] as $i => $band) {
                $rows[] = array_merge([$band['label']], array_map(static fn (int $channel): string => self::db($analysis[$channel]['bands'][$i]['db'], $base['bands'][$i]['db'] ?? null), $columns));
            }
            $io->writeln(null === $relative ? 'Third-octave bands in dBFS:' : \sprintf('Third-octave bands relative to input %d in dB:', $relative));
            $io->table(array_merge(['Band'], array_map(static fn (int $channel): string => 'In'.$channel, $columns)), $rows);
        }
        foreach ($analysis as $channel => $a) {
            if ($a['levels']->peak <= Levels::FLOOR) {
                $io->warning(\sprintf('Input %d delivers digital silence.', $channel));
            } elseif ($a['levels']->clipped > 0) {
                $io->warning(\sprintf('Input %d clipped %d samples. Lower its gain or press PAD.', $channel, $a['levels']->clipped));
            }
        }
    }

    /**
     * @param array<string, mixed>                                                                       $meta
     * @param array<int, array{levels: Levels, bands: list<array{hz: float, label: string, db: float}>}> $analysis
     * @param array<int, array{file: string, curve: Response}>                                           $calibrations
     * @param array<int, Transfer>                                                                       $transfers
     *
     * @return array<string, mixed>
     */
    private static function json(string $wav, array $meta, array $analysis, array $calibrations, ?int $relative, ?int $reference, array $transfers): array
    {
        $channels = [];
        foreach ($analysis as $channel => $a) {
            $channels[(string) $channel] = [
                'levels' => $a['levels']->toArray(),
                'calibration' => $calibrations[$channel]['file'] ?? null,
                'bands' => array_map(static fn (array $band): array => ['hz' => round($band['hz'], 1), 'label' => $band['label'], 'db' => round($band['db'], 2)], $a['bands']),
                'transfer' => isset($transfers[$channel]) ? $transfers[$channel]->toArray() : null,
            ];
        }

        return ['wav' => $wav] + $meta + ['relative' => $relative, 'reference_input' => $reference, 'channels' => $channels];
    }

    /**
     * A level, or with $base its difference to that one. Silence reads as such.
     */
    private static function db(float $db, ?float $base = null): string
    {
        if ($db <= Levels::FLOOR || (null !== $base && $base <= Levels::FLOOR)) {
            return null === $base && $db <= Levels::FLOOR ? 'silent' : '–';
        }

        return null === $base ? \sprintf('%.1f', $db) : \sprintf('%+.1f', $db - $base);
    }

    /**
     * @return non-empty-list<int>
     */
    private static function channels(InputInterface $input): array
    {
        $channels = [];
        foreach (explode(',', (string) self::optional($input, 'channels')) as $value) {
            $channels[] = self::input($value, '--channels');
        }

        return array_values(array_unique($channels));
    }

    /**
     * --cal=<file> corrects the first recorded channel, --cal=<channel>:<file> the one named.
     *
     * @param non-empty-list<int> $channels
     *
     * @return array<int, array{file: string, curve: Response}>
     */
    private static function calibrations(InputInterface $input, array $channels): array
    {
        $calibrations = [];
        foreach (array_filter((array) $input->getOption('cal'), 'is_string') as $value) {
            [$channel, $file] = preg_match('/^(\d+):(.+)$/', $value, $m) ? [self::input($m[1], '--cal'), $m[2]] : [$channels[0], $value];
            if (!\in_array($channel, $channels, true)) {
                throw new \InvalidArgumentException(\sprintf('--cal names channel %d, which is not recorded.', $channel));
            }
            $calibrations[$channel] = ['file' => $file, 'curve' => Calibration::read($file)];
        }

        return $calibrations;
    }

    private static function input(string $value, string $option): int
    {
        $value = trim($value);
        if (!preg_match('/^\d+$/', $value) || (int) $value < 1 || (int) $value > 64) {
            throw new \InvalidArgumentException(\sprintf('%s takes inputs of the interface from 1, got "%s".', $option, $value));
        }

        return (int) $value;
    }

    /**
     * A new file in the directory, which is created when missing. An existing file is never overwritten.
     */
    private static function path(string $directory, string $name): string
    {
        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new \RuntimeException(\sprintf('Cannot create "%s".', $directory));
        }
        $path = $directory.'/'.$name;
        if (file_exists($path)) {
            throw new \RuntimeException(\sprintf('%s exists already. Choose another name with --save.', $path));
        }

        return $path;
    }

    private static function optional(InputInterface $input, string $option): ?string
    {
        $value = $input->getOption($option);

        return \is_string($value) && '' !== $value ? $value : null;
    }

    private static function number(InputInterface $input, string $option): float
    {
        $value = self::optional($input, $option);
        if (null === $value || !is_numeric($value)) {
            throw new \InvalidArgumentException(\sprintf('--%s must be a number.', $option));
        }

        return (float) $value;
    }

    private static function integer(InputInterface $input, string $option): int
    {
        $value = self::optional($input, $option);
        if (null === $value || !preg_match('/^\d+$/', $value)) {
            throw new \InvalidArgumentException(\sprintf('--%s must be a whole number.', $option));
        }

        return (int) $value;
    }
}
