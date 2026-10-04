<?php

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

final class EditorLocaleTest extends WebTestCase
{
    /** @return iterable<string, array{?string, ?string, string}> */
    public static function locales(): iterable
    {
        yield 'default English' => [null, null, 'en'];
        yield 'browser German' => [null, 'de-AT,de;q=0.9,en;q=0.5', 'de'];
        yield 'unsupported browser' => [null, 'fr-FR', 'en'];
        yield 'cookie English beats browser' => ['en', 'de-DE', 'en'];
        yield 'cookie German beats browser' => ['de', 'en-US', 'de'];
        yield 'invalid cookie falls through' => ['fr', 'de-DE', 'de'];
    }

    #[DataProvider('locales')]
    public function testEditorUsesTheRequestLanguage(?string $cookie, ?string $browser, string $locale): void
    {
        $client = self::createClient();
        if (null !== $cookie) {
            $client->getCookieJar()->set(new Cookie('sdwa5_dsp_locale', $cookie));
        }
        $page = $client->request('GET', '/', server: null === $browser ? [] : ['HTTP_ACCEPT_LANGUAGE' => $browser]);
        self::assertResponseIsSuccessful();
        self::assertSame($locale, $page->filter('html')->attr('lang'));
        self::assertSame('de' === $locale ? 'Datei' : 'File', $page->filter('.menu__title')->first()->text());
        self::assertStringContainsString('de' === $locale ? 'Eingang' : 'Input', $page->filter('[data-collapse-key-value="gain.input"] .fold')->text());
        self::assertStringContainsString('de' === $locale ? 'Aufnahmegerät' : 'Capture device', $page->filter('section[data-page=autoeq]')->text());
        self::assertSame('de' === $locale ? 'Out3 mit Out5' : 'Out3 with Out5', $page->filter('#dialog-link [data-side=outputs][data-row="2"][data-column="4"]')->attr('aria-label'));
        self::assertSame('paramFreq', $page->filter('section[data-page=inputs0] [data-peq-target=paramFreq]')->attr('data-peq-target'));
        self::assertStringNotContainsString('editor.menu.', $page->filter('body')->text());
        $messages = json_decode($page->filter('#sdwa5-dsp-i18n')->text(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($messages);
        self::assertSame('de' === $locale ? 'Hell' : 'Light', $messages['Light']);
        $labels = json_decode($page->filter('#sdwa5-dsp-labels')->text(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($labels);
        self::assertSame('de' === $locale ? 'Rosa Rauschen' : 'Pink Noise', $labels['TONE_MODES'][1]);
        self::assertSame('1:1.0', $labels['COMP_RATIOS'][0]);
        // Translated text must not change command arguments, state paths or option values.
        self::assertSame('inputs.0.gain', $page->filter('section[data-page=gain] [data-fader-bind-value="inputs.0.gain"]')->attr('data-fader-bind-value'));
        self::assertSame('1', $page->filter('select[data-choice-bind-value=device_id] option')->first()->attr('value'));
        self::assertCount(3, $page->filter('input[name=language]'));
    }
}
