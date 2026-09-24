<?php

namespace App\Command;

use App\Client\Matrix;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('trackdsp:mute', 'Mute a channel')]
class MuteCommand extends DeviceCommand
{
    protected const MUTE = true;

    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('channel', InputArgument::REQUIRED, 'in1..in8, out1..out8 or aux');
    }

    protected function handle(Matrix $dsp, InputInterface $input, SymfonyStyle $io): int
    {
        $dsp->mute(self::string($input, 'channel'), static::MUTE);

        return Command::SUCCESS;
    }
}
