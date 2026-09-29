<?php

namespace App\Command;

use App\Client\Matrix;
use App\Protocol\Channel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('trackdsp:meters', 'Show the level meters in dB or their raw bytes')]
final class MetersCommand extends DeviceCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->addOption('raw', null, InputOption::VALUE_NONE, 'Print the raw reply bytes');
    }

    protected function handle(Matrix $dsp, InputInterface $input, SymfonyStyle $io): int
    {
        $raw = $dsp->metersRaw();
        if ($input->getOption('raw')) {
            $io->writeln(implode(' ', str_split(bin2hex($raw), 2)));

            return Command::SUCCESS;
        }
        $labels = [...array_map(Channel::label(...), range(0, 15)), 'Aux'];
        $rows = array_map(static fn (string $label, float $db): array => [$label, \sprintf('%6.1f dB', $db)], $labels, Matrix::parseMeters($raw));
        $io->table(['Channel', 'Level'], $rows);

        return Command::SUCCESS;
    }
}
