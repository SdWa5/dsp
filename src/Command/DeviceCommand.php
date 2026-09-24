<?php

namespace App\Command;

use App\Client\DspException;
use App\Client\Matrix;
use App\Transport\TransportException;
use App\Transport\TransportFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Base for commands that talk to the device. Adds -t/--target and turns device errors into a message.
 */
abstract class DeviceCommand extends Command
{
    public function __construct(
        protected readonly TransportFactory $transports,
        #[Autowire('%env(TRACKDSP_TARGET)%')]
        private readonly string $defaultTarget = 'usb',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('target', 't', InputOption::VALUE_REQUIRED, 'usb, usb:/dev/hidrawN, demo or host[:port] (default: $TRACKDSP_TARGET)');
    }

    /**
     * Validates the arguments before any connection is made. Returns an error message or null.
     */
    protected function validate(InputInterface $input): ?string
    {
        return null;
    }

    abstract protected function handle(Matrix $dsp, InputInterface $input, SymfonyStyle $io): int;

    protected function target(InputInterface $input): string
    {
        $target = $input->getOption('target');

        return \is_string($target) && '' !== $target ? $target : $this->defaultTarget;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            if (null !== $error = $this->validate($input)) {
                $io->error($error);

                return Command::INVALID;
            }
            $dsp = $this->transports->connect($this->target($input));
            try {
                return $this->handle($dsp, $input, $io);
            } finally {
                $dsp->close();
            }
        } catch (DspException|TransportException|\InvalidArgumentException|\OutOfRangeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }

    protected static function string(InputInterface $input, string $argument): string
    {
        $value = $input->getArgument($argument);

        return \is_string($value) ? $value : '';
    }
}
