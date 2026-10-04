<?php

namespace App\Command;

use App\Bridge\BridgeClient;
use App\Bridge\BridgeException;
use App\Filter\Chain;
use App\Measurement\FilterCase;
use App\Measurement\Interrupt;
use App\Measurement\Jit;
use App\Measurement\Playback;
use App\Measurement\RecorderFactory;
use App\Measurement\ShapeMatch;
use App\Measurement\Solo;
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
 * Walks filter settings on one output and measures each over its loopback against a reference loopback, then
 * compares what every setting changed with the filter model of App\Filter.
 *
 * Both outputs play pink noise alone for the whole run, the other outputs muted (see Solo). The first recording
 * takes the output as set, and every setting after it is divided by that base (see ShapeMatch). That leaves the
 * setting's own magnitude and phase. One Playback run holds all recordings, so the tone stays on, and its
 * restore puts back every value a setting changed. The JSON in --dir keeps the points of every setting, from
 * which tests/fixtures/filter-shapes.json is taken.
 */
#[AsCommand('sdwa5-dsp:filter-shapes', 'Measure PEQ types, HPF and LPF slopes, delay and polarity of an output over a loopback against the filter model (needs a running bridge)')]
final class FilterShapesCommand extends Command implements SignalableCommandInterface
{
    private const MIN_SECONDS = 2;

    /** Seconds at the start of a recording that the analysis leaves out, as in sdwa5-dsp:measure */
    private const SKIP = 0.1;

    /** Two loopbacks of the same tone read about 1.0, so a base below this has no common signal */
    private const MIN_COHERENCE = 0.9;

    private ?Interrupt $interrupt = null;

