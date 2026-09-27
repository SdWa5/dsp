<?php

namespace App\Command;

use App\Bridge\Bridge;
use App\Bridge\BridgeException;
use App\Transport\TransportFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand('trackdsp:bridge', 'Owns the device connection and serves it to the web editor over a unix socket')]
final class BridgeCommand extends Command implements SignalableCommandInterface
{
    private ?Bridge $bridge = null;

    public function __construct(
        private readonly TransportFactory $transports,
        #[Autowire('%env(TRACKDSP_TARGET)%')]
        private readonly string $defaultTarget,
        #[Autowire('%trackdsp.bridge_socket%')]
        private readonly string $defaultSocket,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('target', 't', InputOption::VALUE_REQUIRED, 'auto, usb, usb:/dev/hidrawN, demo or host[:port] (default: $TRACKDSP_TARGET)')
            ->addOption('socket', 's', InputOption::VALUE_REQUIRED, 'Unix socket path (default: $TRACKDSP_BRIDGE_SOCKET or var/bridge.sock)')
            ->addOption('meter-interval', null, InputOption::VALUE_REQUIRED, 'Seconds between meter polls while a client listens', '0.3')
            ->addOption('resync-interval', null, InputOption::VALUE_REQUIRED, 'Seconds between full re-reads of the device', '60');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $target = self::option($input, 'target', $this->defaultTarget);
        $socket = self::option($input, 'socket', $this->defaultSocket);
        $this->bridge = new Bridge(
            $this->transports,
            $socket,
            $target,
            new ConsoleLogger($output),
            max(0.05, (float) self::option($input, 'meter-interval', '0.3')),
            resyncInterval: max(1.0, (float) self::option($input, 'resync-interval', '60')),
        );
        try {
            $this->bridge->listen();
        } catch (BridgeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $io->writeln(\sprintf('Bridge on <info>%s</info>, target <info>%s</info>. Stop with Ctrl+C.', $socket, $target));
        $this->bridge->run();

        return Command::SUCCESS;
    }

    /**
     * @return list<int>
     */
    public function getSubscribedSignals(): array
    {
        return \defined('SIGINT') ? [\SIGINT, \SIGTERM] : [];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->bridge?->stop();

        return false;
    }

    private static function option(InputInterface $input, string $name, string $default): string
    {
        $value = $input->getOption($name);

        return \is_string($value) && '' !== $value ? $value : $default;
    }
}
