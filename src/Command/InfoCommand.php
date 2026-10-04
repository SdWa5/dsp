<?php

namespace App\Command;

use App\Client\Matrix;
use App\Transport\TcpTransport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('sdwa5-dsp:info|trackdsp:info', 'Show the device, its IP address and the active preset')]
final class InfoCommand extends DeviceCommand
{
    protected function handle(Matrix $dsp, InputInterface $input, SymfonyStyle $io): int
    {
        $number = $dsp->activePreset();
        // The device answers the IP query over USB only
        $ip = $dsp->transport() instanceof TcpTransport ? preg_replace('#^tcp://(.*):\d+$#', '$1', $dsp->transport()->describe()) : $dsp->ipAddress();
        $io->definitionList(
            ['Device' => $dsp->info()],
            ['Connection' => $dsp->transport()->describe()],
            ['IP' => $ip],
            ['Preset' => $number > 0 ? \sprintf('U%02d %s', $number, $dsp->presetName($number - 1)) : 'F00'],
        );

        return Command::SUCCESS;
    }
}
