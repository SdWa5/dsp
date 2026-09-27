<?php

namespace App\Tests\Controller;

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
        unset($_SERVER['TRACKDSP_BRIDGE_SOCKET'], $_ENV['TRACKDSP_BRIDGE_SOCKET']);
    }

    private static function browser(?string $socket = null): KernelBrowser
    {
        \assert(null !== self::$bridge);
        $_SERVER['TRACKDSP_BRIDGE_SOCKET'] = $_ENV['TRACKDSP_BRIDGE_SOCKET'] = $socket ?? self::$bridge->socket;

        return self::createClient();
    }

    /**
     * @param array<mixed>|null $body
     *
     * @return array<mixed>
     */
    private static function json(KernelBrowser $browser, string $method, string $uri, ?array $body = null, int $status = 200): array
    {
        $browser->request($method, $uri, server: ['HTTP_X_TRACKDSP' => '1'], content: null === $body ? '' : (string) json_encode($body));
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
        $browser->request('POST', '/api/write', server: ['HTTP_X_TRACKDSP' => '1'], content: '{broken');
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
        self::assertSame('trackdsp-8x8', json_decode($file, true)['format']);

        self::json($browser, 'POST', '/api/write', ['command' => 'delay', 'args' => ['channel' => 12, 'samples' => 0]]);
        $browser->request('POST', '/api/upload', server: ['HTTP_X_TRACKDSP' => '1'], content: $file);
        self::assertSame(200, $browser->getResponse()->getStatusCode(), (string) $browser->getResponse()->getContent());
        self::assertSame(480, self::json($browser, 'GET', '/api/state')['outputs'][4]['delay']);

        $browser->request('POST', '/api/upload', server: ['HTTP_X_TRACKDSP' => '1'], content: '{"format":"other"}');
        self::assertSame(400, $browser->getResponse()->getStatusCode());
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
        self::assertStringContainsString('X-Trackdsp', (string) $browser->getResponse()->getContent());
    }

    public function testUnknownHostIsRejected(): void
    {
        $browser = self::browser();
        $browser->request('GET', '/api/status', server: ['HTTP_HOST' => 'attacker.example']);

        self::assertSame(400, $browser->getResponse()->getStatusCode());
    }

    public function testWithoutBridge(): void
    {
        $browser = self::browser(sys_get_temp_dir().'/trackdsp-missing.sock');

        self::assertStringContainsString('not running', self::json($browser, 'GET', '/api/status', status: 503)['error']);
        $browser->request('GET', '/api/events');
        self::assertStringContainsString('"bridge":false', $browser->getInternalResponse()->getContent());
    }

    public function testDiscoverListsWhatTheScanFinds(): void
    {
        $client = self::browser();
        $client->request('POST', '/api/discover', server: ['HTTP_X_TRACKDSP' => '1']);
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        // TRACKDSP_DISCOVERY_NETWORKS in .env.test, where nothing listens on port 9761
        self::assertSame(['networks' => ['127.0.0.1/32'], 'devices' => []], $body);
    }

    public function testDiscoverNeedsTheHeader(): void
    {
        $client = self::browser();
        $client->request('POST', '/api/discover');
        self::assertResponseStatusCodeSame(403);
    }
}
