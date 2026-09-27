<?php

namespace App\Command;

use App\Automation\RiderSettings;
use App\Bridge\BridgeClient;
use App\Bridge\BridgeException;
use App\Protocol\Channel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Starts, changes, stops and lists the gain riders of a running bridge. The riders run inside the bridge,
 * so they keep working after this command and without a browser, and they come back after a restart.
 */
#[AsCommand('trackdsp:ride', 'Start, stop or list the gain riders of the bridge, which keep inputs at a target meter level')]
final class RideCommand extends Command
{
    public function __construct(
        #[Autowire('%trackdsp.bridge_socket%')]
        private readonly string $defaultSocket,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('channel', InputArgument::OPTIONAL, 'in1..in8')
            ->addOption('level', 'l', InputOption::VALUE_REQUIRED, 'Target meter level in dB (approximated meter scale)', '-18')
            ->addOption('min', null, InputOption::VALUE_REQUIRED, 'Lowest gain in dB', '-20')
            ->addOption('max', null, InputOption::VALUE_REQUIRED, 'Highest gain in dB', '0')
            ->addOption('slew', null, InputOption::VALUE_REQUIRED, 'Fastest gain change in dB per second', '0.5')
            ->addOption('average', null, InputOption::VALUE_REQUIRED, 'Time constant of the level average in seconds', '5')
            ->addOption('silence', null, InputOption::VALUE_REQUIRED, 'Hold the gain below this meter level in dB', '-45')
            ->addOption('deadband', null, InputOption::VALUE_REQUIRED, 'Leave deviations up to this size alone, in dB', '1')
            ->addOption('hold', null, InputOption::VALUE_REQUIRED, 'Pause in seconds after someone else changed the gain', '30')
            ->addOption('allow-boost', null, InputOption::VALUE_NONE, 'Allow a maximum above 0 dB')
            ->addOption('stop', null, InputOption::VALUE_NONE, 'Stop the rider of the channel')
            ->addOption('list', null, InputOption::VALUE_NONE, 'List the running riders with what they do right now')
            ->addOption('socket', 's', InputOption::VALUE_REQUIRED, 'Bridge socket (default: $TRACKDSP_BRIDGE_SOCKET or var/bridge.sock)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $socket = $input->getOption('socket');
        $bridge = new BridgeClient(\is_string($socket) && '' !== $socket ? $socket : $this->defaultSocket);
        try {
            if ($input->getOption('list')) {
                return $this->list($bridge, $io);
            }
            $settings = $this->settings($input);
        } catch (\InvalidArgumentException|\OutOfRangeException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        } catch (BridgeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $label = Channel::label($settings->channel);
        try {
            if ($input->getOption('stop')) {
                $bridge->request('unride', ['channel' => $settings->channel]);
                $io->writeln(\sprintf('Gain rider on <info>%s</info> stopped. The gain stays where it is.', $label));

                return Command::SUCCESS;
            }
            $bridge->request('ride', ['channel' => $settings->channel, 'settings' => $settings->toArray()]);
        } catch (BridgeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $io->writeln(\sprintf(
            'Gain rider on <info>%s</info> towards <info>%.1f dB</info>, gain %+.1f..%+.1f dB at %.1f dB/s. It runs in the bridge, stop it with --stop.',
            $label, $settings->targetDb, $settings->minGainDb, $settings->maxGainDb, $settings->slewDbPerSecond,
        ));

        return Command::SUCCESS;
    }

    /**
     * The riders from the bridge status, with the live values of the first meters event if one arrives soon.
     */
    private function list(BridgeClient $bridge, SymfonyStyle $io): int
    {
        $status = $bridge->request('status');
        $riders = \is_array($status) && \is_array($status['riders'] ?? null) ? $status['riders'] : [];
        if ([] === $riders) {
            $io->writeln('No gain rider is running.');

            return Command::SUCCESS;
        }
        $live = [];
        $deadline = microtime(true) + 2.0;
        foreach ($bridge->events(0.5) as $event) {
            if (null !== $event && 'meters' === $event['event'] && \is_array($event['data']) && \is_array($event['data']['riders'] ?? null)) {
                foreach ($event['data']['riders'] as $rider) {
                    if (\is_array($rider) && \is_int($rider['channel'] ?? null)) {
                        $live[$rider['channel']] = $rider;
                    }
                }
                break;
            }
            if (microtime(true) >= $deadline) {
                break;
            }
        }
        $rows = [];
        foreach ($riders as $rider) {
            if (!\is_array($rider) || !\is_int($rider['channel'] ?? null)) {
                continue;
            }
            $now = $live[$rider['channel']] ?? [];
            $rows[] = [
                Channel::label($rider['channel']),
                \sprintf('%.1f dB', self::float($rider['target'] ?? null)),
                \sprintf('%+.1f..%+.1f dB', self::float($rider['min'] ?? null), self::float($rider['max'] ?? null)),
                \sprintf('%.1f dB/s', self::float($rider['slew'] ?? null)),
                is_numeric($now['gain'] ?? null) ? \sprintf('%+.1f dB', $now['gain']) : '–',
                is_numeric($now['average'] ?? null) ? \sprintf('%.1f dB', $now['average']) : '–',
                \is_string($now['status'] ?? null) ? $now['status'] : '–',
            ];
        }
        $io->table(['Input', 'Target', 'Window', 'Slew', 'Gain', 'Level', 'Status'], $rows);

        return Command::SUCCESS;
    }

    private function settings(InputInterface $input): RiderSettings
    {
        $channel = $input->getArgument('channel');
        if (!\is_string($channel) || '' === $channel) {
            throw new \InvalidArgumentException('Name the input, e.g. in1, or use --list.');
        }

        return new RiderSettings(
            Channel::parse($channel),
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

    private static function float(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
