<?php

namespace App\Command;

use App\Client\Matrix;
use App\Protocol\Channel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('sdwa5-dsp:meters|trackdsp:meters', 'Show the level meters in dB or their raw bytes')]
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
        $io->table(['Channel', 'Level', 'Limiter'], self::rows($raw));

        return Command::SUCCESS;
    }

    /**
     * One row per meter slot: In1–8, Out1–8, Aux. The Limiter column marks an output whose bit is set in byte 51 of
     * the reply, which the captures show set while the output's limiter works. The device has not confirmed it yet.
     *
     * @return list<array{string, string, string}>
     */
    public static function rows(string $raw): array
    {
        $limiting = Matrix::parseLimiting($raw);
        $rows = [];
        foreach (Matrix::parseMeters($raw) as $slot => $db) {
            $output = $slot >= 8 && $slot < 16;
            $rows[] = [
                16 === $slot ? 'Aux' : Channel::label($slot),
                \sprintf('%6.1f dB', $db),
                $output && 0 !== ($limiting & 1 << ($slot - 8)) ? 'LIMIT' : '',
            ];
        }

        return $rows;
    }
}
