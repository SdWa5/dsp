<?php

namespace App\Command;

use App\Client\Matrix;
use App\Protocol\Scale;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('trackdsp:gain', 'Set the gain of a channel in dB (-60..+12)')]
final class GainCommand extends DeviceCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addArgument('channel', InputArgument::REQUIRED, 'in1..in8, out1..out8 or aux')
            ->addArgument('db', InputArgument::REQUIRED, 'Gain in dB')
            ->addOption('allow-boost', null, InputOption::VALUE_NONE, 'Allow values above 0 dB');
    }

    protected function validate(InputInterface $input): ?string
    {
        $db = self::string($input, 'db');
        if (!is_numeric($db)) {
            return \sprintf('"%s" is not a number.', $db);
        }
        if ((float) $db > 0 && !$input->getOption('allow-boost')) {
            return \sprintf('%+.1f dB is a boost. Pass --allow-boost to be sure.', (float) $db);
        }

        return null;
    }

    protected function handle(Matrix $dsp, InputInterface $input, SymfonyStyle $io): int
    {
        $db = (float) self::string($input, 'db');
        $dsp->setGain(self::string($input, 'channel'), $db);
        if (Scale::GAIN_MUTED === Scale::dbToGain($db)) {
            $io->warning(\sprintf('%.1f dB is gain raw 0, which mutes the channel. The lowest gain that passes signal is -59.5 dB.', $db));
        }

        return Command::SUCCESS;
    }
}
