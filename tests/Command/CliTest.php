<?php

namespace App\Tests\Command;

use App\Command\MetersCommand;
use App\Command\RouteCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The CLI against the simulated device (SDWA5_DSP_TARGET=demo in .env.test).
 */
final class CliTest extends KernelTestCase
{
    /**
     * @param array<string, mixed> $input
     */
    private function runCommand(string $name, array $input = []): CommandTester
    {
        $tester = new CommandTester((new Application(self::bootKernel()))->find($name));
        $tester->execute($input);

        return $tester;
    }

    public function testInfo(): void
    {
        $tester = $this->runCommand('sdwa5-dsp:info');

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('8x8Matrix V010', $tester->getDisplay());
        self::assertStringContainsString('U02 Bulgaria26', $tester->getDisplay());
        self::assertStringContainsString('192.168.68.10', $tester->getDisplay());
    }

    public function testPresets(): void
    {
        $display = $this->runCommand('sdwa5-dsp:presets')->getDisplay();

        self::assertMatchesRegularExpression('/U02\s+Bulgaria26\s+yes\s+\*/', $display);
        self::assertMatchesRegularExpression('/U03\s+empty/', $display);
        self::assertMatchesRegularExpression('/F00\s+\S*\s*factory/', $display);
    }

    public function testStatus(): void
    {
        $display = $this->runCommand('sdwa5-dsp:status')->getDisplay();

        self::assertStringContainsString('in1:MUTED in2:MUTED in3:on in4:on in5:MUTED(gain)', $display);
        self::assertStringContainsString('out8:MUTED', $display);
    }

    public function testWrites(): void
    {
        $tester = $this->runCommand('sdwa5-dsp:gain', ['channel' => 'in1', 'db' => '-6']);
        $tester->assertCommandIsSuccessful();
        self::assertStringNotContainsString('mutes', $tester->getDisplay());
        $this->runCommand('sdwa5-dsp:mute', ['channel' => 'out1'])->assertCommandIsSuccessful();
        $this->runCommand('sdwa5-dsp:unmute', ['channel' => 'in1'])->assertCommandIsSuccessful();
        $this->runCommand('sdwa5-dsp:phase', ['channel' => 'out3', 'mode' => 'invert'])->assertCommandIsSuccessful();
        $this->runCommand('sdwa5-dsp:route', ['output' => 'out3', 'inputs' => 'in1,2'])->assertCommandIsSuccessful();
    }

    public function testTheLowestGainWarnsThatItMutes(): void
    {
        $tester = $this->runCommand('sdwa5-dsp:gain', ['channel' => 'in1', 'db' => '-60']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('-60.0 dB is gain raw 0, which mutes the channel.', $tester->getDisplay());
        self::assertStringNotContainsString('mutes', $this->runCommand('sdwa5-dsp:gain', ['channel' => 'in1', 'db' => '-59.5'])->getDisplay());
    }

    public function testBoostNeedsFlag(): void
    {
        $tester = $this->runCommand('sdwa5-dsp:gain', ['channel' => 'in1', 'db' => '3']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('--allow-boost', $tester->getDisplay());
        $this->runCommand('sdwa5-dsp:gain', ['channel' => 'in1', 'db' => '3', '--allow-boost' => true])->assertCommandIsSuccessful();
    }

    /**
     * @return iterable<string, array{string, array<string, string>}>
     */
    public static function invalidInput(): iterable
    {
        yield 'unknown channel' => ['sdwa5-dsp:mute', ['channel' => 'out9']];
        yield 'gain not a number' => ['sdwa5-dsp:gain', ['channel' => 'in1', 'db' => 'loud']];
        yield 'gain out of range' => ['sdwa5-dsp:gain', ['channel' => 'in1', 'db' => '-70']];
        yield 'phase mode' => ['sdwa5-dsp:phase', ['channel' => 'in1', 'mode' => 'flip']];
        yield 'route output' => ['sdwa5-dsp:route', ['output' => 'out9', 'inputs' => '1']];
        yield 'route inputs' => ['sdwa5-dsp:route', ['output' => '3', 'inputs' => '1,9']];
    }

    /**
     * @param array<string, string> $input
     */
    #[DataProvider('invalidInput')]
    public function testInvalidInputFails(string $name, array $input): void
    {
        $tester = $this->runCommand($name, $input);

        self::assertNotSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('[ERROR]', $tester->getDisplay());
    }

    public function testUnreachableTarget(): void
    {
        $tester = $this->runCommand('sdwa5-dsp:info', ['--target' => '127.0.0.1:1']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Cannot connect', $tester->getDisplay());
    }

    public function testDumpToFileAndHex(): void
    {
        $file = sys_get_temp_dir().'/sdwa5-dsp-dump-'.bin2hex(random_bytes(4)).'.bin';
        try {
            $this->runCommand('sdwa5-dsp:dump', ['file' => $file])->assertCommandIsSuccessful();
            self::assertSame(1277, filesize($file));
        } finally {
            @unlink($file);
        }

        $lines = explode("\n", trim($this->runCommand('sdwa5-dsp:dump')->getDisplay()));
        self::assertCount(26, $lines);
        self::assertStringStartsWith('00  ff ff 42 75', $lines[0]);
    }

    public function testMeters(): void
    {
        $display = $this->runCommand('sdwa5-dsp:meters')->getDisplay();
        self::assertStringContainsString('Aux', $display);
        self::assertStringContainsString('Limiter', $display);
        self::assertMatchesRegularExpression('/^([0-9a-f]{2} ){54}[0-9a-f]{2}$/', trim($this->runCommand('sdwa5-dsp:meters', ['--raw' => true])->getDisplay()));
    }

    public function testMetersMarkTheOutputsWhoseLimiterWorks(): void
    {
        // A reply of a01_gain_scale.pcap, Out1 and Out2 at Limit from 0 dB with the Out1 gain at +12 dB
        $rows = MetersCommand::rows((string) hex2bin('233d50233d50ce3c16cf3c6ead05b1ad05bbad05c6ad05bdbe3d32a33d93ee3b95ad05c2ad05aaad05aaad05c5ad05aaad05b003ffff00'));

        self::assertCount(17, $rows);
        self::assertSame(['Out1', 'LIMIT'], [$rows[8][0], $rows[8][2]]);
        self::assertSame(['Out2', 'LIMIT'], [$rows[9][0], $rows[9][2]]);
        self::assertSame(['Aux', ''], [$rows[16][0], $rows[16][2]]);
        self::assertSame(['LIMIT', 'LIMIT'], array_values(array_filter(array_column($rows, 2))));
    }

    public function testRouteInputParser(): void
    {
        self::assertSame([], RouteCommand::inputs('none'));
        self::assertSame([1, 2, 8], RouteCommand::inputs('in1, 2,IN8'));
        self::assertNull(RouteCommand::inputs('1,x'));
    }
}
