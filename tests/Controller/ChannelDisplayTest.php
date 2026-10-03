<?php

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/** Display settings are browser preferences; device bindings and selector membership stay complete. */
final class ChannelDisplayTest extends WebTestCase
{
    #[DataProvider('locales')]
    public function testDisplayDialogAndHiddenPageRecovery(string $locale, string $title, string $unhide): void
    {
        $page = self::createClient()->request('GET', '/', server: ['HTTP_ACCEPT_LANGUAGE' => $locale]);
        self::assertResponseIsSuccessful();
        $dialog = $page->filter('#dialog-display');
        self::assertSame($title, $dialog->filter('h2')->text());
        self::assertSame([...range(0, 15), 18], $dialog->filter('[data-channelview-target=channel]')->each(static fn (Crawler $node): int => (int) $node->attr('value')));
        self::assertCount(17, $dialog->filter('[data-channelview-target=channel][checked]'));
        self::assertCount(1, $dialog->filter('[data-channelview-target=grouped]:not([checked])'));
        self::assertCount(2, $dialog->filter('fieldset[data-controller~=linkplace][data-controller~=selectors]'));
        foreach (['inputs', 'outputs'] as $side) {
            self::assertCount(1, $dialog->filter('legend [data-action="channelview#showSide"][data-channelview-side-param='.$side.']'));
        }
        self::assertCount(1, $dialog->filter('[data-channelview-target=channel][value="18"]'));
        self::assertCount(0, $dialog->filter('[data-fader-bind-value], [data-toggle-bind-value], [data-choice-bind-value]'));
        self::assertSame($unhide, $page->filter('[data-action="editor#unhideChannel"]')->text());
        self::assertCount(1, $page->filter('[data-editor-target=channelWarning][hidden] [data-editor-page-param=gain]'));
    }

    public function testGroupingIsOptInForProcessingAndKeepsIndependentSelectors(): void
    {
        $page = self::createClient()->request('GET', '/');
        self::assertCount(7, $page->filter('[data-linkplace-display-value=true]'));
        self::assertCount(0, $page->filter('section[data-page=matrix] [data-linkplace-display-value=true]'));
        self::assertCount(8, $page->filter('section[data-page=autoeq] [data-controller~=selectors] input[name=outputs]'));
        foreach (['section[data-page=gain]', '#dialog-sub', 'section[data-page=aux]'] as $scope) {
            self::assertCount(2, $page->filter($scope.' .aux-routes__column[data-controller~=selectors]'));
            self::assertCount(16, $page->filter($scope.' .aux-routes__column .toggle--route'));
        }
        foreach (['#dialog-automix', '#dialog-camera'] as $scope) {
            self::assertCount(8, $page->filter($scope.' [data-controller~=selectors] .toggle--route'));
        }
    }

    public function testCommonSectionsPrecedeSpecialSectionsInConsistentOrder(): void
    {
        $page = self::createClient()->request('GET', '/');
        foreach (['inputs0' => ['bands', 'selected', 'gain', 'hpf', 'lpf', 'gate', 'fbq'], 'outputs0' => ['bands', 'selected', 'gain', 'hpf', 'lpf', 'comp', 'delay'], 'aux' => ['bands', 'selected', 'gain', 'comp', 'select', 'automix', 'effect', 'camera']] as $channel => $expected) {
            $keys = $page->filter('section[data-page='.$channel.'] .peq__panel [data-collapse-key-value]')->each(static fn (Crawler $node): string => explode('.', (string) $node->attr('data-collapse-key-value'))[1]);
            self::assertSame($expected, $keys, $channel);
        }
    }

    public function testCrossoverSectionsKeepTheirSharedVerticalStack(): void
    {
        $page = self::createClient()->request('GET', '/');
        foreach (['inputs0', 'outputs0'] as $channel) {
            $stack = $page->filter('section[data-page='.$channel.'] [data-collapse-key-value="channel.hpf"]')->ancestors()->first();
            self::assertContains('group-stack', explode(' ', (string) $stack->attr('class')));
            self::assertSame(['channel.hpf', 'channel.lpf'], $stack->children()->each(static fn (Crawler $node): ?string => $node->attr('data-collapse-key-value')));
        }
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function locales(): iterable
    {
        yield 'English' => ['en', 'Channel display settings', 'Unhide channel'];
        yield 'German' => ['de', 'Kanalanzeige einstellen', 'Kanal einblenden'];
    }
}
