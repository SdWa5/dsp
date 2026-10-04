<?php

namespace App\Tests\Command;

use App\Bridge\BridgeClient;
use App\Filter\Chain;
use App\Measurement\TestTone;
use App\Tests\Support\BridgeProcess;
use App\Tests\Support\FakeRecorderFactory;
use App\Tests\Support\Signals;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * sdwa5-dsp:filter-shapes against a bridge subprocess on the simulated device. The fake recorder plays the
 * device: input 1 holds the noise through the filters that Out7 has at the moment of the recording, input 2
 * the reference.
 */
final class FilterShapesCommandTest extends KernelTestCase
{
    private ?BridgeProcess $bridge = null;
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/sdwa5-dsp-filter-shapes-test-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        FakeRecorderFactory::$probe = null;
        FakeRecorderFactory::$shape = null;
        $this->bridge?->stop();
        (new Filesystem())->remove($this->directory);
        parent::tearDown();
    }

    public function testWalksTheSettingsAndPutsTheDeviceBack(): void
    {
        $this->bridge = new BridgeProcess();
        $client = new BridgeClient($this->bridge->socket);
        $before = self::state($client);
        $during = [];
        FakeRecorderFactory::$probe = static function () use ($client, &$during): void {
            $during[] = self::state($client);
        };
        FakeRecorderFactory::$shape = static function (int $channel, array $samples) use (&$during): array {
            return self::played($channel, array_values($samples), $during);
        };

        $tester = $this->shapes(['output' => 'out7', '--input' => '1', '--reference' => '2', '--cases' => 'timing,peak-1k,hpf-lk24', '--save' => 'walk']);

        $tester->assertCommandIsSuccessful();
        self::assertSame($before, self::state($client));
        self::assertCount(5, $during);
        foreach ($during as $state) {
            self::assertSame(TestTone::PINK, $state['tone_mode']);
            self::assertFalse($state['outputs'][6]['mute']);
            self::assertFalse($state['outputs'][7]['mute']);
            self::assertTrue($state['outputs'][2]['mute']);
        }
        self::assertSame(48, $during[1]['outputs'][6]['delay']);
        self::assertSame([0, true], [$during[2]['outputs'][6]['delay'], $during[2]['outputs'][6]['phase']]);
        self::assertSame([false, 180], [$during[3]['outputs'][6]['phase'], $during[3]['outputs'][6]['peq'][0]['gain']]);
        self::assertSame(120, $during[4]['outputs'][6]['peq'][0]['gain']);
        self::assertSame(10, $during[4]['outputs'][6]['hpf']['slope']);

        $text = trim(preg_replace(['/^ ! /m', '/\s+/'], ['', ' '], $tester->getDisplay()) ?? '');
        self::assertStringContainsString('Pink noise on Out7, Out8, the other outputs muted. 5 settings of Out7, recorded on input 1 against input 2, 2 s each.', $text);
        self::assertStringContainsString('polarity normal, coherent 20Hz to 20kHz.', $text);
        self::assertStringContainsString('The reference output Out8 is not flat (-6.0 dB at compressor 1:1.0)', $text);
        self::assertMatchesRegularExpression('/delay delay 48 samples \(1\.000 ms\) \+48\.00 normal 0\.0\d 0\.\d 21 to 19k yes/', $text);
        self::assertMatchesRegularExpression('/hpf-lk24 HPF LK -24 1 kHz \+\d\.\d\d \w+ 0\.\d\d \d\.\d 4\d\d to 19k yes/', $text);
        self::assertMatchesRegularExpression('/invert phase inverted \+0\.00 inverted/', $text);
        self::assertStringContainsString('4 of 4 settings fit the model within 0.5 dB and 5°', $text);

        $json = json_decode((string) file_get_contents($this->directory.'/walk.json'), true, 16, \JSON_THROW_ON_ERROR);
        self::assertIsArray($json);
        self::assertSame(['Out7', 1, 'Out8', 2], [$json['output'], $json['input'], $json['reference_output'], $json['reference']]);
        self::assertSame($before['outputs'][6], $json['state']);
        self::assertIsArray($json['cases']);
        self::assertSame(['delay', 'invert', 'peak-1k', 'hpf-lk24'], array_column($json['cases'], 'id'));
        self::assertSame(['id', 'group', 'label', 'changes', 'polarity', 'fit', 'delay', 'points'], array_keys($json['cases'][0]));
        self::assertEqualsWithDelta(48.0, $json['cases'][0]['delay'], 0.05);
        self::assertTrue($json['cases'][3]['fit']['fits']);
        self::assertGreaterThan(100, \count($json['cases'][3]['points']));
        self::assertSame([], glob(sys_get_temp_dir().'/sdwa5-dsp-shapes-*') ?: []);
    }

    public function testInvalidInput(): void
    {
        foreach ([
            [['output' => 'out7', '--reference' => '2'], '--input must name an input'],
            [['output' => 'out7', '--input' => '2', '--reference' => '2'], 'must be different inputs'],
            [['output' => 'out8', '--input' => '1', '--reference' => '2'], 'Out8 cannot be its own reference output'],
            [['output' => 'in1', '--input' => '1', '--reference' => '2'], 'must be outputs'],
            [['output' => 'out7', '--input' => '1', '--reference' => '2', '--cases' => 'peak-2k'], 'Unknown case "peak-2k"'],
            [['output' => 'out7', '--input' => '1', '--reference' => '2', '--seconds' => '1'], '--seconds must be a whole number of 2 or more'],
            [['output' => 'out7', '--input' => '1', '--reference' => '2', '--save' => '../x'], '--save takes a file name'],
        ] as [$input, $message]) {
            $tester = $this->shapes($input);
            self::assertSame(Command::INVALID, $tester->getStatusCode(), $message);
            self::assertStringContainsString($message, preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');
        }
    }

    public function testANameIsNeverTakenTwice(): void
    {
        (new Filesystem())->dumpFile($this->directory.'/taken.json', '{}');
        FakeRecorderFactory::$probe = static fn () => self::fail('Recorded although the result could not be saved.');

        $tester = $this->shapes(['output' => 'out7', '--input' => '1', '--reference' => '2', '--save' => 'taken', '--socket' => '/nonexistent.sock']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('taken.json exists already', $tester->getDisplay());
    }

    /**
     * Input 1 through the filters of Out7 in the latest state the probe saw, the other inputs as recorded.
     *
     * @param list<float>        $samples
     * @param list<array<mixed>> $states
     *
     * @return list<float>
     */
    private static function played(int $channel, array $samples, array $states): array
    {
        $output = $states[\count($states) - 1]['outputs'][6] ?? null;
        self::assertIsArray($output);

        return 1 === $channel ? Signals::filter($samples, Chain::output($output)) : $samples;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function shapes(array $input): CommandTester
    {
        $tester = new CommandTester((new Application(self::bootKernel()))->find('sdwa5-dsp:filter-shapes'));
        $defaults = ['--dir' => $this->directory, '--settle' => '0', '--seconds' => '2'];
        if (null !== $this->bridge) {
            $defaults['--socket'] = $this->bridge->socket;
        }
        $tester->execute($input + $defaults, ['interactive' => false]);

        return $tester;
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
}
