<?php

namespace App\Command;

use App\AutoEq\FitResult;
use App\AutoEq\FitSettings;
use App\AutoEq\FittedBand;
use App\AutoEq\PeqFitter;
use App\AutoEq\TargetCurve;
use App\Bridge\BridgeClient;
use App\Bridge\BridgeException;
use App\Measurement\Calibration;
use App\Measurement\Recorder;
use App\Measurement\Response;
use App\Measurement\Spectrum;
use App\Measurement\WavReader;
use App\Model\Device;
use App\Protocol\Channel;
use App\Protocol\Commands;
use App\Protocol\Frame;
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
 * Supervised automatic EQ through a running bridge.
 *
 * Backs up the device, bypasses the bands it owns on the chosen outputs, plays the device's pink noise,
 * records the measurement mic at one or more positions, fits peaking bands to the target and writes
 * them only after confirmation. Whatever goes wrong, the test tone is switched back and unwritten bands
 * are restored. --wav analyses existing recordings instead of measuring, --revert restores a backup.
 */
#[AsCommand('trackdsp:autoeq', 'Measure with pink noise and a measurement mic and fit the output PEQ to a target (needs a running bridge)')]
final class AutoEqCommand extends Command implements SignalableCommandInterface
{
    private const PINK_NOISE = 1;

