<?php

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Translation\Loader\YamlFileLoader;

/**
 * Tooltips of the editor controls: a widget finds its own by its state path (editor.tip.<path without side and
 * index>), panels and table headers name theirs. Every tooltip text must reach the page in both languages.
 */
final class EditorTooltipTest extends WebTestCase
{
    /** @return iterable<string, array{string}> */
    public static function locales(): iterable
    {
        yield 'English' => ['en'];
        yield 'German' => ['de'];
    }

    #[DataProvider('locales')]
    public function testEveryTooltipReachesThePage(string $locale): void
    {
        $page = $this->page($locale);
        $tips = $this->tips($locale);
        self::assertGreaterThan(50, \count($tips));
        $titles = implode("\n", $page->filter('[title]')->each(static fn (Crawler $n): string => (string) $n->attr('title')));
        foreach ($tips as $key => $text) {
            self::assertStringContainsString($text, $titles, $key.' is on no control');
        }
        self::assertStringNotContainsString('editor.tip', $page->html());
    }

    #[DataProvider('locales')]
    public function testControlsShowTheTooltipOfTheirStatePath(string $locale): void
    {
        $page = $this->page($locale);
        $tips = $this->tips($locale);
        $title = static fn (string $selector): ?string => $page->filter($selector)->first()->attr('title');

        // The same tooltip on every page and in every dialog
        self::assertSame($tips['editor.tip.gain'], $title('section[data-page=gain] [data-fader-bind-value="inputs.0.gain"]'));
        self::assertSame($tips['editor.tip.gain'], $title('section[data-page=outputs3] [data-fader-bind-value="outputs.3.gain"]'));
        self::assertSame($tips['editor.tip.comp.ratio'], $title('[data-fader-bind-value="outputs.2.comp.ratio"]'));
        self::assertSame($tips['editor.tip.comp.ratio'], $title('#dialog-auxcomp [data-fader-bind-value="aux.comp.ratio"]'));
        self::assertSame($tips['editor.tip.sub.gain'], $title('#dialog-sub [data-fader-bind-value="sub.0.gain"]'));
        self::assertSame($tips['editor.tip.automix.select'], $title('#dialog-automix [data-toggle-bind-value="aux.automix.select"]'));
        self::assertSame($tips['editor.tip.phantom'], $title('[data-toggle-bind-value="inputs.4.phantom"]'));
        self::assertSame($tips['editor.tip.delay_unit'], $title('select[data-choice-bind-value=delay_unit]'));
        // A fader with a default keeps the double-click hint after its tooltip
        self::assertStringStartsWith($tips['editor.tip.gain'].' ', (string) $title('[data-fader-bind-value="inputs.0.gain"] input[type=range]'));
        // Panels, filters and the band table
        self::assertSame($tips['editor.tip.panel.fbq'], $title('section[data-page=inputs0] [data-collapse-key-value="channel.fbq"] .fold'));
        self::assertSame($tips['editor.tip.panel.hpf'], $title('section[data-page=outputs0] [data-collapse-key-value="channel.hpf"] .fold'));
        self::assertSame($tips['editor.tip.band.q'], $page->filter('section[data-page=aux] .peq__table th')->eq(2)->attr('title'));
        self::assertSame($tips['editor.tip.panel.groups'], $title('#dialog-groups h2'));
        // A path without a tooltip renders no title, so the matrix cells keep only their label
        self::assertNull($title('[data-toggle-bind-value="outputs.0.route"]'));
    }

    #[DataProvider('locales')]
    public function testEverySectionTitleHasATooltip(string $locale): void
    {
        $page = $this->page($locale);
        $titles = $page->filter('.fold, dialog > .dialog__head h2, details.card > summary');
        self::assertGreaterThan(30, $titles->count());
        $titles->each(static function (Crawler $node): void {
            self::assertNotEmpty($node->attr('title'), trim($node->text()).' has no tooltip');
        });
    }

    private function page(string $locale): Crawler
    {
        $client = self::createClient();
        $client->getCookieJar()->set(new Cookie('trackdsp_locale', $locale));
        $page = $client->request('GET', '/');
        self::assertResponseIsSuccessful();

        return $page;
    }

    /**
     * @return array<string, string>
     */
    private function tips(string $locale): array
    {
        $messages = (new YamlFileLoader())->load(__DIR__.'/../../translations/messages.'.$locale.'.yaml', $locale)->all('messages');

        return array_filter($messages, static fn (string $key): bool => str_starts_with($key, 'editor.tip.'), \ARRAY_FILTER_USE_KEY);
    }
}
