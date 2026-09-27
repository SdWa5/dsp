<?php

namespace App\Tests\Command;

use App\Bridge\BridgeClient;
use App\Tests\Support\BridgeProcess;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * trackdsp:ride against a bridge subprocess on the simulated device, whose meters move between about
 * -54 and -22 dB regardless of the gain.
 */
final class RideCommandTest extends KernelTestCase
{
    private ?BridgeProcess $bridge = null;

    protected function tearDown(): void
    {
        $this->bridge?->stop();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $input
     */
    private function ride(array $input): CommandTester
    {
        $tester = new CommandTester((new Application(self::bootKernel()))->find('trackdsp:ride'));
        $tester->execute($input + ['--socket' => $this->bridge?->socket]);

        return $tester;
    }

    private function in1Gain(BridgeClient $client): int
    {
        $state = $client->request('state');
        self::assertIsArray($state);
        self::assertIsArray($state['inputs'][0] ?? null);
        self::assertIsInt($state['inputs'][0]['gain']);

        return $state['inputs'][0]['gain'];
    }

    public function testStartsARiderThatKeepsRidingInTheBridge(): void
    {
        $this->bridge = new BridgeProcess();
        $client = new BridgeClient($this->bridge->socket);
        $before = $this->in1Gain($client);

        $tester = $this->ride([
            'channel' => 'in1', '--level' => '-80', '--silence' => '-90', '--min' => '-40', '--slew' => '20',
            '--average' => '0.1', '--deadband' => '0',
        ]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Gain rider on In1 towards -80.0 dB', $tester->getDisplay());
        $deadline = microtime(true) + 5;
        while ($this->in1Gain($client) >= $before && microtime(true) < $deadline) {
            usleep(100_000);
        }
        self::assertLessThan($before, $this->in1Gain($client));
    }

    public function testListsAndStopsTheRiders(): void
    {
        $this->bridge = new BridgeProcess();
        $client = new BridgeClient($this->bridge->socket);
        self::assertStringContainsString('No gain rider is running.', $this->ride(['--list' => true])->getDisplay());
        $this->ride(['channel' => 'in2', '--level' => '-24'])->assertCommandIsSuccessful();

        $tester = $this->ride(['--list' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertMatchesRegularExpression('/In2\s+-24\.0 dB\s+-20\.0\.\.\+0\.0 dB\s+0\.5 dB\/s/', $tester->getDisplay());

        $tester = $this->ride(['channel' => 'in2', '--stop' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Gain rider on In2 stopped', $tester->getDisplay());
        $status = $client->request('status');
        self::assertIsArray($status);
        self::assertSame([], $status['riders']);
    }

    public function testRejectsInvalidSettings(): void
    {
        $this->bridge = new BridgeProcess();

        $tester = $this->ride(['channel' => 'out1']);
        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('inputs only', $tester->getDisplay());

        $tester = $this->ride(['channel' => 'in1', '--max' => '3']);
        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('boost', $tester->getDisplay());

        $tester = $this->ride([]);
        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('--list', $tester->getDisplay());
    }

    public function testFailsWithoutBridge(): void
    {
        $tester = $this->ride(['channel' => 'in1', '--socket' => sys_get_temp_dir().'/trackdsp-none.sock']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('not running', $tester->getDisplay());
    }
}
