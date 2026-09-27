<?php

namespace App\Command;

use App\Client\Matrix;
use App\Protocol\Channel;
use App\Protocol\Commands;
use App\Protocol\Labels;
use App\Protocol\Scale;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('trackdsp:compressor', 'Set the compressor of outputs or the Aux, or apply the DJ default')]
final class CompressorCommand extends DeviceCommand
{
    /**
     * DJ default: 1:4 from -6 dB, 10 ms attack, 200 ms release. The threshold is on the device's own scale,
     * so check it against the real levels on site.
     */
    public const DJ_DEFAULT = ['ratio' => '4', 'knee' => '0', 'attack' => '10', 'release' => '200', 'threshold' => '-6'];

    protected function configure(): void
    {
        parent::configure();
        $this
            ->addArgument('channels', InputArgument::REQUIRED, 'Comma-separated outputs or aux, e.g. out1,out2')
            ->addOption('dj', null, InputOption::VALUE_NONE, 'Start from the DJ default (1:4, -6 dB, 10 ms, 200 ms)')
            ->addOption('ratio', null, InputOption::VALUE_REQUIRED, 'Ratio as 1:N with N = 1, 1.1, 1.3, 1.5, 1.7, 2, 2.5, 3, 3.5, 4, 5, 6, 8, 10, 20, or "limit"')
            ->addOption('knee', null, InputOption::VALUE_REQUIRED, 'Knee, raw value 0..255')
            ->addOption('attack', null, InputOption::VALUE_REQUIRED, 'Attack in ms (1..65536)')
            ->addOption('release', null, InputOption::VALUE_REQUIRED, 'Release in ms (1..65536)')
            ->addOption('threshold', null, InputOption::VALUE_REQUIRED, 'Threshold in dB (-90..+20)');
    }

    protected function validate(InputInterface $input): ?string
    {
        try {
            self::channels($input);
            self::values($input);
        } catch (\InvalidArgumentException|\OutOfRangeException $e) {
            return $e->getMessage();
        }

        return null;
    }

    protected function handle(Matrix $dsp, InputInterface $input, SymfonyStyle $io): int
    {
        $values = self::values($input);
        foreach (self::channels($input) as $channel) {
            $dsp->write(Commands::compressor($channel, ...$values));
            $io->writeln(\sprintf(
                '%s: %s from %+.1f dB, attack %d ms, release %d ms, knee %d',
                Channel::label($channel), Labels::COMP_RATIOS[$values['ratio']], Scale::thresholdToDb($values['threshold']),
                Scale::rawToMs($values['attack']), Scale::rawToMs($values['release']), $values['knee'],
            ));
        }

        return Command::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private static function channels(InputInterface $input): array
    {
        $channels = [];
        foreach (explode(',', self::string($input, 'channels')) as $name) {
            $channel = Channel::parse($name);
            if (!Channel::isOutput($channel) && Channel::AUX !== $channel) {
                throw new \InvalidArgumentException(\sprintf('%s has no compressor, only the outputs and the Aux have one.', Channel::label($channel)));
            }
            $channels[] = $channel;
        }

        return array_values(array_unique($channels));
    }

    /**
     * @return array{ratio: int, knee: int, attack: int, release: int, threshold: int}
     */
    private static function values(InputInterface $input): array
    {
        $options = [];
        foreach (array_keys(self::DJ_DEFAULT) as $name) {
            $value = $input->getOption($name);
            if (\is_string($value) && '' !== $value) {
                $options[$name] = $value;
            }
        }
        if ($input->getOption('dj')) {
            $options += self::DJ_DEFAULT;
        }
        if ([] !== $missing = array_diff(array_keys(self::DJ_DEFAULT), array_keys($options))) {
            throw new \InvalidArgumentException(\sprintf('Missing --%s. Pass all values or start from --dj.', implode(', --', $missing)));
        }

        return [
            'ratio' => self::ratio($options['ratio'] ?? ''),
            'knee' => self::integer($options['knee'] ?? '', 'knee', 0, 255),
            'attack' => Scale::msToRaw(self::integer($options['attack'] ?? '', 'attack', 1, 0x10000)),
            'release' => Scale::msToRaw(self::integer($options['release'] ?? '', 'release', 1, 0x10000)),
            'threshold' => Scale::dbToThreshold(self::decimal($options['threshold'] ?? '', 'threshold', -90, 20)),
        ];
    }

    private static function ratio(string $value): int
    {
        $value = strtolower(trim($value));
        $label = 'limit' === $value ? 'Limit' : (is_numeric(str_replace('1:', '', $value)) ? \sprintf('1:%.1f', (float) str_replace('1:', '', $value)) : $value);
        $index = array_search($label, Labels::COMP_RATIOS, true);
        if (false === $index) {
            throw new \InvalidArgumentException(\sprintf('Unknown ratio "%s". Use one of %s.', $value, implode(', ', Labels::COMP_RATIOS)));
        }

        return $index;
    }

    private static function integer(string $value, string $name, int $min, int $max): int
    {
        if (!preg_match('/^\d+$/', $value) || (int) $value < $min || (int) $value > $max) {
            throw new \InvalidArgumentException(\sprintf('--%s must be a whole number %d..%d, got "%s".', $name, $min, $max, $value));
        }

        return (int) $value;
    }

    private static function decimal(string $value, string $name, float $min, float $max): float
    {
        if (!is_numeric($value) || (float) $value < $min || (float) $value > $max) {
            throw new \InvalidArgumentException(\sprintf('--%s must be %s..%s, got "%s".', $name, $min, $max, $value));
        }

        return (float) $value;
    }
}
