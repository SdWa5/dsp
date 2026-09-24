<?php

namespace App\Command;

use App\Client\Matrix;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('trackdsp:phase', 'Set the phase of a channel')]
final class PhaseCommand extends DeviceCommand
{
    private const MODES = ['normal', 'invert'];

    protected function configure(): void
    {
        parent::configure();
        $this
            ->addArgument('channel', InputArgument::REQUIRED, 'in1..in8 or out1..out8')
            ->addArgument('mode', InputArgument::REQUIRED, 'normal or invert', null, self::MODES);
    }

    protected function validate(InputInterface $input): ?string
    {
        return \in_array(self::string($input, 'mode'), self::MODES, true) ? null : 'The mode must be normal or invert.';
    }

    protected function handle(Matrix $dsp, InputInterface $input, SymfonyStyle $io): int
    {
        $dsp->phase(self::string($input, 'channel'), 'invert' === self::string($input, 'mode'));

        return Command::SUCCESS;
    }
}
