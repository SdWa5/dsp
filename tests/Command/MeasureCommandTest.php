<?php

namespace App\Tests\Command;

use App\Bridge\BridgeClient;
use App\Measurement\Playback;
use App\Measurement\TestTone;
use App\Protocol\Commands;
use App\Protocol\Scale;
use App\Tests\Support\BridgeProcess;
use App\Tests\Support\FakeRecorderFactory;
use App\Tests\Support\Signals;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * sdwa5-dsp:measure against a bridge subprocess on the simulated device, with the fake recorder: the last
 * recorded channel holds pink noise through a +6 dB peak at 1 kHz, every channel before it the plain noise
 * 6 dB lower per channel.
 */
final class MeasureCommandTest extends KernelTestCase
{
    private ?BridgeProcess $bridge = null;
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/sdwa5-dsp-measure-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        FakeRecorderFactory::$probe = null;
        $this->bridge?->stop();
        (new Filesystem())->remove($this->directory);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $input
     */
    private function measure(array $input): CommandTester
    {
        $tester = new CommandTester((new Application(self::bootKernel()))->find('sdwa5-dsp:measure'));
        $tester->execute($input + ['--dir' => $this->directory, '--settle' => '0', '--seconds' => '2'], ['interactive' => false]);

        return $tester;
    }

    private function client(): BridgeClient
    {
        $this->bridge = new BridgeProcess();

        return new BridgeClient($this->bridge->socket);
    }

    /**
     * @return array<mixed>
     */
    private static function state(BridgeClient $client): array
    {
        $state = $client->request('state');
        self::assertIsArray($state);

        return $state;
    }

    /**
     * @param array<mixed> $state
     *
     * @return array<mixed>
     */
    private static function out(array $state, int $number): array
    {
        self::assertIsArray($state['outputs'][$number - 1] ?? null);

        return $state['outputs'][$number - 1];
    }

    /**
     * The display on one line. SymfonyStyle wraps notes and warnings and starts each of their lines with " ! ".
     */
    private static function text(CommandTester $tester): string
    {
        return trim(preg_replace(['/^ ! /m', '/\s+/'], ['', ' '], $tester->getDisplay()) ?? '');
    }

    /**
     * @return array<mixed>
     */
    private function json(string $name): array
    {
        $json = json_decode((string) file_get_contents($this->directory.'/'.$name.'.json'), true, 16, \JSON_THROW_ON_ERROR);
        self::assertIsArray($json);

        return $json;
    }

    /**
     * @param list<list<float>> $channels
     */
    private function wav(array $channels): string
    {
        (new Filesystem())->mkdir($this->directory);
        $path = $this->directory.'/input.wav';
        file_put_contents($path, Signals::wav($channels, 24));

        return $path;
    }

    public function testPlaysOneOutputAloneAndPutsTheDeviceBack(): void
    {
        $client = $this->client();
        $before = self::state($client);
        $during = null;
        FakeRecorderFactory::$probe = static function () use ($client, &$during): void {
            $during = self::state($client);
        };

        $tester = $this->measure(['outputs' => 'out3', '-c' => '1,2,6', '--trim' => '-20', '--socket' => $this->bridge?->socket, '--save' => 'out3']);

        $tester->assertCommandIsSuccessful();
        self::assertIsArray($during);
        self::assertSame(TestTone::PINK, $during['tone_mode']);
        // Out3 at +5 dB plays 20 dB lower at compressor 1:1.1, the muted reference Out8 plays, the rest is muted
        self::assertSame(Scale::dbToGain(-15.0), self::out($during, 3)['gain']);
        self::assertSame([1, 220], [self::out($during, 3)['comp']['ratio'] ?? null, self::out($during, 3)['comp']['threshold'] ?? null]);
        self::assertFalse(self::out($during, 3)['mute']);
        self::assertFalse(self::out($during, 8)['mute']);
        foreach ([1, 2, 4, 5, 6, 7] as $number) {
            self::assertTrue(self::out($during, $number)['mute'], 'Out'.$number);
        }
        self::assertSame($before, self::state($client));

        $text = self::text($tester);
        self::assertStringContainsString('Pink Noise on Out3, Out8 (reference), the other outputs muted.', $text);
        self::assertStringContainsString('Out8 was muted and plays for the recording.', $text);
        self::assertStringContainsString('The recording runs the compressor of Out3 at 1:1.1 from +20 dB.', $text);
        self::assertStringContainsString('The test tone was already on (White Noise). It goes back on after the recording.', $text);
        self::assertStringContainsString('Out3 +5.0 dB -15.0 dB -20.0 dB', $text);
        self::assertStringContainsString('Third-octave bands in dBFS: ', $text);
        self::assertStringContainsString('Band In1 In2 In6', $text);

        $json = $this->json('out3');
        self::assertSame($this->directory.'/out3.wav', $json['wav']);
        self::assertFileExists($this->directory.'/out3.wav');
        self::assertSame('Pink Noise', $json['signal']);
        self::assertSame(['Out3', 'Out8'], $json['played']);
        self::assertSame('Out8', $json['reference']);
        self::assertTrue($json['solo']);
        self::assertEquals(['Out3' => ['from' => 5.0, 'to' => -15.0]], $json['gains']);
        self::assertNull($json['relative']);
        self::assertIsArray($json['channels']);
        self::assertSame(['1', '2', '6'], array_map('strval', array_keys($json['channels'])));
        self::assertIsArray($json['channels'][6]['bands'] ?? null);
        self::assertCount(31, $json['channels'][6]['bands']);
        self::assertSame(['rms', 'peak', 'crest', 'clipped', 'samples'], array_keys((array) ($json['channels'][6]['levels'] ?? null)));

        // A name is never taken twice, and the refusal comes before the device is touched
        FakeRecorderFactory::$probe = static fn () => self::fail('Recorded over an existing file.');
        $tester = $this->measure(['outputs' => 'out3', '--socket' => $this->bridge?->socket, '--save' => 'out3.wav']);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('out3.wav exists already. Choose another name with --save.', self::text($tester));
    }

