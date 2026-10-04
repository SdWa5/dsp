<?php

namespace App\Tests\Controller;

use App\AutoEq\FittedBand;
use App\Bridge\BridgeClient;
use App\Measurement\Playback;
use App\Protocol\Commands;
use App\Protocol\Scale;
use App\Tests\Support\BridgeProcess;
use App\Tests\Support\FakeRecorderFactory;
use App\Tests\Support\Signals;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The stepwise auto EQ of the web editor against a bridge process on the simulated device. The fake
 * recorder of the test environment records pink noise through a +6 dB peak at 1 kHz.
 */
final class AutoEqControllerTest extends WebTestCase
{
    private static ?BridgeProcess $bridge = null;
    private KernelBrowser $browser;
    private string $directory;

    public static function setUpBeforeClass(): void
    {
        self::$bridge = new BridgeProcess();
    }

    public static function tearDownAfterClass(): void
    {
        self::$bridge?->stop();
        self::$bridge = null;
        unset($_SERVER['SDWA5_DSP_BRIDGE_SOCKET'], $_ENV['SDWA5_DSP_BRIDGE_SOCKET']);
    }

    protected function setUp(): void
    {
        \assert(null !== self::$bridge);
        $_SERVER['SDWA5_DSP_BRIDGE_SOCKET'] = $_ENV['SDWA5_DSP_BRIDGE_SOCKET'] = self::$bridge->socket;
        $this->browser = self::createClient();
        $directory = self::getContainer()->getParameter('sdwa5_dsp.autoeq_dir');
        self::assertIsString($directory);
        $this->directory = $directory;
        (new Filesystem())->remove($this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
        parent::tearDown();
    }

    private function client(): BridgeClient
    {
        \assert(null !== self::$bridge);

        return new BridgeClient(self::$bridge->socket);
    }

    /**
     * @param array<mixed>|string|null $body
     *
     * @return array<mixed>
     */
    private function call(string $method, string $uri, array|string|null $body = null, int $status = 200): array
    {
        $content = \is_array($body) ? (string) json_encode($body) : ($body ?? '');
        $this->browser->request($method, $uri, server: ['HTTP_X_SDWA5_DSP' => '1'], content: $content);
        $response = $this->browser->getResponse();
        self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());
        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data);

        return $data;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function out1Peq(): array
    {
        $state = $this->client()->request('state');
        self::assertIsArray($state);
        self::assertIsArray($state['outputs'][0]['peq'] ?? null);

        return array_values(array_filter($state['outputs'][0]['peq'], 'is_array'));
    }

    public function testWarnsAboutAGainAtCompressor1To1(): void
    {
        $this->client()->request('write', ['frames' => [
            Playback::hex(Commands::compressor(8, 0, 0, 10, 10, 180)),
            Playback::hex(Commands::gain(8, Scale::dbToGain(-6.0))),
        ]]);

        try {
            $warnings = $this->call('POST', '/api/autoeq/start', ['outputs' => [8], 'seconds' => 2, 'settle' => 0])['warnings'];
            self::assertCount(1, $warnings);
            self::assertStringContainsString('Out1 is set to -6.0 dB with its compressor at 1:1.0', $warnings[0]);
            self::assertSame($warnings, $this->call('POST', '/api/autoeq/record')['warnings']);
        } finally {
            // The bridge serves every test of this class
            $this->client()->request('write', ['frames' => [Playback::hex(Commands::gain(8, Scale::GAIN_UNITY))]]);
        }
    }

