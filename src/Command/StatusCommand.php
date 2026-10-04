<?php

namespace App\Command;

use App\Client\Matrix;
use App\Model\Device;
use App\Protocol\Scale;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reads the whole configuration rather than the mute page alone, since a gain at raw 0 mutes a channel too.
 */
#[AsCommand('sdwa5-dsp:status|trackdsp:status', 'Show the mute state of all channels')]
final class StatusCommand extends DeviceCommand
{
    protected function handle(Matrix $dsp, InputInterface $input, SymfonyStyle $io): int
    {
        $device = Device::parse($dsp->config());
        foreach (['in' => $device->inputs, 'out' => $device->outputs] as $group => $channels) {
            $cells = [];
            foreach ($channels as $i => $channel) {
                $cells[] = $group.($i + 1).':'.match (true) {
                    $channel->mute => 'MUTED',
                    Scale::GAIN_MUTED === $channel->gain => 'MUTED(gain)',
                    default => 'on',
                };
            }
            $io->writeln(implode(' ', $cells));
        }

        return Command::SUCCESS;
    }
}
