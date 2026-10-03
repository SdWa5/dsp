<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Visible counterparts for the vendor About and RS232 command-builder dialogs. */
final class EditorParityTest extends WebTestCase
{
    public function testAboutShowsTheDeclaredApplicationVersion(): void
    {
        $client = self::createClient();
        $page = $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        $package = json_decode((string) file_get_contents(__DIR__.'/../../composer.json'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($package);
        self::assertSame($package['version'], $page->filter('#dialog-about [data-app-version]')->text());
    }

    public function testSerialBuilderIsLocalAndOffersCopyableOutput(): void
    {
        $page = self::createClient()->request('GET', '/');
        self::assertResponseIsSuccessful();
        $builder = $page->filter('#dialog-help [data-controller=serial]');
        self::assertCount(1, $builder);
        self::assertCount(1, $builder->filter('select[data-serial-target=command]'));
        self::assertSame('1', $builder->filter('[data-serial-target=id]')->attr('min'));
        self::assertSame('254', $builder->filter('[data-serial-target=id]')->attr('max'));
        self::assertCount(1, $builder->filter('input[readonly][data-serial-target=packet]'));
        self::assertCount(0, $builder->filter('[data-fader-send-value], [data-choice-send-value], button[type=submit]'));
    }

    public function testAllChannelEqResetButtonsUseTheSharedConfirmationAction(): void
    {
        $page = self::createClient()->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertCount(17, $page->filter('[data-controller~=peq] [data-action="peq#reset"]'));
    }

    public function testAllChannelEqViewsHaveADisplayOnlyLabelToggle(): void
    {
        $page = self::createClient()->request('GET', '/');
        self::assertResponseIsSuccessful();
        $toggles = $page->filter('[data-controller~=peq] input[data-peq-target=labels]');
        self::assertCount(17, $toggles);
        foreach ($toggles as $toggle) {
            self::assertInstanceOf(\DOMElement::class, $toggle);
            self::assertSame('peq#toggleLabels', $toggle->getAttribute('data-action'));
            self::assertFalse($toggle->hasAttribute('data-fader-send-value'));
        }
    }

    public function testAllChannelPlotsExposeWheelAndDoubleClickGestures(): void
    {
        $page = self::createClient()->request('GET', '/');
        self::assertResponseIsSuccessful();
        $plots = $page->filter('[data-controller~=peq] canvas[data-peq-target=canvas]');
        self::assertCount(17, $plots);
        foreach ($plots as $plot) {
            self::assertInstanceOf(\DOMElement::class, $plot);
            self::assertStringContainsString('wheel->peq#wheel', $plot->getAttribute('data-action'));
            self::assertStringContainsString('dblclick->peq#doubleClick', $plot->getAttribute('data-action'));
        }
    }

    public function testEveryFaderOffersDoubleClickAndHasANumericDefault(): void
    {
        $page = self::createClient()->request('GET', '/');
        self::assertResponseIsSuccessful();
        $defaults = json_decode($page->filter('#trackdsp-defaults')->text(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($defaults);
        $ranges = $page->filter('input[type=range]');
        self::assertGreaterThan(150, $ranges->count());
        foreach ($ranges as $range) {
            self::assertInstanceOf(\DOMElement::class, $range);
            self::assertStringContainsString('dblclick->', $range->getAttribute('data-action'));
            self::assertNotEmpty($range->getAttribute('title'));
            $parent = $range->parentNode;
            if ($parent instanceof \DOMElement && $parent->hasAttribute('data-fader-bind-value')) {
                $value = $defaults;
                foreach (explode('.', $parent->getAttribute('data-fader-bind-value')) as $key) {
                    $value = \is_array($value) ? ($value[$key] ?? null) : null;
                }
                self::assertTrue(\is_int($value) || \is_float($value), $parent->getAttribute('data-fader-bind-value'));
            } else {
                self::assertStringContainsString('dblclick->peq#resetFader', $range->getAttribute('data-action'));
            }
        }
    }
}