    public function __construct(
        #[Autowire('%trackdsp.bridge_socket%')]
        private readonly string $defaultSocket,
        #[Autowire('%kernel.project_dir%/var/autoeq')]
        private readonly string $defaultDirectory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('outputs', InputArgument::REQUIRED, 'Comma-separated outputs to correct, e.g. out1,out2')
            ->addOption('device', 'd', InputOption::VALUE_REQUIRED, 'ALSA capture device, e.g. plughw:CARD=UMC1820', 'default')
            ->addOption('mic-channel', 'm', InputOption::VALUE_REQUIRED, 'Input of the interface the mic is on (1-based)', '1')
            ->addOption('seconds', null, InputOption::VALUE_REQUIRED, 'Recording length per position', '8')
            ->addOption('positions', 'p', InputOption::VALUE_REQUIRED, 'Mic positions to average', '1')
            ->addOption('settle', null, InputOption::VALUE_REQUIRED, 'Seconds of noise before the first recording', '2')
            ->addOption('wav', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Analyse this recording instead of measuring (repeatable)')
            ->addOption('cal', null, InputOption::VALUE_REQUIRED, 'Mic calibration file ("frequency dB" lines)')
            ->addOption('target', null, InputOption::VALUE_REQUIRED, 'flat, tilt:<dB per octave> or file:<path>', 'flat')
            ->addOption('low', null, InputOption::VALUE_REQUIRED, 'Lowest frequency to correct in Hz', '60')
            ->addOption('high', null, InputOption::VALUE_REQUIRED, 'Highest frequency to correct in Hz', '16000')
            ->addOption('bands', 'b', InputOption::VALUE_REQUIRED, 'Bands to use (1..7, from band 1 up)', '7')
            ->addOption('max-boost', null, InputOption::VALUE_REQUIRED, 'Largest total boost in dB', '3')
            ->addOption('max-cut', null, InputOption::VALUE_REQUIRED, 'Deepest cut of one band in dB', '9')
            ->addOption('max-q', null, InputOption::VALUE_REQUIRED, 'Narrowest cut', '5')
            ->addOption('tolerance', null, InputOption::VALUE_REQUIRED, 'Leave deviations up to this size alone, in dB', '1')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Write the bands without asking')
            ->addOption('revert', null, InputOption::VALUE_REQUIRED, 'Restore the PEQ of the outputs from this backup and stop')
            ->addOption('socket', 's', InputOption::VALUE_REQUIRED, 'Bridge socket (default: $TRACKDSP_BRIDGE_SOCKET or var/bridge.sock)')
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Directory for backups, recordings and results (default: var/autoeq)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $outputs = self::outputs($input);
            $socket = self::optional($input, 'socket') ?? $this->defaultSocket;
            $bridge = new BridgeClient($socket);
            if (null !== $revert = self::optional($input, 'revert')) {
                return $this->revert($bridge, $outputs, $revert, $io);
            }
            $settings = new FitSettings(
                self::integer($input, 'bands'), self::number($input, 'low'), self::number($input, 'high'),
                self::number($input, 'max-boost'), self::number($input, 'max-cut'), self::number($input, 'max-q'),
                min(2.0, self::number($input, 'max-q')), self::number($input, 'tolerance'),
            );
            $target = TargetCurve::parse((string) self::optional($input, 'target'));
            $calibration = null === ($cal = self::optional($input, 'cal')) ? null : Calibration::read($cal);
            $micChannel = self::integer($input, 'mic-channel');
            /** @var list<string> $wavs */
            $wavs = array_values(array_filter((array) $input->getOption('wav'), 'is_string'));
        } catch (\InvalidArgumentException|\OutOfRangeException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        $directory = self::optional($input, 'dir') ?? $this->defaultDirectory;
        $stamp = date('Ymd-His');
        try {
            $state = $bridge->request('state');
            if (!\is_array($state)) {
                throw new BridgeException('The bridge sent no device state.', 409);
            }
            if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
                throw new \RuntimeException(\sprintf('Cannot create "%s".', $directory));
            }
            $backup = $directory.'/backup-'.$stamp.'.json';
            file_put_contents($backup, json_encode(['format' => Device::FILE_FORMAT, 'version' => Device::FILE_VERSION, 'device' => $state], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));
            $io->writeln(\sprintf('Backup of the device in <info>%s</info>.', $backup));
        } catch (BridgeException|\RuntimeException|\JsonException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $measuring = [] === $wavs;
        $written = false;
        try {
            if ($measuring) {
                $wavs = $this->measure($bridge, $state, $outputs, $settings->bands, $micChannel, $directory.'/measure-'.$stamp, $input, $io);
            }
            $io->writeln(\sprintf('Analysing %d recording%s…', \count($wavs), 1 === \count($wavs) ? '' : 's'));
            $measured = $this->analyse($wavs, $micChannel, $calibration);
            $result = (new PeqFitter())->fit($measured, $target, $settings);
            $this->report($result, $target, $outputs, $io);
            file_put_contents($directory.'/result-'.$stamp.'.json', json_encode([
                'outputs' => array_map(Channel::label(...), $outputs),
                'target' => $target->describe(),
                'bands' => array_map(static fn (FittedBand $b): array => $b->toArray(), $result->bands),
                'before' => ['freqs' => $result->before->freqs, 'db' => $result->before->db],
                'after' => ['freqs' => $result->after->freqs, 'db' => $result->after->db],
                'measured' => ['freqs' => $measured->freqs, 'db' => $measured->db],
            ], \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));

            $labels = implode(', ', array_map(Channel::label(...), $outputs));
            if ([] !== $result->bands && ($input->getOption('apply') || ($input->isInteractive() && $io->confirm(\sprintf('Write these bands to %s?', $labels), false)))) {
                $bridge->request('write', ['frames' => $this->bandFrames($state, $outputs, $settings->bands, $result)]);
                $written = true;
                $io->success(\sprintf('Written to %s. Measure again to verify. Undo with --revert=%s', $labels, $backup));
            } else {
                $io->writeln('Nothing written.');
            }
        } catch (BridgeException|\RuntimeException|\InvalidArgumentException|\LengthException|\JsonException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        } finally {
            if ($measuring && !$written) {
                try {
                    $bridge->request('write', ['frames' => self::restoreFrames($state, $outputs, $settings->bands)]);
                } catch (BridgeException $e) {
                    $io->error('Could not restore the bands: '.$e->getMessage().' Restore them with --revert='.$backup);
                }
            }
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
     * arecord gets the signal too and fails, and the finally blocks switch the tone off and restore the bands.
     */
    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        return false;
    }

    /**
     * @param array<mixed> $state
     * @param list<int>    $outputs
     *
     * @return list<string> recorded files
     */
    private function measure(BridgeClient $bridge, array $state, array $outputs, int $bands, int $micChannel, string $prefix, InputInterface $input, SymfonyStyle $io): array
    {
        $seconds = self::integer($input, 'seconds');
        $positions = max(1, self::integer($input, 'positions'));
        $recorder = new Recorder((string) self::optional($input, 'device'), $micChannel);
        $bypass = [];
        foreach ($outputs as $channel) {
            foreach (array_slice(self::bands($state, $channel), 0, $bands) as $n => $band) {
                $bypass[] = self::hex(Commands::peq($channel, $n, $band['gain'], $band['freq'], $band['q'], $band['type'], true));
            }
        }
        $bridge->request('write', ['frames' => $bypass]);
        $toneMode = \is_int($state['tone_mode'] ?? null) ? $state['tone_mode'] : 0;
        $toneFreq = \is_int($state['tone_freq'] ?? null) ? $state['tone_freq'] : 0;
        $io->warning('Pink noise starts now on every routed output. Keep the level safe.');
        $bridge->request('write', ['frames' => [self::hex(Commands::testTone(self::PINK_NOISE, $toneFreq))]]);
        $files = [];
        try {
            usleep((int) (1e6 * max(0.0, self::number($input, 'settle'))));
            for ($p = 1; $p <= $positions; ++$p) {
                if ($positions > 1 && $input->isInteractive()) {
                    $io->ask(\sprintf('Put the mic at position %d of %d and press Enter', $p, $positions), '');
                }
                $io->writeln(\sprintf('Recording position %d for %d s…', $p, $seconds));
                $files[] = $recorder->record($seconds, \sprintf('%s-%d.wav', $prefix, $p));
            }
        } finally {
            $bridge->request('write', ['frames' => [self::hex(Commands::testTone($toneMode, $toneFreq))]]);
        }

        return $files;
    }

    /**
     * @param list<string> $wavs
     */
    private function analyse(array $wavs, int $micChannel, ?Response $calibration): Response
    {
        $spectrum = new Spectrum();
        $grid = Response::grid();
        $responses = [];
        foreach ($wavs as $wav) {
            $recording = WavReader::read($wav, $micChannel);
            $response = $spectrum->analyse($recording['samples'], $recording['rate'], $grid);
            $responses[] = null === $calibration ? $response : $response->minus($calibration);
        }
        if ([] === $responses) {
            throw new \InvalidArgumentException('Nothing to analyse.');
        }

        return Response::average($responses);
    }

    /**
     * @param list<int> $outputs
     */
    private function report(FitResult $result, TargetCurve $target, array $outputs, SymfonyStyle $io): void
    {
        $io->section(\sprintf('Correction for %s, target %s', implode(', ', array_map(Channel::label(...), $outputs)), $target->describe()));
        if ([] === $result->bands) {
            $io->writeln('The response is already within the tolerance, no bands needed.');
        } else {
            $io->table(['Band', 'Frequency', 'Gain', 'Q'], array_map(
                static fn (int $n, FittedBand $b): array => [$n + 1, \sprintf('%.1f Hz', $b->hz()), \sprintf('%+.1f dB', $b->db()), \sprintf('%.2f', $b->qValue())],
                array_keys($result->bands), $result->bands,
            ));
        }
        $io->writeln(\sprintf(
            'Deviation from the target: %.1f dB RMS, %.1f dB peak before, %.1f dB RMS, %.1f dB peak predicted after.',
            FitResult::rms($result->before), FitResult::peak($result->before), FitResult::rms($result->after), FitResult::peak($result->after),
        ));
    }

    /**
     * Fitted bands from band 1 up, the remaining owned bands flat and bypassed.
     *
     * @param array<mixed> $state
     * @param list<int>    $outputs
     *
     * @return list<string>
     */
    private function bandFrames(array $state, array $outputs, int $bands, FitResult $result): array
    {
        $frames = [];
        foreach ($outputs as $channel) {
            foreach (array_slice(self::bands($state, $channel), 0, $bands) as $n => $band) {
                $fitted = $result->bands[$n] ?? null;
                $frames[] = self::hex(null === $fitted
                    ? Commands::peq($channel, $n, 120, $band['freq'], $band['q'], $band['type'], true)
                    : Commands::peq($channel, $n, $fitted->gain, $fitted->freq, $fitted->q, 0, false));
            }
        }

        return $frames;
    }

    /**
     * @param list<int> $outputs
     */
    private function revert(BridgeClient $bridge, array $outputs, string $path, SymfonyStyle $io): int
    {
        try {
            $data = json_decode((string) @file_get_contents($path), true, 64, \JSON_THROW_ON_ERROR);
            if (!\is_array($data) || Device::FILE_FORMAT !== ($data['format'] ?? null) || !\is_array($data['device'] ?? null)) {
                throw new \InvalidArgumentException(\sprintf('"%s" is not a trackdsp backup.', $path));
            }
            $bridge->request('write', ['frames' => self::restoreFrames($data['device'], $outputs, 7)]);
        } catch (\JsonException|\InvalidArgumentException|BridgeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $io->success(\sprintf('PEQ of %s restored from %s.', implode(', ', array_map(Channel::label(...), $outputs)), $path));

        return Command::SUCCESS;
    }

    /**
     * @param array<mixed> $state
     * @param list<int>    $outputs
     *
     * @return list<string>
     */
    private static function restoreFrames(array $state, array $outputs, int $bands): array
    {
        $frames = [];
        foreach ($outputs as $channel) {
            foreach (array_slice(self::bands($state, $channel), 0, $bands) as $n => $band) {
                $frames[] = self::hex(Commands::peq($channel, $n, $band['gain'], $band['freq'], $band['q'], $band['type'], $band['bypass']));
            }
        }

        return $frames;
    }

    /**
     * The PEQ bands of an output from a device state.
     *
     * @param array<mixed> $state
     *
     * @return list<array{gain: int, freq: int, q: int, type: int, bypass: bool}>
     */
    private static function bands(array $state, int $channel): array
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
     * @return list<int>
     */
    private static function outputs(InputInterface $input): array
    {
        $outputs = [];
        $argument = $input->getArgument('outputs');
        foreach (explode(',', \is_string($argument) ? $argument : '') as $name) {
            $channel = Channel::parse($name);
            if (!Channel::isOutput($channel)) {
                throw new \InvalidArgumentException(\sprintf('The auto EQ corrects outputs only, got %s.', Channel::label($channel)));
            }
            $outputs[] = $channel;
        }

        return array_values(array_unique($outputs));
    }

    private static function hex(Frame $frame): string
    {
        return str_replace(' ', '', $frame->shortHex());
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
