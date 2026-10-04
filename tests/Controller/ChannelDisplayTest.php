<?php

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/** Display preferences remain local; individual name edits retain existing device bindings. */
final class ChannelDisplayTest extends WebTestCase
{
    #[DataProvider('locales')]
    public function testDisplayPageAndHiddenPageRecovery(string $locale, string $title, string $unhide): void
    {
        $page = self::createClient()->request('GET', '/', server: ['HTTP_ACCEPT_LANGUAGE' => $locale]);
        self::assertResponseIsSuccessful();
        $display = $page->filter('section[data-page=display]');
        self::assertSame($title, $display->filter('h1')->text());
        self::assertSame('de' === $locale ? 'Kanalnamen' : 'Channel Names', $display->filter('[data-display-section=channels] > h3')->text());
        self::assertSame([...range(0, 15), 18], $display->filter('[data-channelview-target=channel]')->each(static fn (Crawler $node): int => (int) $node->attr('value')));
        self::assertCount(17, $display->filter('[data-channelview-target=channel][checked]'));
        self::assertCount(2, $display->filter('.display-settings__channels > .display-settings__row'));
        self::assertCount(1, $display->filter('[data-display-side=aux] [data-channelview-target=channel][value=18]'));
        self::assertCount(1, $display->filter('.display-settings__row [data-channelview-target=grouped]'));
        self::assertCount(1, $display->filter('.display-settings__row [data-action="channelview#showAll"]'));
        self::assertCount(1, $display->filter('[data-channelview-target=grouped]:not([checked])'));
        self::assertSame('de' === $locale ? 'Ausgeblendete Kanäle' : 'Hidden channels', $display->filter('.display-settings__channels > h3')->text());
        foreach (['channels', 'eq'] as $section) {
            self::assertSame(['inputs', 'outputs', 'aux'], $display->filter('[data-display-section='.$section.'] .channel-visibility > [data-display-side]')->each(static fn (Crawler $node): ?string => $node->attr('data-display-side')));
        }

        self::assertCount(2, $display->filter('[data-display-section=channels] fieldset[data-controller~=linkplace][data-controller~=selectors]'));
        foreach (['inputs', 'outputs'] as $side) {
            self::assertCount(1, $display->filter('legend [data-action="channelview#showSide"][data-channelview-side-param='.$side.']'));
        }
        self::assertCount(1, $display->filter('[data-channelview-target=channel][value="18"]'));
        self::assertCount(0, $display->filter('[data-fader-bind-value], [data-toggle-bind-value], [data-choice-bind-value]'));
        self::assertSame($unhide, $page->filter('[data-action="editor#unhideChannel"]')->text());
        self::assertCount(1, $page->filter('[data-editor-target=channelWarning][hidden] [data-editor-page-param=gain]'));
    }