    public function __construct(
        #[Autowire('%sdwa5_dsp.bridge_socket%')]
        private readonly string $defaultSocket,
        #[Autowire('%sdwa5_dsp.measure_dir%')]
        private readonly string $defaultDirectory,
        private readonly RecorderFactory $recorders = new RecorderFactory(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('output', InputArgument::REQUIRED, 'Output whose filters are walked, e.g. out7')
            ->addOption('input', 'i', InputOption::VALUE_REQUIRED, 'Input of the interface that records the output\'s loopback (1-based)')
            ->addOption('reference-output', null, InputOption::VALUE_REQUIRED, 'Output that plays unchanged for the reference loopback', 'out8')
            ->addOption('reference', 'r', InputOption::VALUE_REQUIRED, 'Input of the interface that records the reference loopback (1-based)')
            ->addOption('cases', null, InputOption::VALUE_REQUIRED, 'all, groups (timing, peq, hpf, lpf) or case ids such as peak-1k,hpf-lk24', 'all')
            ->addOption('device', 'd', InputOption::VALUE_REQUIRED, 'ALSA capture device, e.g. plughw:CARD=UMC1820,DEV=0', $this->recorders->defaultDevice())
            ->addOption('seconds', null, InputOption::VALUE_REQUIRED, 'Recording length per setting in whole seconds', '3')
            ->addOption('settle', null, InputOption::VALUE_REQUIRED, 'Seconds of tone before each recording', '1')
            ->addOption('save', null, InputOption::VALUE_REQUIRED, 'Base name of the JSON in --dir (default: filter-shapes-<time>)')
            ->addOption('socket', 's', InputOption::VALUE_REQUIRED, 'Bridge socket (default: $SDWA5_DSP_BRIDGE_SOCKET or var/bridge.sock)')
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Directory for the results (default: var/measure)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Jit::enable();
        $io = new SymfonyStyle($input, $output);
        $this->interrupt = new Interrupt();
        try {
            $channel = Channel::parse((string) $input->getArgument('output'));
            $referenceOutput = Channel::parse((string) $input->getOption('reference-output'));
            if (!Channel::isOutput($channel) || !Channel::isOutput($referenceOutput)) {
                throw new \InvalidArgumentException('The output and the reference output must be outputs.');
            }
            if ($channel === $referenceOutput) {
                throw new \InvalidArgumentException(\sprintf('%s cannot be its own reference output.', Channel::label($channel)));
            }
            $recorded = self::input($input, 'input');
            $reference = self::input($input, 'reference');
            if ($recorded === $reference) {
                throw new \InvalidArgumentException('--input and --reference must be different inputs.');
            }
            $cases = FilterCase::select((string) $input->getOption('cases'));
            $seconds = self::number($input, 'seconds');
            if ($seconds < self::MIN_SECONDS || floor($seconds) !== $seconds) {
                throw new \InvalidArgumentException(\sprintf('--seconds must be a whole number of %d or more, the transfer function needs 1.6 s.', self::MIN_SECONDS));
            }
            $settle = self::number($input, 'settle');
            if ($settle < 0) {
                throw new \InvalidArgumentException('--settle must not be negative.');
            }
            $name = (string) ($input->getOption('save') ?? 'filter-shapes-'.date('Ymd-His'));
            $name = preg_replace('/\.json$/i', '', $name) ?? $name;
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $name)) {
                throw new \InvalidArgumentException('--save takes a file name without a directory, of letters, digits, dot, dash and underscore.');
            }
        } catch (\InvalidArgumentException|\OutOfRangeException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        $device = (string) $input->getOption('device');
        $wav = null;
        try {
            $json = self::path((string) ($input->getOption('dir') ?? $this->defaultDirectory), $name.'.json');
            $bridge = new BridgeClient((string) ($input->getOption('socket') ?? $this->defaultSocket));
            $state = $bridge->request('state');
            if (!\is_array($state)) {
                throw new BridgeException('The bridge sent no device state.', 409);
            }
            $set = self::output($state, $channel);
            $solo = Solo::plan($state, [$channel], 0.0, $referenceOutput);
            if ([] !== $solo->warnings) {
                $io->warning($solo->warnings);
            }
            $io->writeln(\sprintf('Pink noise on %s, the other outputs muted. %d setting%s of %s, recorded on input %d against input %d, %d s each.', implode(', ', array_map(Channel::label(...), $solo->played)), \count($cases), 1 === \count($cases) ? '' : 's', Channel::label($channel), $recorded, $reference, $seconds));
            $wav = (string) tempnam(sys_get_temp_dir(), 'sdwa5-dsp-shapes-');
            $transfers = $this->walk($bridge, $solo, $set, $channel, $cases, $device, max($recorded, $reference), $recorded, $reference, (int) $seconds, $settle, $wav, $io);
            $results = self::report($set, $cases, $transfers, $io);
            file_put_contents($json, json_encode([
                'time' => date(\DATE_ATOM),
                'device' => $device,
                'output' => Channel::label($channel),
                'input' => $recorded,
                'reference_output' => Channel::label($referenceOutput),
                'reference' => $reference,
                'seconds' => (int) $seconds,
                'settle' => $settle,
                'state' => $set,
                'base' => $transfers[FilterCase::BASE]->toArray(),
                'cases' => $results,
            ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));
            $io->writeln(\sprintf('Saved %s.', $json));
        } catch (BridgeException|\RuntimeException|\InvalidArgumentException|\LengthException|\JsonException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        } finally {
            if (null !== $wav) {
                @unlink($wav);
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

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->interrupt?->trigger();

        return false;
    }

    /**
     * Plays once and records every case, and returns the transfer function of each by case id.
     *
     * @param array<string, mixed>       $set
     * @param non-empty-list<FilterCase> $cases
     *
     * @return array<string, Transfer>
     */
    private function walk(BridgeClient $bridge, Solo $solo, array $set, int $channel, array $cases, string $device, int $channels, int $recorded, int $reference, int $seconds, float $settle, string $wav, SymfonyStyle $io): array
    {
        $recorder = $this->recorders->create($device, $channels);
        $function = new TransferFunction();
        $playback = new Playback($bridge, interrupt: $this->interrupt);
        $restore = [...FilterCase::restore($set, $channel, $cases), ...$solo->restore];

        return $playback->run($solo->setup, TestTone::parse('pink', $solo->original->freq), $solo->original, $restore, $settle, static function () use ($playback, $recorder, $function, $set, $channel, $cases, $recorded, $reference, $seconds, $settle, $wav, $io): array {
            $transfers = [];
            $previous = null;
            foreach ($cases as $i => $case) {
                if (null !== $previous) {
                    $playback->apply($case->transition($set, $channel, $previous), $settle);
                }
                $io->writeln(\sprintf('%2d/%d %s', $i + 1, \count($cases), $case->label));
                $recorder->record($seconds, $wav);
                $transfers[$case->id] = self::transfer($function, $wav, $recorded, $reference);
                if (null === $previous && ($coherence = $transfers[$case->id]->meanCoherence()) < self::MIN_COHERENCE) {
                    throw new \RuntimeException(\sprintf('As set, input %d is coherent with input %d at γ² %.2f only, so the two loopbacks carry no common signal. %sCheck that both outputs play, see the warnings above, and check both cables, the PAD and the gain knobs.', $recorded, $reference, $coherence, implode('', array_map(static fn (string $w): string => $w.' ', $transfers[$case->id]->warnings))));
                }
                $previous = $case;
            }

            return $transfers;
        });
    }

    private static function transfer(TransferFunction $function, string $wav, int $recorded, int $reference): Transfer
    {
        $measured = WavReader::read($wav, $recorded);
        $loopback = WavReader::read($wav, $reference);
        $skip = (int) round($measured['rate'] * self::SKIP);

        return $function->analyse(\array_slice($loopback['samples'], $skip), \array_slice($measured['samples'], $skip), $measured['rate']);
    }

    /**
     * Prints the base and a table of every setting against its model, and returns what the JSON keeps.
     *
     * @param array<string, mixed>    $set
     * @param list<FilterCase>        $cases
     * @param array<string, Transfer> $transfers
     *
     * @return list<array<string, mixed>>
     */
    private static function report(array $set, array $cases, array $transfers, SymfonyStyle $io): array
    {
        $base = $transfers[FilterCase::BASE];
        $range = $base->coherentRange();
        $io->writeln(\sprintf(
            'As set, the output reads %+.2f dB and %+.1f° at 1 kHz against the reference, %s, polarity %s, coherent %s.',
            $base->at(1000)['db'], $base->at(1000)['phase'],
            null === $base->delay ? 'without a clear peak' : \sprintf('%.2f samples later', $base->delay),
            $base->polarity->value,
            null === $range ? 'nowhere' : $range['from'].' to '.$range['to'],
        ));
        $model = Chain::output($set);
        $rows = $results = $differ = [];
        foreach ($cases as $case) {
            if (FilterCase::BASE === $case->id) {
                continue;
            }
            $transfer = $transfers[$case->id];
            $match = ShapeMatch::measure($base, $transfer);
            $fit = $match->against(Chain::output($case->apply($set)), $model);
            if (!$fit['fits']) {
                $differ[] = $case->id;
            }
            $rows[] = [
                $case->id,
                $case->label,
                null === $match->delay ? '-' : \sprintf('%+.2f', $match->delay),
                $transfer->polarity->value,
                null === $fit['db'] ? '-' : \sprintf('%.2f', $fit['db']),
                null === $fit['phase'] ? '-' : \sprintf('%.1f', $fit['phase']),
                null === $fit['from'] ? '-' : \sprintf('%s to %s', self::hz($fit['from']), self::hz((float) $fit['to'])),
                $fit['fits'] ? 'yes' : 'no',
            ];
            $results[] = ['id' => $case->id, 'group' => $case->group, 'label' => $case->label, 'changes' => $case->changes, 'polarity' => $transfer->polarity->value, 'fit' => $fit] + $match->toArray();
        }
        if ([] === $rows) {
            return [];
        }
        $io->table(['Case', 'Setting', 'Delay', 'Polarity', 'Max ΔdB', 'Max Δ°', 'Compared', 'Fits'], $rows);
        $fits = \count($rows) - \count($differ);
        $summary = \sprintf('%d of %d settings fit the model within %.1f dB and %.0f°, where the model stays above %d dB.', $fits, \count($rows), ShapeMatch::MAX_DB, ShapeMatch::MAX_DEGREES, ShapeMatch::FLOOR_DB);
        if ([] === $differ) {
            $io->success($summary);
        } else {
            $io->warning($summary.' Differing: '.implode(', ', $differ).'.');
        }

        return $results;
    }

    private static function input(InputInterface $input, string $name): int
    {
        $value = $input->getOption($name);
        if (!\is_string($value) || !preg_match('/^[1-9]\d*$/', $value)) {
            throw new \InvalidArgumentException(\sprintf('--%s must name an input of the interface, 1 or more.', $name));
        }

        return (int) $value;
    }

    private static function number(InputInterface $input, string $name): float
    {
        $value = $input->getOption($name);
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException(\sprintf('--%s must be a number.', $name));
        }

        return (float) $value;
    }

    /**
     * The output of the device state, as the bridge sends it.
     *
     * @param array<mixed> $state
     *
     * @return array<string, mixed>
     */
    private static function output(array $state, int $channel): array
    {
        $output = \is_array($state['outputs'] ?? null) ? ($state['outputs'][$channel - Channel::output(1)] ?? null) : null;
        if (!\is_array($output)) {
            throw new BridgeException(\sprintf('The device state has no %s.', Channel::label($channel)), 409);
        }
        $named = [];
        foreach ($output as $key => $value) {
            $named[(string) $key] = $value;
        }

        return $named;
    }

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

    private static function hz(float $hz): string
    {
        return $hz >= 1000 ? \sprintf('%.3gk', $hz / 1000) : \sprintf('%.0f', $hz);
    }
}
