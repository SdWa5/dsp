<?php

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Aux is one independent destination and source, shared by both matrix orientations and Sub Control views. */
final class AuxRoutingTest extends WebTestCase
{
    public function testMatrixAuxCellsUseExistingMasksAndCommands(): void
    {
        $page = self::createClient()->request('GET', '/');
        self::assertResponseIsSuccessful();
        foreach ($page->filter('section[data-page=matrix] table.matrix') as $table) {
            $matrix = new \Symfony\Component\DomCrawler\Crawler($table);
            self::assertCount(80, $matrix->filter('td[data-source][data-output]'));
            self::assertCount(1, $matrix->filter('button.matrix__unsupported[disabled]'));
            self::assertCount(0, $matrix->filter('td[data-source="8"][data-output="8"]'));
            self::assertCount(1, $matrix->filter('th[data-source="8"] .ch[data-channel-index-value="18"]'));
            self::assertCount(1, $matrix->filter('th[data-output="8"] .ch[data-channel-index-value="18"]'));
            foreach (range(0, 7) as $i) {
                $incoming = $matrix->filter('td[data-source="'.$i.'"][data-output="8"] button');
                self::assertSame('aux.select', $incoming->attr('data-toggle-bind-value'));
                self::assertSame((string) $i, $incoming->attr('data-toggle-bit-value'));
                self::assertSame('Aux from In'.($i + 1), $incoming->attr('aria-label'));
                self::assertSame([['command' => 'routing', 'args' => ['channel' => 18, 'mask' => '@aux.select']]], json_decode((string) $incoming->attr('data-toggle-send-value'), true, flags: \JSON_THROW_ON_ERROR));
                $outgoing = $matrix->filter('td[data-source="8"][data-output="'.$i.'"] button');
                self::assertSame('outputs.'.$i.'.route', $outgoing->attr('data-toggle-bind-value'));
                self::assertSame('8', $outgoing->attr('data-toggle-bit-value'));
                self::assertSame('Out'.($i + 1).' from Aux', $outgoing->attr('aria-label'));
                self::assertSame([['command' => 'routing', 'args' => ['channel' => 8 + $i, 'mask' => '@outputs.'.$i.'.route']]], json_decode((string) $outgoing->attr('data-toggle-send-value'), true, flags: \JSON_THROW_ON_ERROR));
            }
        }
    }

    #[DataProvider('locales')]
    public function testSharedAuxSelectorsHaveSeparateLabelledColumns(string $locale, string $input, string $output, string $selfRoute): void
    {
        $page = self::createClient()->request('GET', '/', server: ['HTTP_ACCEPT_LANGUAGE' => $locale]);
        self::assertResponseIsSuccessful();
        foreach (['section[data-page=gain] .strips[data-collapse-key-value="gain.sub"] .aux-routes', 'dialog#dialog-sub .aux-routes', 'section[data-page=aux] fieldset[data-collapse-key-value="aux.select"]'] as $scope) {
            $aux = $page->filter($scope);
            $columns = $aux->filter('.aux-routes__column[data-controller=linkplace]');
            self::assertCount(2, $columns);
            self::assertSame($input, $columns->eq(0)->filter('legend')->text());
            self::assertSame($output, $columns->eq(1)->filter('legend')->text());
            self::assertCount(8, $columns->eq(0)->filter('button[data-toggle-bind-value="aux.select"]'));
            foreach (range(0, 7) as $i) {
                $button = $columns->eq(1)->filter('button[data-toggle-bind-value="outputs.'.$i.'.route"]');
                self::assertCount(1, $button);
                self::assertSame('8', $button->attr('data-toggle-bit-value'));
                self::assertNotEmpty($button->attr('aria-label'));
                self::assertSame([['command' => 'routing', 'args' => ['channel' => 8 + $i, 'mask' => '@outputs.'.$i.'.route']]], json_decode((string) $button->attr('data-toggle-send-value'), true, flags: \JSON_THROW_ON_ERROR));
            }
            // Routing retains its existing per-output behavior, independent of gain/PEQ link mirroring.
            self::assertCount(0, $aux->filter('[data-toggle-link-value]'));
        }
        foreach ($page->filter('button.matrix__unsupported') as $button) {
            self::assertInstanceOf(\DOMElement::class, $button);
            self::assertSame($selfRoute, $button->getAttribute('aria-label'));
            self::assertSame($selfRoute, $button->getAttribute('title'));
        }
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function locales(): iterable
    {
        yield 'English' => ['en', 'Input', 'Output', 'Aux cannot feed itself'];
        yield 'German' => ['de', 'Eingang', 'Ausgang', 'Aux kann sich nicht selbst versorgen'];
    }
}
