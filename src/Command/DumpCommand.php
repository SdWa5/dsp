<?php

namespace App\Command;

use App\Client\Matrix;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('trackdsp:dump', 'Read the configuration (26 pages) as hex or into a binary file')]
final class DumpCommand extends DeviceCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('file', InputArgument::OPTIONAL, 'Write the raw 1277 bytes to this file');
    }

    protected function handle(Matrix $dsp, InputInterface $input, SymfonyStyle $io): int
    {
        $config = $dsp->config();
        $file = self::string($input, 'file');
        if ('' !== $file) {
            if (false === @file_put_contents($file, $config)) {
                $io->error(\sprintf('Cannot write %s.', $file));

                return Command::FAILURE;
            }
            $io->writeln(\sprintf('%d bytes -> %s', \strlen($config), $file));

            return Command::SUCCESS;
        }
        foreach (str_split($config, Matrix::CONFIG_PAGE_SIZE) as $page => $bytes) {
            $io->writeln(\sprintf('%02X  %s', $page, implode(' ', str_split(bin2hex($bytes), 2))));
        }

        return Command::SUCCESS;
    }
}
