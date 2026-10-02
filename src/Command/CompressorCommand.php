<?php

namespace App\Command;

use App\Client\Matrix;
use App\Model\Device;
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

/**
 * Values that are not given keep what the compressor of each channel holds on the device. The byte called knee has no
 * option, because the vendor editor has no control for it and its meaning is unmeasured. It keeps the device's value.
 */
#[AsCommand('trackdsp:compressor', 'Set the compressor of outputs or the Aux')]
final class CompressorCommand extends DeviceCommand
{
    private const VALUES = ['ratio', 'attack', 'release', 'threshold'];

    protected function configure(): void
    {
        parent::configure();
        $this
            ->addArgument('channels', InputArgument::REQUIRED, 'Comma-separated outputs or aux, e.g. out1,out2')
            ->addOption('ratio', null, InputOption::VALUE_REQUIRED, 'Ratio as 1:N with N = 1, 1.1, 1.3, 1.5, 1.7, 2, 2.5, 3, 3.5, 4, 5, 6, 8, 10, 20, or "limit"')
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
        $given = self::values($input);
        $device = Device::parse($dsp->config());
        foreach (self::channels($input) as $channel) {
            $current = Channel::AUX === $channel ? $device->aux->comp : $device->outputs[$channel - Channel::output(1)]->comp;
            $values = $given + ['ratio' => $current->ratio, 'attack' => $current->attack, 'release' => $current->release, 'threshold' => $current->threshold];
            $dsp->write(Commands::compressor($channel, $values['ratio'], $current->knee, $values['attack'], $values['release'], $values['threshold']));
            $io->writeln(\sprintf(
                '%s: %s from %+.1f dB, attack %d ms, release %d ms',
                Channel::label($channel), Labels::COMP_RATIOS[$values['ratio']], Scale::thresholdToDb($values['threshold']),
                Scale::rawToMs($values['attack']), Scale::rawToMs($values['release']),
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
     * Raw device values of the options given, at least one.
     *
     * @return array{ratio?: int, attack?: int, release?: int, threshold?: int}
     */
    private static function values(InputInterface $input): array
    {
        $values = [];
        foreach (self::VALUES as $name) {
            $value = $input->getOption($name);
            if (!\is_string($value) || '' === $value) {
                continue;
            }
            $values[$name] = match ($name) {
                'ratio' => self::ratio($value),
                'attack', 'release' => Scale::msToRaw(self::integer($value, $name, 1, 0x10000)),
                'threshold' => Scale::dbToThreshold(self::decimal($value, 'threshold', -90, 20)),
            };
        }
        if ([] === $values) {
            throw new \InvalidArgumentException('Give at least one of --ratio, --attack, --release, --threshold.');
        }

        return $values;
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
