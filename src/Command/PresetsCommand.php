<?php

namespace App\Command;

use App\Client\Matrix;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('trackdsp:presets', 'List the preset names U01..U20 and F00')]
final class PresetsCommand extends DeviceCommand
{
    protected function handle(Matrix $dsp, InputInterface $input, SymfonyStyle $io): int
    {
        $active = $dsp->activePreset();
        $rows = [];
        foreach ($dsp->presetNames() as $slot => $name) {
            $rows[] = [self::label($slot), $name, $slot === $active - 1 ? '*' : ''];
        }
        $io->table(['Slot', 'Name', 'Active'], $rows);

        return Command::SUCCESS;
    }

    /**
     * Name slot to editor label: 0 = U01, 19 = U20, 20 = F00.
     */
    public static function label(int $slot): string
    {
        return 20 === $slot ? 'F00' : \sprintf('U%02d', $slot + 1);
    }
}
