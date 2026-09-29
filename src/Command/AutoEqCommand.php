<?php

namespace App\Command;

use App\AutoEq\AutoEq;
use App\AutoEq\FitResult;
use App\AutoEq\FitSettings;
use App\AutoEq\FittedBand;
use App\AutoEq\PeqFitter;
use App\AutoEq\TargetCurve;
use App\Bridge\BridgeClient;
use App\Bridge\BridgeException;
use App\Measurement\Calibration;
use App\Measurement\Interrupt;
use App\Measurement\RecorderFactory;
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
 * Supervised automatic EQ through a running bridge.
 *
 * Backs up the device, then records the measurement mic at one or more positions. Every recording bypasses
 * the bands the auto EQ owns on the chosen outputs, pauses the gain riders, plays the device's pink noise and
 * restores all of it afterwards, see AutoEq::record(). Then it fits peaking bands to the target and writes
 * them only after confirmation. --wav analyses existing recordings instead of measuring, --revert restores
 * a backup.
 */
#[AsCommand('trackdsp:autoeq', 'Measure with pink noise and a measurement mic and fit the output PEQ to a target (needs a running bridge)')]
final class AutoEqCommand extends Command implements SignalableCommandInterface
{
    private ?Interrupt $interrupt = null;

    public function __construct(
        #[Autowire('%trackdsp.bridge_socket%')]
        private readonly string $defaultSocket,
        #[Autowire('%trackdsp.autoeq_dir%')]
        private readonly string $defaultDirectory,
        private readonly RecorderFactory $recorders = new RecorderFactory(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('outputs', InputArgument::REQUIRED, 'Comma-separated outputs to correct, e.g. out1,out2')
            ->addOption('device', 'd', InputOption::VALUE_REQUIRED, 'ALSA capture device, e.g. plughw:CARD=UMC1820,DEV=0', $this->recorders->defaultDevice())
            ->addOption('mic-channel', 'm', InputOption::VALUE_REQUIRED, 'Input of the interface the mic is on (1-based)', '1')
            ->addOption('seconds', null, InputOption::VALUE_REQUIRED, 'Recording length per position', '8')
            ->addOption('positions', 'p', InputOption::VALUE_REQUIRED, 'Mic positions to average', '1')
            ->addOption('settle', null, InputOption::VALUE_REQUIRED, 'Seconds of noise before each recording', '2')
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
            $this->interrupt = new Interrupt();
            $autoEq = new AutoEq(new BridgeClient($socket), self::optional($input, 'dir') ?? $this->defaultDirectory, $this->recorders, $this->interrupt);
            if (null !== $revert = self::optional($input, 'revert')) {
                return $this->revert($autoEq, $outputs, $revert, $io);
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

        $stamp = date('Ymd-His');
        try {
            $state = $autoEq->state();
            $backup = $autoEq->backup($state, $outputs, $stamp);
            $io->writeln(\sprintf('Backup of the device in <info>%s</info>.', $backup));
        } catch (BridgeException|\RuntimeException|\JsonException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        try {
            if ([] === $wavs) {
                $wavs = $this->measure($autoEq, $state, $outputs, $settings->bands, $micChannel, $stamp, $input, $io);
            }
            $io->writeln(\sprintf('Analysing %d recording%s…', \count($wavs), 1 === \count($wavs) ? '' : 's'));
            $measured = AutoEq::average(array_map(static fn (string $wav) => AutoEq::spectrum($wav, $micChannel), $wavs), $calibration);
            $result = (new PeqFitter())->fit($measured, $target, $settings);
            $this->report($result, $target, $outputs, $io);
            file_put_contents($autoEq->path('result-'.$stamp.'.json'), json_encode([
                'outputs' => array_map(Channel::label(...), $outputs),
                'target' => $target->describe(),
                'bands' => array_map(static fn (FittedBand $b): array => $b->toArray(), $result->bands),
                'before' => ['freqs' => $result->before->freqs, 'db' => $result->before->db],
                'after' => ['freqs' => $result->after->freqs, 'db' => $result->after->db],
                'measured' => ['freqs' => $measured->freqs, 'db' => $measured->db],
            ], \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));

            $labels = implode(', ', array_map(Channel::label(...), $outputs));
            if ([] !== $result->bands && ($input->getOption('apply') || ($input->isInteractive() && $io->confirm(\sprintf('Write these bands to %s?', $labels), false)))) {
                $autoEq->apply($state, $outputs, $settings->bands, $result->bands);
                $io->success(\sprintf('Written to %s. Measure again to verify. Undo with --revert=%s', $labels, $backup));
            } else {
                $io->writeln('Nothing written.');
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
     * Lets AutoEq::record() stop before the recording and put tone and bands back, which also ends the
     * remaining positions. arecord gets the signal too.
     */
    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->interrupt?->trigger();

        return false;
    }

    /**
     * @param array<mixed> $state
     * @param list<int>    $outputs
     *
     * @return list<string> recorded files
     */
    private function measure(AutoEq $autoEq, array $state, array $outputs, int $bands, int $micChannel, string $stamp, InputInterface $input, SymfonyStyle $io): array
    {
        $seconds = self::integer($input, 'seconds');
        $settle = self::number($input, 'settle');
        $positions = max(1, self::integer($input, 'positions'));
        $device = (string) self::optional($input, 'device');
        $io->warning('Pink noise starts now on every routed output. Keep the level safe.');
        $files = [];
        for ($p = 1; $p <= $positions; ++$p) {
            if ($positions > 1 && $input->isInteractive()) {
                $io->ask(\sprintf('Put the mic at position %d of %d and press Enter', $p, $positions), '');
            }
            $io->writeln(\sprintf('Recording position %d for %d s…', $p, $seconds));
            $files[] = $autoEq->record($state, $outputs, $bands, $device, $micChannel, $seconds, $settle, $autoEq->path(\sprintf('measure-%s-%d.wav', $stamp, $p)));
        }

        return $files;
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
     * @param list<int> $outputs
     */
    private function revert(AutoEq $autoEq, array $outputs, string $path, SymfonyStyle $io): int
    {
        try {
            $autoEq->revert($path, $outputs);
        } catch (\JsonException|\InvalidArgumentException|BridgeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $io->success(\sprintf('PEQ of %s restored from %s.', implode(', ', array_map(Channel::label(...), $outputs)), $path));

        return Command::SUCCESS;
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
