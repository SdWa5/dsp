<?php

namespace App\Command;

use App\Automation\GainRider;
use App\Automation\RiderSettings;
use App\Bridge\BridgeClient;
use App\Bridge\BridgeException;
use App\Protocol\Channel;
use App\Protocol\Commands;
use App\Protocol\Scale;
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
 * Runs the gain rider on one input through a running bridge: meters and state come from its events,
 * the gain goes back as a write. The bridge keeps owning the device connection.
 */
#[AsCommand('trackdsp:ride', 'Ride the gain of an input towards a target meter level (needs a running bridge)')]
final class RideCommand extends Command implements SignalableCommandInterface
{
    private bool $stopped = false;

    public function __construct(
        #[Autowire('%trackdsp.bridge_socket%')]
        private readonly string $defaultSocket,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('channel', InputArgument::REQUIRED, 'in1..in8')
            ->addOption('level', 'l', InputOption::VALUE_REQUIRED, 'Target meter level in dB (approximated meter scale)', '-18')
            ->addOption('min', null, InputOption::VALUE_REQUIRED, 'Lowest gain in dB', '-20')
            ->addOption('max', null, InputOption::VALUE_REQUIRED, 'Highest gain in dB', '0')
            ->addOption('slew', null, InputOption::VALUE_REQUIRED, 'Fastest gain change in dB per second', '0.5')
            ->addOption('average', null, InputOption::VALUE_REQUIRED, 'Time constant of the level average in seconds', '5')
            ->addOption('silence', null, InputOption::VALUE_REQUIRED, 'Hold the gain below this meter level in dB', '-45')
            ->addOption('deadband', null, InputOption::VALUE_REQUIRED, 'Leave deviations up to this size alone, in dB', '1')
            ->addOption('hold', null, InputOption::VALUE_REQUIRED, 'Pause in seconds after someone else changed the gain', '30')
            ->addOption('allow-boost', null, InputOption::VALUE_NONE, 'Allow a maximum above 0 dB')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only show what the rider would do')
            ->addOption('duration', null, InputOption::VALUE_REQUIRED, 'Stop after this many seconds, 0 = until Ctrl+C', '0')
            ->addOption('socket', 's', InputOption::VALUE_REQUIRED, 'Bridge socket (default: $TRACKDSP_BRIDGE_SOCKET or var/bridge.sock)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $settings = $this->settings($input);
        } catch (\InvalidArgumentException|\OutOfRangeException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }
        $socket = $input->getOption('socket');
        $bridge = new BridgeClient(\is_string($socket) && '' !== $socket ? $socket : $this->defaultSocket);
        $rider = new GainRider($settings);
        $dryRun = (bool) $input->getOption('dry-run');
        $duration = (float) self::number($input, 'duration');
        $channel = $settings->channel;
        $label = Channel::label($channel);

        $io->writeln(\sprintf(
            'Riding <info>%s</info> towards <info>%.1f dB</info>, gain %+.1f..%+.1f dB at %.1f dB/s%s. Stop with Ctrl+C.',
            $label, $settings->targetDb, $settings->minGainDb, $settings->maxGainDb, $settings->slewDbPerSecond, $dryRun ? ' (dry run)' : '',
        ));
        $start = microtime(true);
        try {
            foreach ($bridge->events(1.0) as $event) {
                $now = microtime(true);
                if ($this->stopped || ($duration > 0 && $now - $start >= $duration)) {
                    break;
                }
                if (null === $event) {
                    continue;
                }
                if ('state' === $event['event']) {
                    $gain = self::inputGain($event['data'], $channel);
                    if (null !== $gain && $rider->observeGain($gain, $now)) {
                        $io->writeln(\sprintf('%s %s changed to %+.1f dB by someone else, pausing for %d s.', date('H:i:s'), $label, Scale::gainToDb($gain), $settings->overrideHoldSeconds));
                    }
                } elseif ('meters' === $event['event']) {
                    $level = self::level($event['data'], $channel);
                    if (null === $level || null === $raw = $rider->meter($level, $now)) {
                        continue;
                    }
                    if (!$dryRun) {
                        $bridge->request('write', ['frames' => [str_replace(' ', '', Commands::gain($channel, $raw)->shortHex())]]);
                    }
                    if ($output->isVerbose()) {
                        $io->writeln(\sprintf('%s level %.1f dB, average %.1f dB, gain %+.1f dB', date('H:i:s'), $level, $rider->averageDb() ?? $level, Scale::gainToDb($raw)));
                    }
                }
            }
        } catch (BridgeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        } finally {
            $this->stopped = false;
        }
        $gainDb = $rider->gainDb();
        $io->writeln(null === $gainDb ? 'Stopped before the first state.' : \sprintf('Stopped, %s gain %+.1f dB.', $label, $gainDb));

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
        $this->stopped = true;

        return false;
    }

    private function settings(InputInterface $input): RiderSettings
    {
        $channel = $input->getArgument('channel');

        return new RiderSettings(
            Channel::parse(\is_string($channel) ? $channel : ''),
            (float) self::number($input, 'level'),
            (float) self::number($input, 'min'),
            (float) self::number($input, 'max'),
            (float) self::number($input, 'slew'),
            (float) self::number($input, 'average'),
            (float) self::number($input, 'silence'),
            (float) self::number($input, 'deadband'),
            (float) self::number($input, 'hold'),
            (bool) $input->getOption('allow-boost'),
        );
    }

    private static function number(InputInterface $input, string $option): string
    {
        $value = $input->getOption($option);
        if (!\is_string($value) || !is_numeric($value)) {
            throw new \InvalidArgumentException(\sprintf('--%s must be a number.', $option));
        }

        return $value;
    }

    private static function inputGain(mixed $state, int $channel): ?int
    {
        $gain = \is_array($state) && \is_array($state['inputs'] ?? null) && \is_array($state['inputs'][$channel] ?? null) ? ($state['inputs'][$channel]['gain'] ?? null) : null;

        return \is_int($gain) ? $gain : null;
    }

    private static function level(mixed $meters, int $channel): ?float
    {
        $level = \is_array($meters) && \is_array($meters['levels'] ?? null) ? ($meters['levels'][$channel] ?? null) : null;

        return \is_int($level) || \is_float($level) ? (float) $level : null;
    }
}
