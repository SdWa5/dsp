<?php

namespace App\Tests\Command;

use App\AutoEq\FittedBand;
use App\Bridge\BridgeClient;
use App\Measurement\Playback;
use App\Protocol\Commands;
use App\Protocol\Scale;
use App\Tests\Support\BridgeProcess;
use App\Tests\Support\Signals;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * trackdsp:autoeq against a bridge subprocess on the simulated device, with a synthetic recording of pink
 * noise through a +6 dB peak at 1 kHz.
 */
final class AutoEqCommandTest extends KernelTestCase
{
    private static ?string $wav = null;
    private ?BridgeProcess $bridge = null;
    private string $directory;

    public static function setUpBeforeClass(): void
    {
        $peak = new FittedBand(Scale::hzToIndex(1000), Scale::dbToPeqGain(6.0), Scale::qToIndex(2.0));
        self::$wav = sys_get_temp_dir().'/trackdsp-autoeq-'.bin2hex(random_bytes(4)).'.wav';
        file_put_contents(self::$wav, Signals::wav([Signals::peak(Signals::pinkNoise(3 * Scale::SAMPLE_RATE), $peak)], 24));
    }

    public static function tearDownAfterClass(): void
    {
        if (null !== self::$wav) {
            @unlink(self::$wav);
        }
    }

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/trackdsp-autoeq-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->bridge?->stop();
        (new Filesystem())->remove($this->directory);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $input
     */
    private function autoEq(array $input): CommandTester
    {
        $tester = new CommandTester((new Application(self::bootKernel()))->find('trackdsp:autoeq'));
        $tester->execute($input + ['--dir' => $this->directory], ['interactive' => false]);

        return $tester;
    }

    private function client(): BridgeClient
    {
        $this->bridge = new BridgeProcess();

        return new BridgeClient($this->bridge->socket);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function out1Peq(BridgeClient $client): array
    {
        $state = $client->request('state');
        self::assertIsArray($state);
        self::assertIsArray($state['outputs'][0]['peq'] ?? null);

        return array_values(array_filter($state['outputs'][0]['peq'], 'is_array'));
    }

    private static function toneMode(BridgeClient $client): mixed
    {
        $state = $client->request('state');
        self::assertIsArray($state);

        return $state['tone_mode'] ?? null;
    }

    private function backup(): string
    {
        $backups = glob($this->directory.'/backup-*.json') ?: [];
        self::assertCount(1, $backups);

        return $backups[0];
    }

    public function testAppliesTheFittedBands(): void
    {
        $client = $this->client();

        $tester = $this->autoEq(['outputs' => 'out1', '--socket' => $this->bridge?->socket, '--wav' => [self::$wav], '--apply' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Correction for Out1, target flat', $tester->getDisplay());
        self::assertStringContainsString('Written to Out1', $tester->getDisplay());
        $first = self::out1Peq($client)[0];
        self::assertIsInt($first['freq']);
        self::assertIsInt($first['gain']);
        self::assertEqualsWithDelta(1000, Scale::indexToHz($first['freq']), 150);
        self::assertLessThan(Scale::dbToPeqGain(-3.0), $first['gain']);
        self::assertFalse($first['bypass']);
        self::assertCount(1, glob($this->directory.'/result-*.json') ?: []);
        $this->backup();
    }

    public function testWritesNothingWithoutApply(): void
    {
        $client = $this->client();
        $before = self::out1Peq($client);

        $tester = $this->autoEq(['outputs' => 'out1', '--socket' => $this->bridge?->socket, '--wav' => [self::$wav]]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Nothing written.', $tester->getDisplay());
        self::assertSame($before, self::out1Peq($client));
    }

    public function testRevertRestoresTheBackup(): void
    {
        $client = $this->client();
        $before = self::out1Peq($client);
        $this->autoEq(['outputs' => 'out1', '--socket' => $this->bridge?->socket, '--wav' => [self::$wav], '--apply' => true])->assertCommandIsSuccessful();
        self::assertNotSame($before, self::out1Peq($client));

        $tester = $this->autoEq(['outputs' => 'out1', '--socket' => $this->bridge?->socket, '--revert' => $this->backup()]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('PEQ of Out1 restored', $tester->getDisplay());
        self::assertSame($before, self::out1Peq($client));
    }

    public function testRevertRejectsOtherFiles(): void
    {
        $client = $this->client();
        $before = self::out1Peq($client);

        $tester = $this->autoEq(['outputs' => 'out1', '--socket' => $this->bridge?->socket, '--revert' => (string) self::$wav]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame($before, self::out1Peq($client));
    }

    public function testWarnsAboutAGainAtCompressor1To1(): void
    {
        $client = $this->client();
        $client->request('write', ['frames' => [
            Playback::hex(Commands::compressor(8, 0, 0, 10, 10, 180)),
            Playback::hex(Commands::gain(8, Scale::dbToGain(-6.0))),
        ]]);

        $tester = $this->autoEq(['outputs' => 'out1', '--socket' => $this->bridge?->socket, '--device' => 'trackdsp-test-none', '--settle' => '0', '--seconds' => '1']);

        self::assertStringContainsString('compressor at 1:1.0', $tester->getDisplay());
        self::assertStringContainsString('Set Limit, or 1:1.1 from +20 dB, before measuring.', $tester->getDisplay());
    }

    public function testAFailedRecordingRestoresToneAndBands(): void
    {
        $client = $this->client();
        $before = self::out1Peq($client);
        $tone = self::toneMode($client);

        $tester = $this->autoEq(['outputs' => 'out1', '--socket' => $this->bridge?->socket, '--device' => 'trackdsp-test-none', '--settle' => '0', '--seconds' => '1']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Pink noise starts now', $tester->getDisplay());
        self::assertSame($before, self::out1Peq($client));
        self::assertSame($tone ?? 0, self::toneMode($client) ?? 0);
    }

    public function testRejectsInvalidInput(): void
    {
        $tester = $this->autoEq(['outputs' => 'in1']);
        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('outputs only', $tester->getDisplay());

        $tester = $this->autoEq(['outputs' => 'out1', '--bands' => '8']);
        self::assertSame(Command::INVALID, $tester->getStatusCode());

        $tester = $this->autoEq(['outputs' => 'out1', '--target' => 'house']);
        self::assertSame(Command::INVALID, $tester->getStatusCode());
    }

    public function testFailsWithoutBridge(): void
    {
        $tester = $this->autoEq(['outputs' => 'out1', '--socket' => sys_get_temp_dir().'/trackdsp-none.sock', '--wav' => [(string) self::$wav]]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('not running', $tester->getDisplay());
        self::assertSame([], glob($this->directory.'/backup-*.json') ?: []);
    }
}
