<?php

namespace App\Command;

use App\Discovery\Discovery;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('sdwa5-dsp:discover|trackdsp:discover', 'Find devices on USB and on the local networks')]
final class DiscoverCommand extends Command
{
    public function __construct(
        private readonly Discovery $discovery,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('networks', null, InputOption::VALUE_REQUIRED, 'Comma-separated CIDRs such as 192.168.68.0/22 (default: $SDWA5_DSP_DISCOVERY_NETWORKS, else the local interfaces)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $networks = $input->getOption('networks');
        $discovery = \is_string($networks) && '' !== $networks ? $this->discovery->withNetworks($networks) : $this->discovery;
        try {
            $subnets = $discovery->subnets();
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }
        $io->comment(\sprintf('Searching USB and %s.', implode(', ', array_map(strval(...), $subnets)) ?: 'no network'));
        $found = $discovery->scan();
        if ([] === $found) {
            $io->warning('No DSP found.');

            return Command::FAILURE;
        }
        $io->table(['Target', 'Connection', 'Device'], array_map(static fn (array $d): array => [$d['target'], strtoupper($d['kind']).($d['busy'] ? ' (busy)' : ''), $d['info']], $found));

        return Command::SUCCESS;
    }
}