    public function testAFailedRecordingPutsTheDeviceBack(): void
    {
        $client = $this->client();
        $before = self::state($client);

        $tester = $this->measure(['outputs' => 'out7', '--trim' => '-10', '--socket' => $this->bridge?->socket, '--device' => FakeRecorderFactory::FAILING]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('arecord failed', self::text($tester));
        self::assertSame($before, self::state($client));
        self::assertSame([], glob($this->directory.'/*.json') ?: []);
    }

    public function testShowsTheBandsRelativeToOneChannel(): void
    {
        $this->client();

        $tester = $this->measure(['outputs' => 'out7', '-c' => '5,6', '--relative' => '6', '--socket' => $this->bridge?->socket, '--save' => 'relative']);

        $tester->assertCommandIsSuccessful();
        $text = self::text($tester);
        self::assertStringContainsString('Input RMS Peak Crest Clipped RMS vs In6', $text);
        self::assertStringContainsString('Third-octave bands relative to input 6 in dB: ', $text);
        self::assertStringContainsString('Band In5 ', $text);
        self::assertStringContainsString('Pink Noise on Out7, Out8 (reference), the other outputs muted.', $text);

        // In5 is the noise 6 dB lower, In6 carries the peak at 1 kHz on top
        $json = $this->json('relative');
        self::assertSame(6, $json['relative']);
        self::assertSame([], $json['gains']);
        $band = static function (array $json, int $channel, int $index): float {
            self::assertIsArray($json['channels'][$channel]['bands'][$index] ?? null);
            self::assertIsFloat($json['channels'][$channel]['bands'][$index]['db']);

            return $json['channels'][$channel]['bands'][$index]['db'];
        };
        self::assertEqualsWithDelta(FakeRecorderFactory::STEP_DB, $band($json, 5, 7) - $band($json, 6, 7), 0.3);
        self::assertEqualsWithDelta(FakeRecorderFactory::STEP_DB - 5.5, $band($json, 5, 17) - $band($json, 6, 17), 1.0);
    }

    public function testTheTransferFunctionAgainstTheReferenceInput(): void
    {
        $this->client();

        $tester = $this->measure(['outputs' => 'out7', '-c' => '4,5,6', '--reference' => '5', '--socket' => $this->bridge?->socket, '--save' => 'transfer']);

        $tester->assertCommandIsSuccessful();
        $text = self::text($tester);
        self::assertStringContainsString('Transfer function against input 5: ', $text);
        self::assertStringContainsString('4 48.00 samples, 1.000 ms normal 20Hz to 20kHz 1.00', $text);
        self::assertStringContainsString('Band In4 dB Phase γ² In6 dB Phase γ²', $text);

        // In4 is the reference noise 6 dB lower and 48 samples later, In6 the same noise 48 samples earlier through the peak
        $json = $this->json('transfer');
        self::assertSame(5, $json['reference_input']);
        self::assertIsArray($json['channels']);
        self::assertNull($json['channels'][5]['transfer']);
        $in4 = $json['channels'][4]['transfer'];
        $in6 = $json['channels'][6]['transfer'];
        self::assertIsArray($in4);
        self::assertIsArray($in6);
        self::assertEqualsWithDelta(FakeRecorderFactory::LAG, $in4['delay'], 0.1);
        self::assertSame('normal', $in4['polarity']);
        self::assertIsArray($in4['bands']);
        self::assertIsArray($in4['bands'][17]);
        self::assertEqualsWithDelta(FakeRecorderFactory::STEP_DB, $in4['bands'][17]['db'], 0.2);
        self::assertEqualsWithDelta(0.0, $in4['bands'][17]['phase'], 2.0);
        self::assertEqualsWithDelta(-FakeRecorderFactory::LAG, $in6['delay'], 0.5);
        self::assertIsArray($in6['bands']);
        self::assertIsArray($in6['bands'][17]);
        self::assertEqualsWithDelta(-FakeRecorderFactory::STEP_DB + 5.5, $in6['bands'][17]['db'], 1.0);
    }

    public function testAnalysesARecordingWithoutTheBridge(): void
    {
        $wav = $this->wav([Signals::sine(1000.0, 2 * Scale::SAMPLE_RATE, 0.5), array_fill(0, 2 * Scale::SAMPLE_RATE, 0.0)]);

        $tester = $this->measure(['-c' => '1,2', '--wav' => $wav, '--socket' => sys_get_temp_dir().'/sdwa5-dsp-none.sock']);

        $tester->assertCommandIsSuccessful();
        $text = self::text($tester);
        self::assertStringContainsString('1 -9.0 -6.0 3.0 dB 0', $text);
        self::assertStringContainsString('2 silent silent 0.0 dB 0', $text);
        self::assertStringContainsString('1kHz -9.0 silent', $text);
        self::assertStringContainsString('Input 2 delivers digital silence.', $text);
        self::assertSame([$wav], glob($this->directory.'/*') ?: [], 'No JSON without --save');

        $tester = $this->measure(['-c' => '1,2', '--wav' => $wav, '--reference' => '2']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('No transfer function against input 2. The reference is silent.', self::text($tester));

        $tester = $this->measure(['-c' => '1', '--wav' => $wav, '--save' => 'sine']);

        $tester->assertCommandIsSuccessful();
        $json = $this->json('sine');
        self::assertSame($wav, $json['wav']);
        self::assertArrayNotHasKey('played', $json);
        self::assertIsArray($json['channels']);
        self::assertSame([1], array_keys($json['channels']));
    }

    public function testLeavesTheClickAtTheStartOfARecordingOut(): void
    {
        $click = array_fill(0, 240, 1.0);
        $sine = Signals::sine(1000.0, 2 * Scale::SAMPLE_RATE, 0.5);
        $wav = $this->wav([array_merge($click, \array_slice($sine, 240)), array_merge($click, array_fill(0, 2 * Scale::SAMPLE_RATE - 240, 0.0))]);

        $tester = $this->measure(['-c' => '1,2', '--wav' => $wav]);

        $tester->assertCommandIsSuccessful();
        $text = self::text($tester);
        self::assertStringContainsString('1 -9.0 -6.0 3.0 dB 0', $text);
        self::assertStringContainsString('2 silent silent 0.0 dB 0', $text);
        self::assertStringNotContainsString('clipped', $text);
    }

    public function testTheCalibrationCorrectsTheBands(): void
    {
        $wav = $this->wav([Signals::sine(1000.0, 2 * Scale::SAMPLE_RATE, 0.5), Signals::sine(1000.0, 2 * Scale::SAMPLE_RATE, 0.5)]);
        $cal = $this->directory.'/mic.txt';
        file_put_contents($cal, "Sens Factor =-1.2dB\n20 0\n1000 3.0\n20000 0\n");

        $tester = $this->measure(['-c' => '1,2', '--wav' => $wav, '--cal' => ['2:'.$cal], '--save' => 'cal']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('1kHz -9.0 -12.0', self::text($tester));
        $json = $this->json('cal');
        self::assertIsArray($json['channels']);
        self::assertNull($json['channels'][1]['calibration'] ?? null);
        self::assertSame($cal, $json['channels'][2]['calibration'] ?? null);
        // The levels stay as recorded
        self::assertSame($json['channels'][1]['levels'] ?? null, $json['channels'][2]['levels'] ?? null);
    }

    public function testALinkedReferenceOutputLeavesTheDeviceAlone(): void
    {
        $client = $this->client();
        $before = self::state($client);
        FakeRecorderFactory::$probe = static fn () => self::fail('Recorded with a linked reference.');

        $tester = $this->measure(['outputs' => 'out3', '--reference-output' => 'out1', '--socket' => $this->bridge?->socket]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('The reference output Out1 is linked (Out1, Out2).', self::text($tester));
        self::assertSame($before, self::state($client));
    }

    public function testRestoreReplaysWhatACrashedMeasurementLeft(): void
    {
        $client = $this->client();
        $before = self::state($client);
        $original = TestTone::fromState($before);
        // The crash left the tone on and Out3 muted
        $client->request('write', ['frames' => [Playback::hex(Commands::mute(3, true)), Playback::hex((new TestTone(TestTone::PINK))->frame())]]);
        file_put_contents(Playback::restoreFile((string) $this->bridge?->socket), json_encode([
            'time' => '2026-09-29T20:00:00+00:00',
            'frames' => [Playback::hex($original->frame()), Playback::hex(Commands::mute(3, false))],
            'riders' => [['channel' => 1, 'target' => -24]],
        ], \JSON_THROW_ON_ERROR));

        $tester = $this->measure(['--restore' => true, '--socket' => $this->bridge?->socket]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Put back 2 frames from the measurement of 2026-09-29T20:00:00+00:00 and started the riders on In2 again.', self::text($tester));
        self::assertSame($before, self::state($client));
        $status = $client->request('status');
        self::assertIsArray($status);
        self::assertIsArray($status['riders']);
        // The demo links In1 and In2, so the rider on In2 comes back for both
        self::assertSame([0, 1], array_column($status['riders'], 'channel'));
        self::assertFileDoesNotExist(Playback::restoreFile((string) $this->bridge?->socket));

        $tester = $this->measure(['--restore' => true, '--socket' => $this->bridge?->socket]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Nothing to restore.', self::text($tester));
    }

    public function testFailsWithoutBridge(): void
    {
        $tester = $this->measure(['outputs' => 'out3', '--socket' => sys_get_temp_dir().'/sdwa5-dsp-none.sock']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('not running', self::text($tester));
    }

    public function testRejectsInvalidInput(): void
    {
        $cases = [
            'Only outputs play the test tone, got In1.' => ['outputs' => 'in1'],
            'Unknown channel "out9"' => ['outputs' => 'out9'],
            'A positive --trim needs --allow-boost.' => ['--trim' => '6'],
            '--trim must be -60..+12 dB.' => ['--trim' => '-70'],
            '--trim must be a number.' => ['--trim' => 'loud'],
            '--reference-output must be an output or none, got In1.' => ['--reference-output' => 'in1'],
            'Unknown signal "brown"' => ['--signal' => 'brown'],
            'The test tone has no sine near 25000 Hz' => ['--signal' => 'sine:25k'],
            '--seconds must be 2 or more' => ['--seconds' => '1'],
            '--seconds must be a whole number.' => ['--seconds' => '2.5'],
            '--settle must not be negative.' => ['--settle' => '-1'],
            '--channels takes inputs of the interface from 1, got "0".' => ['-c' => '1,0'],
            '--relative=3 is not among the recorded channels 1,2.' => ['-c' => '1,2', '--relative' => '3'],
            '--reference=3 must be one of at least two recorded channels, got 1,2.' => ['-c' => '1,2', '--reference' => '3'],
            '--reference=1 must be one of at least two recorded channels, got 1.' => ['--reference' => '1'],
            '--cal names channel 3, which is not recorded.' => ['--cal' => ['3:mic.txt']],
            'Cannot read the calibration file' => ['--cal' => ['/nonexistent/mic.txt']],
            '--save takes a file name without a directory' => ['--save' => '../out3'],
            '--wav analyses an existing recording. Leave out the outputs.' => ['outputs' => 'out3', '--wav' => 'x.wav'],
        ];
        foreach ($cases as $message => $input) {
            $tester = $this->measure($input + ['--socket' => sys_get_temp_dir().'/sdwa5-dsp-none.sock']);

            self::assertSame(Command::INVALID, $tester->getStatusCode(), $message);
            self::assertStringContainsString($message, self::text($tester));
        }
        self::assertDirectoryDoesNotExist($this->directory);
    }
}
