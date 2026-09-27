<?php

namespace App\Command;

use App\Client\Matrix;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('trackdsp:status', 'Show the mute state of all channels')]
final class StatusCommand extends DeviceCommand
{
    protected function handle(Matrix $dsp, InputInterface $input, SymfonyStyle $io): int
    {
        $state = $dsp->muteState();
        foreach (['in', 'out'] as $group) {
            $cells = [];
            foreach ($state as $channel => $muted) {
                if (preg_match('/^'.$group.'\d$/', $channel)) {
                    $cells[] = $channel.':'.($muted ? 'MUTED' : 'on');
                }
            }
            $io->writeln(implode(' ', $cells));
        }

        return Command::SUCCESS;
    }
}
