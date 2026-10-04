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

#[AsCommand('sdwa5-dsp:bridge|trackdsp:bridge', 'Owns the device connection and serves it to the web editor over a unix socket')]
final class BridgeCommand extends Command implements SignalableCommandInterface
{
    private ?Bridge $bridge = null;

    public function __construct(
        private readonly TransportFactory $transports,
        #[Autowire('%env(legacy:SDWA5_DSP_TARGET)%')]
        private readonly string $defaultTarget,
        #[Autowire('%sdwa5_dsp.bridge_socket%')]
        private readonly string $defaultSocket,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('target', 't', InputOption::VALUE_REQUIRED, 'auto, usb, usb:/dev/hidrawN, demo or host[:port]. Without it the bridge starts on the target of the last connect or disconnect, else on $SDWA5_DSP_TARGET')
            ->addOption('socket', 's', InputOption::VALUE_REQUIRED, 'Unix socket path (default: $SDWA5_DSP_BRIDGE_SOCKET or var/bridge.sock)')
            ->addOption('meter-interval', null, InputOption::VALUE_REQUIRED, 'Seconds between meter polls while a client listens', '0.3')
            ->addOption('resync-interval', null, InputOption::VALUE_REQUIRED, 'Seconds between full re-reads of the device', '60')
            ->addOption('riders-file', null, InputOption::VALUE_REQUIRED, 'Where the gain riders are kept across restarts (default: the socket path with .riders.json)')
            ->addOption('target-file', null, InputOption::VALUE_REQUIRED, 'Where the last connect or disconnect is kept across restarts (default: the socket path with .target.json)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $explicitTarget = self::option($input, 'target', '');
        $socket = self::option($input, 'socket', $this->defaultSocket);
        $base = preg_replace('/\.sock$/', '', $socket) ?? $socket;
        $this->bridge = new Bridge(
            $this->transports,
            $socket,
            '' !== $explicitTarget ? $explicitTarget : $this->defaultTarget,
            new ConsoleLogger($output),
            max(0.05, (float) self::option($input, 'meter-interval', '0.3')),
            resyncInterval: max(1.0, (float) self::option($input, 'resync-interval', '60')),
            ridersFile: self::option($input, 'riders-file', $base.'.riders.json'),
            targetFile: self::option($input, 'target-file', $base.'.target.json'),
            restoreTarget: '' === $explicitTarget,
        );
        try {
            $this->bridge->listen();
        } catch (BridgeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $io->writeln(\sprintf('Bridge on <info>%s</info>, target <info>%s</info>. Stop with Ctrl+C.', $socket, $this->bridge->status()['target']));
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
