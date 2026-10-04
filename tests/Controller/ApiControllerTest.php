<?php

namespace App\Tests\Controller;

use App\Model\Device;
use App\Tests\Support\BridgeProcess;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The JSON API against a bridge process on the simulated device.
 */
final class ApiControllerTest extends WebTestCase
{
    private static ?BridgeProcess $bridge = null;

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

    private static function browser(?string $socket = null): KernelBrowser
    {
        \assert(null !== self::$bridge);
        $_SERVER['SDWA5_DSP_BRIDGE_SOCKET'] = $_ENV['SDWA5_DSP_BRIDGE_SOCKET'] = $socket ?? self::$bridge->socket;

        return self::createClient();
    }

    /**
     * @param array<mixed>|null $body
     *
     * @return array<mixed>
     */
    private static function json(KernelBrowser $browser, string $method, string $uri, ?array $body = null, int $status = 200): array
    {
        $browser->request($method, $uri, server: ['HTTP_X_SDWA5_DSP' => '1'], content: null === $body ? '' : (string) json_encode($body));
        $response = $browser->getResponse();
        self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());
        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data);

        return $data;
    }

    public function testStatusAndState(): void
    {
        $browser = self::browser();

        self::assertTrue(self::json($browser, 'GET', '/api/status')['connected']);
        self::assertSame('Bulgaria26', self::json($browser, 'GET', '/api/state')['preset_name']);
    }

    public function testWriteOneAndMany(): void
    {
        $browser = self::browser();

        self::json($browser, 'POST', '/api/write', ['command' => 'channelName', 'args' => ['channel' => 3, 'name' => 'Bass']]);
        self::json($browser, 'POST', '/api/write', ['commands' => [
            ['command' => 'gainDb', 'args' => ['channel' => 3, 'db' => -10]],
            ['command' => 'phase', 'args' => ['channel' => 3, 'inverted' => true]],
        ]]);
        $input = self::json($browser, 'GET', '/api/state')['inputs'][3];

        self::assertSame(['Bass', 180, true], [$input['name'], $input['gain'], $input['phase']]);
    }

    public function testWriteErrors(): void
    {
        $browser = self::browser();

        self::assertStringContainsString('Unknown command', self::json($browser, 'POST', '/api/write', ['command' => 'setPin', 'args' => ['pin' => '1']], 400)['error']);
        self::assertStringContainsString('needs a "command"', self::json($browser, 'POST', '/api/write', ['args' => []], 400)['error']);
        self::assertStringContainsString('needs a "command"', self::json($browser, 'POST', '/api/write', null, 400)['error']);
        $browser->request('POST', '/api/write', server: ['HTTP_X_SDWA5_DSP' => '1'], content: '{broken');
        self::assertSame(400, $browser->getResponse()->getStatusCode());
        self::assertStringContainsString('JSON object', (string) $browser->getResponse()->getContent());
        $browser->request('GET', '/api/write');
        self::assertSame(405, $browser->getResponse()->getStatusCode());
    }

    public function testDownloadAndUploadRoundTrip(): void
    {
        $browser = self::browser();
        self::json($browser, 'POST', '/api/write', ['command' => 'delay', 'args' => ['channel' => 12, 'samples' => 480]]);

        $browser->request('GET', '/api/download');
        $file = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('attachment; filename="Bulgaria26.json"', (string) $browser->getResponse()->headers->get('Content-Disposition'));
        self::assertSame('sdwa5-dsp-8x8', json_decode($file, true)['format']);

        $before = self::json($browser, 'GET', '/api/state');
        self::json($browser, 'POST', '/api/write', ['commands' => [
            ['command' => 'delay', 'args' => ['channel' => 12, 'samples' => 0]],
            ['command' => 'eqBypass', 'args' => ['channel' => 15, 'on' => !$before['outputs'][7]['eq_bypass']]],
            ['command' => 'channelName', 'args' => ['channel' => 0x12, 'name' => 'Probe']],
            ['command' => 'delayUnit', 'args' => ['unit' => (1 + $before['delay_unit']) % 3]],
        ]]);
        $browser->request('POST', '/api/upload', server: ['HTTP_X_SDWA5_DSP' => '1'], content: $file);
        self::assertSame(200, $browser->getResponse()->getStatusCode(), (string) $browser->getResponse()->getContent());
        $after = self::json($browser, 'GET', '/api/state');
        self::assertSame(480, $after['outputs'][4]['delay']);
        self::assertSame($before['outputs'][7]['eq_bypass'], $after['outputs'][7]['eq_bypass']);
        self::assertSame($before['aux']['name'], $after['aux']['name']);
        self::assertSame($before['delay_unit'], $after['delay_unit']);

        $browser->request('POST', '/api/upload', server: ['HTTP_X_SDWA5_DSP' => '1'], content: '{"format":"other"}');
        self::assertSame(400, $browser->getResponse()->getStatusCode());

        // A gain above the device's range in the raw configuration
        $data = json_decode($file, true);
        $data['device']['raw'] = substr_replace($data['device']['raw'], 'ffff', 2 * (Device::INPUT_BASE + 56), 4);
        $browser->request('POST', '/api/upload', server: ['HTTP_X_SDWA5_DSP' => '1'], content: (string) json_encode($data));
        self::assertSame(400, $browser->getResponse()->getStatusCode());
        self::assertStringContainsString('Gain raw value', (string) $browser->getResponse()->getContent());
    }

    public function testAPresetFileCarriesTheGainRiders(): void
    {
        $browser = self::browser();
        self::json($browser, 'POST', '/api/ride', ['channel' => 2, 'settings' => ['target' => -24]]);
        $browser->request('GET', '/api/download');
        $file = json_decode((string) $browser->getResponse()->getContent(), true);
        // The demo links In3 and In4, so the rider is one for both
        self::assertSame([2, 3], array_column($file['riders'], 'channel'));
        self::assertEquals([-24, -24], array_column($file['riders'], 'target'));

        self::json($browser, 'POST', '/api/unride', ['channel' => 2]);
        $browser->request('POST', '/api/upload', server: ['HTTP_X_SDWA5_DSP' => '1'], content: (string) json_encode($file));
        self::assertSame(200, $browser->getResponse()->getStatusCode(), (string) $browser->getResponse()->getContent());
        self::assertSame([2, 3], array_column(self::json($browser, 'GET', '/api/status')['riders'], 'channel'));

        // A bad rider refuses the whole file, before anything is written
        $bad = $file;
        $bad['riders'][0]['target'] = 'loud';
        $bad['device']['raw'] = '';
        $browser->request('POST', '/api/upload', server: ['HTTP_X_SDWA5_DSP' => '1'], content: (string) json_encode($bad));
        self::assertSame(400, $browser->getResponse()->getStatusCode());
        self::assertSame([2, 3], array_column(self::json($browser, 'GET', '/api/status')['riders'], 'channel'));

        // A file without riders, as from before 0.43.0, stops them
        unset($file['riders']);
        $browser->request('POST', '/api/upload', server: ['HTTP_X_SDWA5_DSP' => '1'], content: (string) json_encode($file));
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        self::assertSame([], self::json($browser, 'GET', '/api/status')['riders']);
    }

    public function testUploadsAVendorPresetFile(): void
    {
        $browser = self::browser();
        $before = self::json($browser, 'GET', '/api/state');
        $file = (string) file_get_contents(__DIR__.'/../fixtures/vendor/SdWa5_Default_Preset_3_DSPs.prs');

        $browser->request('POST', '/api/upload', server: ['HTTP_X_SDWA5_DSP' => '1', 'CONTENT_TYPE' => 'application/octet-stream'], content: $file);

        self::assertSame(200, $browser->getResponse()->getStatusCode(), (string) $browser->getResponse()->getContent());
        $after = self::json($browser, 'GET', '/api/state');
        self::assertSame('LoopInL', $after['inputs'][0]['name']);
        self::assertSame('DSP3_R', $after['outputs'][7]['name']);
        self::assertSame(0b01010100, $after['outputs'][0]['route']);
        // The file carries no EQ bypass and no delay unit, so the device keeps its own
        self::assertSame($before['delay_unit'], $after['delay_unit']);
        self::assertSame(array_column($before['outputs'], 'eq_bypass'), array_column($after['outputs'], 'eq_bypass'));

        $browser->request('POST', '/api/upload', server: ['HTTP_X_SDWA5_DSP' => '1'], content: substr($file, 0, 500));
        self::assertSame(400, $browser->getResponse()->getStatusCode());
        self::assertStringContainsString('vendor preset file has 1278 bytes', (string) $browser->getResponse()->getContent());
    }

    public function testConnectDisconnectRefresh(): void
    {
        $browser = self::browser();

        self::assertFalse(self::json($browser, 'POST', '/api/disconnect')['connected']);
        self::assertStringContainsString('not connected', self::json($browser, 'GET', '/api/state', status: 409)['error']);
        self::assertSame('demo', self::json($browser, 'POST', '/api/connect', ['target' => 'demo'])['target']);
        usleep(300_000);
        self::assertTrue(self::json($browser, 'POST', '/api/refresh')['connected']);
        self::assertStringContainsString('non-empty', self::json($browser, 'POST', '/api/connect', ['target' => 5], 400)['error']);
    }

    public function testEventsStream(): void
    {
        $browser = self::browser();
        $browser->request('GET', '/api/events');
        $response = $browser->getInternalResponse();

        self::assertStringStartsWith('text/event-stream', $response->getHeaders()['content-type'][0] ?? '');
        self::assertSame('no', $response->getHeader('X-Accel-Buffering'));
        self::assertStringStartsWith("retry: 1000\n\n", $response->getContent());
        self::assertStringContainsString("event: status\ndata: {", $response->getContent());
        self::assertStringContainsString('event: meters', $response->getContent());
    }

    public function testWritesNeedTheHeader(): void
    {
        $browser = self::browser();
        $browser->request('POST', '/api/write', content: '{"command":"mute","args":{"channel":8,"on":true}}');

        self::assertSame(403, $browser->getResponse()->getStatusCode());
        self::assertStringContainsString('X-Sdwa5-Dsp', (string) $browser->getResponse()->getContent());
    }

    public function testUnknownHostIsRejected(): void
    {
        $browser = self::browser();
        $browser->request('GET', '/api/status', server: ['HTTP_HOST' => 'attacker.example']);

        self::assertSame(400, $browser->getResponse()->getStatusCode());
    }

    public function testWithoutBridge(): void
    {
        $browser = self::browser(sys_get_temp_dir().'/sdwa5-dsp-missing.sock');

        self::assertStringContainsString('not running', self::json($browser, 'GET', '/api/status', status: 503)['error']);
        $browser->request('GET', '/api/events');
        self::assertStringContainsString('"bridge":false', $browser->getInternalResponse()->getContent());
    }

    public function testDiscoverListsWhatTheScanFinds(): void
    {
        $client = self::browser();
        $client->request('POST', '/api/discover', server: ['HTTP_X_SDWA5_DSP' => '1']);
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        // SDWA5_DSP_DISCOVERY_NETWORKS in .env.test, where nothing listens on port 9761
        self::assertSame(['networks' => ['127.0.0.1/32'], 'devices' => []], $body);
    }

    public function testDiscoverNeedsTheHeader(): void
    {
        $client = self::browser();
        $client->request('POST', '/api/discover');
        self::assertResponseStatusCodeSame(403);
    }
}
