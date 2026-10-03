<?php

namespace App\Tests\Controller;

use App\Model\Device;
use App\Tests\Support\BridgeProcess;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** File inspection works without a bridge and cannot change live settings or gain riders. */
final class PresetInspectionTest extends WebTestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        unset($_SERVER['TRACKDSP_BRIDGE_SOCKET'], $_ENV['TRACKDSP_BRIDGE_SOCKET']);
    }

    public function testVendorFileOpensWithoutABridgeAndKeepsAbsentValuesUnknown(): void
    {
        $_SERVER['TRACKDSP_BRIDGE_SOCKET'] = $_ENV['TRACKDSP_BRIDGE_SOCKET'] = sys_get_temp_dir().'/trackdsp-inspect-missing.sock';
        $client = self::createClient();
        $file = (string) file_get_contents(__DIR__.'/../fixtures/vendor/SdWa5_Default_Preset_3_DSPs.prs');
        $client->request('POST', '/api/preset', server: ['HTTP_X_TRACKDSP' => '1'], content: $file);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertCount(8, $data['device']['inputs']);
        self::assertCount(8, $data['device']['outputs']);
        self::assertSame('LoopInL', $data['device']['inputs'][0]['name']);
        self::assertSame('DSP3_R', $data['device']['outputs'][7]['name']);
        self::assertNull($data['device']['delay_unit']);
        self::assertNull($data['device']['tone_mode']);
        self::assertNull($data['device']['tone_freq']);
        self::assertNull($data['device']['aux']['eq_bypass']);
        self::assertSame(array_fill(0, 8, null), array_column($data['device']['outputs'], 'eq_bypass'));
        self::assertSame([], $data['riders']);

        $client->request('POST', '/api/preset', server: ['HTTP_X_TRACKDSP' => '1'], content: substr($file, 0, 500));
        self::assertResponseStatusCodeSame(400);
        $client->request('POST', '/api/preset', content: $file);
        self::assertResponseStatusCodeSame(403);
    }

    public function testJsonInspectionAndRejectedFilesLeaveLiveStateAndRidersUnchanged(): void
    {
        $bridge = new BridgeProcess();
        try {
            $_SERVER['TRACKDSP_BRIDGE_SOCKET'] = $_ENV['TRACKDSP_BRIDGE_SOCKET'] = $bridge->socket;
            $client = self::createClient();
            $client->request('GET', '/api/state');
            $before = $client->getResponse()->getContent();
            $client->request('GET', '/api/status');
            $riders = json_decode((string) $client->getResponse()->getContent(), true)['riders'];
            $device = Device::parse((string) file_get_contents(__DIR__.'/../../resources/factory_config.bin'));
            $data = json_decode($device->toJson(), true, flags: \JSON_THROW_ON_ERROR);
            $data['riders'] = [['channel' => 7, 'target' => -24]];
            $client->request('POST', '/api/preset', server: ['HTTP_X_TRACKDSP' => '1'], content: json_encode($data, \JSON_THROW_ON_ERROR));
            self::assertResponseIsSuccessful();
            $parsed = json_decode((string) $client->getResponse()->getContent(), true);
            self::assertSame($device->inputs[0]->gain, $parsed['device']['inputs'][0]['gain']);
            self::assertSame(7, $parsed['riders'][0]['channel']);
            self::assertEquals(-24, $parsed['riders'][0]['target']);
            self::assertArrayHasKey('average', $parsed['riders'][0]);

            $bad = $data;
            $bad['riders'][0]['channel'] = 8;
            $invalidGain = $data;
            $invalidGain['device']['raw'] = substr_replace($data['device']['raw'], 'ffff', 2 * (Device::INPUT_BASE + 56), 4);
            foreach (['{broken', json_encode($bad, \JSON_THROW_ON_ERROR), json_encode($invalidGain, \JSON_THROW_ON_ERROR)] as $file) {
                $client->request('POST', '/api/preset', server: ['HTTP_X_TRACKDSP' => '1'], content: $file);
                self::assertResponseStatusCodeSame(400);
            }
            $client->request('GET', '/api/state');
            self::assertSame($before, $client->getResponse()->getContent());
            $client->request('GET', '/api/status');
            self::assertSame($riders, json_decode((string) $client->getResponse()->getContent(), true)['riders']);
        } finally {
            $bridge->stop();
        }
    }

    public function testFileOpenAndApplyAreSeparateUiActions(): void
    {
        $page = self::createClient()->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSame('change->editor#inspectFile', $page->filter('input[data-editor-target=file]')->attr('data-action'));
        $dialog = $page->filter('#dialog-preset');
        self::assertCount(1, $dialog);
        self::assertSame('presetfile', $dialog->attr('data-controller'));
        self::assertCount(1, $dialog->filter('[data-action="presetfile#apply"][disabled]'));
        self::assertCount(0, $dialog->filter('[data-fader-send-value], input[type=range]'));
    }
}
