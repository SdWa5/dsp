<?php

namespace App\Tests\Controller;

use App\Tests\Support\BridgeProcess;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Guarded requests use a private simulated bridge; no shared demo or hardware is contacted. */
final class HistoryControllerTest extends WebTestCase
{
    private ?BridgeProcess $bridge = null;

    protected function tearDown(): void
    {
        $this->bridge?->stop();
        unset($_SERVER['TRACKDSP_BRIDGE_SOCKET'], $_ENV['TRACKDSP_BRIDGE_SOCKET']);
        parent::tearDown();
    }

    public function testGuardedReplayRejectsConflictsWithoutPartialWrites(): void
    {
        $this->bridge = new BridgeProcess();
        $_SERVER['TRACKDSP_BRIDGE_SOCKET'] = $_ENV['TRACKDSP_BRIDGE_SOCKET'] = $this->bridge->socket;
        $browser = self::createClient();
        $browser->request('GET', '/api/state');
        $state = json_decode((string) $browser->getResponse()->getContent(), true);
        $changes = [
            ['path' => 'outputs.0.gain', 'before' => $state['outputs'][0]['gain'], 'after' => 180],
            ['path' => 'outputs.1.gain', 'before' => $state['outputs'][1]['gain'] + 1, 'after' => 180],
        ];
        $browser->request('POST', '/api/history', server: ['HTTP_X_TRACKDSP' => '1'], content: json_encode(['changes' => $changes], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(409);
        $browser->request('GET', '/api/state');
        self::assertSame($state['outputs'][0]['gain'], json_decode((string) $browser->getResponse()->getContent(), true)['outputs'][0]['gain']);
        $changes[1]['before'] = $state['outputs'][1]['gain'];
        $browser->request('POST', '/api/history', server: ['HTTP_X_TRACKDSP' => '1'], content: json_encode(['changes' => $changes], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $browser->request('GET', '/api/state');
        self::assertSame(180, json_decode((string) $browser->getResponse()->getContent(), true)['outputs'][0]['gain']);
    }

    public function testRiderReplayCanonicalizesJsonAndPreservesOtherChannels(): void
    {
        $this->bridge = new BridgeProcess();
        $_SERVER['TRACKDSP_BRIDGE_SOCKET'] = $_ENV['TRACKDSP_BRIDGE_SOCKET'] = $this->bridge->socket;
        $browser = self::createClient();
        $commands = array_map(static fn (int $i): array => ['command' => 'link', 'args' => ['channel' => $i, 'flags' => 1 << $i]], range(0, 7));
        $browser->request('POST', '/api/write', server: ['HTTP_X_TRACKDSP' => '1'], content: json_encode(['commands' => $commands], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        foreach ([0, 7] as $channel) {
            $browser->request('POST', '/api/ride', server: ['HTTP_X_TRACKDSP' => '1'], content: json_encode(['channel' => $channel, 'settings' => ['target' => -18]], \JSON_THROW_ON_ERROR));
            self::assertResponseIsSuccessful();
        }
        $browser->request('GET', '/api/status');
        $riders = json_decode((string) $browser->getResponse()->getContent(), true)['riders'];
        $body = ['changes' => [], 'riders' => ['channels' => [0], 'before' => [$riders[0]], 'after' => []]];
        $browser->request('POST', '/api/history', server: ['HTTP_X_TRACKDSP' => '1'], content: json_encode($body, \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $browser->request('GET', '/api/status');
        $after = json_decode((string) $browser->getResponse()->getContent(), true)['riders'];
        self::assertCount(1, $after);
        self::assertSame(7, $after[0]['channel']);
        $browser->request('POST', '/api/history', server: ['HTTP_X_TRACKDSP' => '1'], content: json_encode($body, \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(409);
    }

    public function testHistoryControlsAndRegistryAreRenderedInBothLanguages(): void
    {
        $browser = self::createClient();
        foreach (['en', 'de'] as $locale) {
            $crawler = $browser->request('GET', '/', server: ['HTTP_ACCEPT_LANGUAGE' => $locale]);
            self::assertSame(1, $crawler->filter('[data-controller="editor history"]')->count());
            self::assertSame(3, $crawler->filter('button[data-history-target]')->count());
            self::assertSame('polite', $crawler->filter('[data-history-target="announcement"]')->attr('aria-live'));
            $units = json_decode($crawler->filter('#trackdsp-history')->text(), true);
            self::assertGreaterThan(250, \count($units));
        }
    }
}