    public function testMeasuresFitsAppliesAndReverts(): void
    {
        $before = $this->out1Peq();
        $index = $this->call('GET', '/api/autoeq');
        self::assertNull($index['session']);
        self::assertTrue($index['recorder']);
        self::assertSame('default', $index['devices'][0]['id']);
        self::assertSame('default', $index['device']);

        $start = $this->call('POST', '/api/autoeq/start', ['outputs' => [8], 'bands' => 5, 'seconds' => 2, 'settle' => 0]);
        self::assertSame([], $start['warnings']);
        $session = $start['session'];
        self::assertSame(['Out1'], $session['labels']);
        self::assertSame('default', $session['device']);
        self::assertMatchesRegularExpression('/^backup-\d{8}-\d{6}\.json$/', $session['backup']);

        $session = $this->call('POST', '/api/autoeq/record')['session'];
        self::assertSame([['name' => 'Position 1', 'file' => $session['recordings'][0]['file'], 'verify' => false]], $session['recordings']);
        self::assertSame($before, $this->out1Peq(), 'A recording leaves the bands as they were');

        $fit = $this->call('POST', '/api/autoeq/fit', ['target' => 'flat'])['session']['fit'];
        self::assertSame('flat', $fit['settings']['target']);
        $bands = $fit['result']['bands'];
        self::assertNotSame([], $bands);
        self::assertLessThanOrEqual(5, \count($bands));
        self::assertEqualsWithDelta(1000, $bands[0]['hz'], 150);
        self::assertLessThan(-3, $bands[0]['db']);
        self::assertLessThan($fit['result']['rms_before'], $fit['result']['rms_after']);
        self::assertCount(\count($fit['result']['freqs']), $fit['result']['eq']);

        self::assertTrue($this->call('POST', '/api/autoeq/apply')['session']['applied']);
        $applied = $this->out1Peq();
        self::assertSame($bands[0]['freq'], $applied[0]['freq']);
        self::assertFalse($applied[0]['bypass']);
        self::assertTrue($applied[6]['bypass'] === $before[6]['bypass'] && $applied[6]['gain'] === $before[6]['gain'], 'Bands above the owned ones stay');

        $session = $this->call('POST', '/api/autoeq/record', ['verify' => true])['session'];
        self::assertSame('Verify 2', $session['recordings'][1]['name']);
        self::assertSame($applied, $this->out1Peq(), 'A verify recording measures with the bands on and keeps them');
        $result = $this->call('POST', '/api/autoeq/fit', ['target' => 'flat'])['session']['fit']['result'];
        self::assertCount(\count($result['freqs']), $result['verified']);

        $reverted = $this->call('POST', '/api/autoeq/revert');
        self::assertSame([8], $reverted['outputs']);
        self::assertFalse($reverted['session']['applied']);
        self::assertSame($before, $this->out1Peq());

        self::assertSame($session['backup'], $this->call('GET', '/api/autoeq')['backups'][0]['file']);
        self::assertNull($this->call('POST', '/api/autoeq/discard')['session']);
        self::assertSame([], glob($this->directory.'/measure-*') ?: []);
        self::assertCount(1, glob($this->directory.'/backup-*') ?: []);
    }

    public function testUploadRemoveAndCalibration(): void
    {
        $this->call('POST', '/api/autoeq/start', ['outputs' => [8, 9], 'mic_channel' => 2]);
        $peak = new FittedBand(Scale::hzToIndex(250), Scale::dbToPeqGain(6.0), Scale::qToIndex(2.0));
        $samples = Signals::peak(Signals::pinkNoise(2 * Scale::SAMPLE_RATE), $peak);
        $wav = Signals::wav([array_fill(0, \count($samples), 0.0), $samples], 24);

        $session = $this->call('POST', '/api/autoeq/upload?name=FOH', $wav)['session'];
        self::assertSame('FOH', $session['recordings'][0]['name']);
        self::assertStringContainsString('WAV', $this->call('POST', '/api/autoeq/upload', 'not a wav', 400)['error']);
        self::assertCount(1, $this->call('GET', '/api/autoeq')['session']['recordings']);

        $session = $this->call('POST', '/api/autoeq/calibration', "20 0\n1000 0\n20000 -1\n")['session'];
        self::assertNotNull($session['calibration']);
        $bands = $this->call('POST', '/api/autoeq/fit', ['target' => 'tilt:-1', 'high' => 8000])['session']['fit']['result']['bands'];
        $cuts = array_filter($bands, static fn (array $b): bool => $b['db'] < -3 && abs($b['hz'] - 250) < 60);
        self::assertNotSame([], $cuts, (string) json_encode($bands));
        self::assertNull($this->call('POST', '/api/autoeq/calibration', '')['session']['calibration']);
        $this->call('POST', '/api/autoeq/calibration', 'nonsense', 400);

        self::assertSame([], $this->call('POST', '/api/autoeq/remove', ['index' => 0])['session']['recordings']);
        $this->call('POST', '/api/autoeq/remove', ['index' => 0], 400);
        self::assertStringContainsString('Nothing to analyse', $this->call('POST', '/api/autoeq/fit', [], 400)['error']);
    }

    public function testRejectsBadRequests(): void
    {
        self::assertStringContainsString('Start an auto EQ session', $this->call('POST', '/api/autoeq/record', null, 400)['error']);
        self::assertStringContainsString('outputs only', $this->call('POST', '/api/autoeq/start', ['outputs' => [0]], 400)['error']);
        self::assertStringContainsString('at least one output', $this->call('POST', '/api/autoeq/start', [], 400)['error']);
        self::assertStringContainsString('No such backup', $this->call('POST', '/api/autoeq/revert', ['backup' => '../config.bin'], 400)['error']);

        $this->call('POST', '/api/autoeq/start', ['outputs' => [8], 'device' => FakeRecorderFactory::FAILING, 'seconds' => 2, 'settle' => 0]);
        $before = $this->out1Peq();
        self::assertStringContainsString('arecord failed', $this->call('POST', '/api/autoeq/record', null, 500)['error']);
        self::assertSame($before, $this->out1Peq());
        self::assertStringContainsString('flat or tilt', $this->call('POST', '/api/autoeq/fit', ['target' => 'file:/etc/passwd'], 400)['error']);
        self::assertStringContainsString('Fit first', $this->call('POST', '/api/autoeq/apply', null, 400)['error']);

        $this->browser->request('POST', '/api/autoeq/discard');
        self::assertSame(403, $this->browser->getResponse()->getStatusCode());
    }
}
