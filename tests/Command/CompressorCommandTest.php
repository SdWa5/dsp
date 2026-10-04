<?php

namespace App\Tests\Command;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * sdwa5-dsp:compressor against the simulated device (SDWA5_DSP_TARGET=demo in .env.test).
 */
final class CompressorCommandTest extends KernelTestCase
{
    /**
     * @param array<string, mixed> $input
     */
    private function runCommand(array $input): CommandTester
    {
        $tester = new CommandTester((new Application(self::bootKernel()))->find('sdwa5-dsp:compressor'));
        $tester->execute($input);

        return $tester;
    }

    public function testAllValuesOnSeveralOutputs(): void
    {
        $tester = $this->runCommand(['channels' => 'out1,out2', '--ratio' => '4', '--attack' => '10', '--release' => '200', '--threshold' => '-6']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Out1: 1:4.0 from -6.0 dB, attack 10 ms, release 200 ms', $tester->getDisplay());
        self::assertStringContainsString('Out2: 1:4.0 from -6.0 dB', $tester->getDisplay());
    }

    public function testMissingValuesKeepTheDeviceValues(): void
    {
        // The demo holds Out8 at 1:1 from +20 dB, 50 ms attack, 200 ms release
        $tester = $this->runCommand(['channels' => 'out8,aux', '--threshold' => '-3']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Out8: 1:1.0 from -3.0 dB, attack 50 ms, release 200 ms', $tester->getDisplay());
        self::assertStringContainsString('Aux: ', $tester->getDisplay());
        self::assertStringContainsString('from -3.0 dB', explode('Aux: ', $tester->getDisplay())[1]);
    }

    public function testRatioLimit(): void
    {
        $tester = $this->runCommand(['channels' => 'aux', '--ratio' => 'limit']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Aux: Limit from', $tester->getDisplay());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidInput(): iterable
    {
        yield 'input has no compressor' => [['channels' => 'in1', '--ratio' => '4'], 'only the outputs'];
        yield 'no value' => [['channels' => 'out1'], 'at least one'];
        yield 'unknown ratio' => [['channels' => 'out1', '--ratio' => '7'], 'Unknown ratio'];
        yield 'threshold out of range' => [['channels' => 'out1', '--threshold' => '30'], '--threshold'];
        yield 'attack not a number' => [['channels' => 'out1', '--attack' => 'fast'], '--attack'];
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
