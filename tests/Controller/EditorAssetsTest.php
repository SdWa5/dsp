<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class EditorAssetsTest extends WebTestCase
{
    public function testBrowserAssetsCompileAndNodeTestsStayPrivate(): void
    {
        $client = self::createClient();
        $mapper = self::getContainer()->get(AssetMapperInterface::class);
        self::assertInstanceOf(AssetMapperInterface::class, $mapper);
        self::assertNull($mapper->getAsset('tests/i18n.test.js'));
        $count = 0;
        foreach ($mapper->allAssets() as $asset) {
            self::assertStringNotContainsString('tests/', $asset->logicalPath);
            ++$count;
        }
        self::assertGreaterThan(20, $count);
        $defaults = $mapper->getAsset('lib/defaults.js');
        self::assertNotNull($defaults);
        $client->request('GET', $defaults->publicPath);
        self::assertResponseIsSuccessful();
        $response = $client->getResponse();
        $content = $response instanceof BinaryFileResponse
            ? file_get_contents($response->getFile()->getPathname())
            : $response->getContent();
        self::assertIsString($content);
        self::assertStringContainsString('export function pageChanges', $content);
        $client->request('GET', '/assets/tests/i18n.test.js');
        self::assertResponseStatusCodeSame(404);
    }
}
