<?php

namespace App\Tests\Command;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * trackdsp:compressor against the simulated device (TRACKDSP_TARGET=demo in .env.test).
 */
final class CompressorCommandTest extends KernelTestCase
{
    /**
     * @param array<string, mixed> $input
     */
    private function runCommand(array $input): CommandTester
    {
        $tester = new CommandTester((new Application(self::bootKernel()))->find('trackdsp:compressor'));
        $tester->execute($input);

        return $tester;
    }

    public function testDjDefaultOnSeveralOutputs(): void
    {
        $tester = $this->runCommand(['channels' => 'out1,out2', '--dj' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Out1: 1:4.0 from -6.0 dB, attack 10 ms, release 200 ms, knee 0', $tester->getDisplay());
        self::assertStringContainsString('Out2: 1:4.0 from -6.0 dB', $tester->getDisplay());
    }

    public function testOptionsOverrideTheDjDefault(): void
    {
        $tester = $this->runCommand(['channels' => 'aux', '--dj' => true, '--ratio' => 'limit', '--threshold' => '-3']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Aux: Limit from -3.0 dB, attack 10 ms', $tester->getDisplay());
    }

    public function testAllValuesWithoutDefault(): void
    {
        $tester = $this->runCommand(['channels' => 'out8', '--ratio' => '1:2', '--knee' => '2', '--attack' => '5', '--release' => '500', '--threshold' => '0']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Out8: 1:2.0 from +0.0 dB, attack 5 ms, release 500 ms, knee 2', $tester->getDisplay());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidInput(): iterable
    {
        yield 'input has no compressor' => [['channels' => 'in1', '--dj' => true], 'only the outputs'];
        yield 'missing values' => [['channels' => 'out1', '--ratio' => '4'], 'Missing'];
        yield 'unknown ratio' => [['channels' => 'out1', '--dj' => true, '--ratio' => '7'], 'Unknown ratio'];
        yield 'threshold out of range' => [['channels' => 'out1', '--dj' => true, '--threshold' => '30'], '--threshold'];
        yield 'attack not a number' => [['channels' => 'out1', '--dj' => true, '--attack' => 'fast'], '--attack'];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('invalidInput')]
    public function testInvalidInput(array $input, string $message): void
    {
        $tester = $this->runCommand($input);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString($message, $tester->getDisplay());
    }
}