    public function testCompleteDisplayInventoryAndMenu(): void
    {
        $page = self::createClient()->request('GET', '/');
        $display = $page->filter('section[data-page=display]');
        self::assertSame(['appearance', 'channels', 'links', 'meters', 'eq', 'matrix', 'panels'], $display->filter('[data-display-section]')->each(static fn (Crawler $node): ?string => $node->attr('data-display-section')));
        $sections = $display->filter('[data-display-section]');
        $sections->each(static function (Crawler $section): void {
            self::assertSame('collapse', $section->attr('data-controller'));
            self::assertSame('display.'.$section->attr('data-display-section'), $section->attr('data-collapse-key-value'));
            self::assertCount(1, $section->filter('legend.card__title [data-action="collapse#toggle"][aria-expanded=true]'));
        });
        self::assertCount(0, $page->filter('#dialog-display'));
        self::assertCount(0, $page->filter('.tab[data-page=display]'));
        self::assertCount(53, $display->filter('[data-displaysettings-target=eq]'));
        self::assertCount(17, $display->filter('[data-displaysettings-target=overview]'));
        self::assertCount(2, $display->filter('.display-settings__eq-side[data-controller=linkplace]'));
        self::assertCount(2, $display->filter('.display-settings__eq-side[data-linkplace-group-value=true]'));
        self::assertCount(4, $display->filter('[data-controller~=selectors][data-selectors-compact-value=true]'));
        self::assertCount(0, $page->filter('section:not([data-page=display]) [data-selectors-compact-value=true]'));
        foreach (['inputs', 'outputs', 'aux'] as $side) {
            self::assertCount('aux' === $side ? 1 : 8, $display->filter('[data-eq-side='.$side.'] .display-settings__eq'));
        }
        $names = $display->filter('[data-display-section=channels] [data-controller=text]');
        self::assertCount(17, $names);
        self::assertCount(1, $display->filter('.names--rows'));
        self::assertCount(0, $page->filter('#dialog-names .names--rows'));
        self::assertSame(['inputs', 'outputs', 'aux'], $display->filter('.names__row')->each(static fn (Crawler $node): ?string => $node->attr('data-names-side')));
        foreach (['inputs' => 8, 'outputs' => 8, 'aux' => 1] as $side => $count) {
            $row = $display->filter('.names__row[data-names-side='.$side.']');
            self::assertCount($count, $row->filter('[data-controller=text]'));
            self::assertSame('group', $row->attr('role'));
            self::assertNotEmpty($row->attr('aria-label'));
            self::assertSame($row->attr('aria-label'), $row->filter('.names__heading')->text());
        }

        $names->each(static function (Crawler $input) use ($page): void {
            $shortcut = $page->filter('#dialog-names [data-text-bind-value="'.$input->attr('data-text-bind-value').'"]');
            foreach (['data-text-send-value', 'maxlength', 'title'] as $attribute) {
                self::assertSame($shortcut->attr($attribute), $input->attr($attribute));
            }
            self::assertNull($input->attr('data-text-link-value'));
        });
        self::assertCount(1, $display->filter('[data-displaysettings-target=panels]'));
        self::assertCount(1, $display->filter('[data-displaysettings-target=names]'));
        self::assertSame(['auto', 'light', 'dark'], $display->filter('[data-displaysettings-target=theme] option')->each(static fn (Crawler $node): ?string => $node->attr('value')));
        self::assertSame(['auto', 'en', 'de'], $display->filter('[data-action="change->editor#language"] option')->each(static fn (Crawler $node): ?string => $node->attr('value')));
        $menu = $page->filter('.menu__list')->reduce(static fn (Crawler $node): bool => $node->filter('[data-editor-dialog-param=names]')->count() > 0);
        self::assertCount(1, $menu);
        self::assertSame(['names', 'link', 'copy', 'sub', 'groups'], $menu->filter('[data-editor-dialog-param]')->each(static fn (Crawler $node): ?string => $node->attr('data-editor-dialog-param')));
        self::assertCount(0, $menu->filter('input, [data-controller=meterview]'));
        self::assertCount(0, $menu->filter('[data-editor-page-param=display]'));
        self::assertCount(1, $page->filter('nav.menu > button.menu__title[data-editor-page-param=display]'));
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
            $stack = $page->filter('section[data-page='.$channel.'] .peq__band-stack');
            self::assertCount(1, $stack);
            self::assertContains('group-stack', explode(' ', (string) $stack->attr('class')));
            $prefix = 'aux' === $channel ? 'aux' : 'channel';
            self::assertSame([$prefix.'.bands', $prefix.'.selected'], $stack->children()->each(static fn (Crawler $node): ?string => $node->attr('data-collapse-key-value')));
        }
    }

    public function testChannelTitlesKeepIndividualRenameFieldsWithoutRepeatingTheirNames(): void
    {
        $page = self::createClient()->request('GET', '/');
        foreach ([...array_map(static fn (int $i): string => 'inputs'.$i, range(0, 7)), ...array_map(static fn (int $i): string => 'outputs'.$i, range(0, 7)), 'aux'] as $channel) {
            $heading = $page->filter('section[data-page='.$channel.'] .channel-page-heading');
            self::assertCount(1, $heading);
            self::assertCount(1, $heading->filter('.page__title [data-channel-title-value=true]'));
            self::assertCount(0, $heading->filter('.page__title .ch__name, .page__title [data-controller=text]'));
            $field = $heading->filter('.channel-name-edit [data-controller=text]');
            self::assertCount('aux' === $channel ? 1 : 8, $field);
            $field->each(static function (Crawler $input): void {
                self::assertSame('8', $input->attr('maxlength'));
                self::assertStringEndsWith('.name', (string) $input->attr('data-text-bind-value'));
                self::assertNull($input->attr('data-text-link-value'));
                $send = json_decode((string) $input->attr('data-text-send-value'), true, flags: JSON_THROW_ON_ERROR);
                self::assertSame('channelName', $send[0]['command']);
                $parts = explode('.', (string) $input->attr('data-text-bind-value'));
                $index = 'aux' === $parts[0] ? 18 : (int) $parts[1] + ('outputs' === $parts[0] ? 8 : 0);
                self::assertSame($index, $send[0]['args']['channel']);
            });
            self::assertSame($field->count(), count(array_unique($field->each(static fn (Crawler $input): ?string => $input->attr('data-text-bind-value')))));
            self::assertCount('aux' === $channel ? 0 : 1, $heading->filter('.page__title [data-linkname-target=groupTitle][hidden]'));
            if ('aux' !== $channel) {
                self::assertSame('linkname', $heading->attr('data-controller'));
                self::assertCount(1, $heading->filter('.channel-name-edit:not([hidden])'));
                self::assertCount(7, $heading->filter('.channel-name-edit[hidden]'));
            }
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
        yield 'English' => ['en', 'Display Settings', 'Unhide channel'];
        yield 'German' => ['de', 'Anzeigeeinstellungen', 'Kanal einblenden'];
    }
}
