<?php

namespace App\Command;

use App\Client\Matrix;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('trackdsp:route', 'Route inputs to an output, replacing its current routing')]
final class RouteCommand extends DeviceCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addArgument('output', InputArgument::REQUIRED, 'out1..out8 or 1..8')
            ->addArgument('inputs', InputArgument::REQUIRED, 'e.g. 1,2 or in1,in2, "none" for no input');
    }

    protected function validate(InputInterface $input): ?string
    {
        if (!preg_match('/^(out)?[1-8]$/i', self::string($input, 'output'))) {
            return 'The output must be out1..out8 or 1..8.';
        }
        if (null === self::inputs(self::string($input, 'inputs'))) {
            return 'The inputs must be a list like 1,2 or in1,in2, or "none".';
        }

        return null;
    }

    protected function handle(Matrix $dsp, InputInterface $input, SymfonyStyle $io): int
    {
        $dsp->route((int) preg_replace('/^out/i', '', self::string($input, 'output')), self::inputs(self::string($input, 'inputs')) ?? []);

        return Command::SUCCESS;
    }

    /**
     * @return list<int>|null input numbers, null when the list is malformed
     */
    public static function inputs(string $spec): ?array
    {
        $spec = strtolower(trim($spec));
        if (\in_array($spec, ['none', '-', ''], true)) {
            return [];
        }
        $inputs = [];
        foreach (explode(',', $spec) as $item) {
            if (!preg_match('/^(in)?([1-8])$/', trim($item), $m)) {
                return null;
            }
            $inputs[] = (int) $m[2];
        }

        return $inputs;
    }
}
