<?php

namespace App\Tests\Controller;

use App\Bridge\CommandMap;
use App\Transport\DemoTransport;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The editor page and its wiring: every widget must bind to a path of the device state, and every
 * command template must build a frame once its "@path" arguments are read from that state.
 */
final class EditorControllerTest extends WebTestCase
{
    private const ROOT = __DIR__.'/../..';

    private static ?Crawler $page = null;

    /** @var array<mixed>|null */
    private static ?array $state = null;

    private static function page(): Crawler
    {
        if (null === self::$page) {
            $client = self::createClient();
            self::$page = $client->request('GET', '/');
            self::assertResponseIsSuccessful();
            self::ensureKernelShutdown();
        }

        return self::$page;
    }

    /**
     * @return array<mixed>
     */
    private static function state(): array
    {
        return self::$state ??= DemoTransport::fromFile(self::ROOT.'/resources/demo_config.bin')->state()->toDevice()->toArray();
    }

    /**
     * @param array<mixed>|null $root the device state by default
     */
    private static function has(string $path, ?array $root = null): bool
    {
        $node = $root ?? self::state();
        foreach (explode('.', $path) as $key) {
            if (!\is_array($node) || !\array_key_exists($key, $node)) {
                return false;
            }
            $node = $node[$key];
        }

        return true;
    }

    private static function get(string $path): mixed
    {
        $node = self::state();
        foreach (explode('.', $path) as $key) {
            \assert(\is_array($node));
            $node = $node[$key];
        }

        return $node;
    }

    public function testPageRendersEveryPageAndDialog(): void
    {
        $page = self::page();
        self::assertSame('t.racks 8x8 Matrix Editor', $page->filter('title')->text());
        self::assertCount(23, $page->filter('section.page'));
        self::assertCount(7, $page->filter('.tab'));
        self::assertCount(16, $page->filter('.chtab'));
        self::assertCount(19, $page->filter('dialog'));
        foreach ($page->filter('[data-editor-dialog-param]')->each(static fn (Crawler $n): string => (string) $n->attr('data-editor-dialog-param')) as $dialog) {
            self::assertCount(1, $page->filter('dialog#dialog-'.$dialog), \sprintf('No dialog "%s".', $dialog));
        }
        $labels = json_decode($page->filter('#trackdsp-labels')->text(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($labels);
        self::assertSame('LK -48', $labels['SLOPES'][20]);
        // Option values are the raw values: not renumbered from 0
        self::assertSame('1', $page->filter('select[data-choice-bind-value=device_id] option')->first()->attr('value'));
        self::assertSame('254', $page->filter('select[data-choice-bind-value=device_id] option')->last()->attr('value'));
        self::assertSame('20', $page->filter('select[data-peq-target=filterSlope] option')->last()->attr('value'));
        self::assertSame('15', $page->filter('select[name=target] option')->last()->attr('value'));
    }

    public function testGainPageCarriesSubAndGroupControl(): void
    {
        $gain = self::page()->filter('section[data-page=gain]');
        // The same widgets as in the dialogs, bound to the same paths
        self::assertCount(2, $gain->filter('[data-fader-bind-value^="sub."]'));
        self::assertCount(8, $gain->filter('[data-fader-bind-value^="groups."]'));
        self::assertCount(8 * 8, $gain->filter('.toggle--route[data-toggle-bind-value^="groups."]'));
        self::assertCount(0, $gain->filter('[data-controller~=riders] [data-fader-bind-value^="sub."]'), 'Sub Control sits outside the rider scope.');
        self::assertCount(2, self::page()->filter('dialog#dialog-sub [data-fader-bind-value^="sub."]'));
    }

    public function testEveryBindingIsAStatePath(): void
    {
        $paths = [];
        foreach (self::page()->filter('*')->each(static fn (Crawler $node): ?\DOMNode => $node->getNode(0)) as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            foreach ($node->attributes as $attribute) {
                if (preg_match('/^data-[a-z]+-(bind|enabled|path)-value$/', $attribute->name)) {
                    $paths[$attribute->value] = true;
                }
            }
        }
        self::assertGreaterThan(100, \count($paths));
        $defaults = json_decode(self::page()->filter('#trackdsp-defaults')->text(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($defaults);
        // Not preset content, or not in the configuration: these have no factory value
        $none = '/^(preset_name|working_status|device_id|ip|tone_mode|tone_freq|inputs\.\d\.phantom)$/';
        foreach (array_keys($paths) as $path) {
            self::assertTrue(self::has((string) $path), \sprintf('"%s" is not a state path.', $path));
            self::assertSame(!preg_match($none, (string) $path), self::has((string) $path, $defaults), \sprintf('Factory default of "%s".', $path));
        }
    }

    public function testEveryCommandTemplateBuildsAFrame(): void
    {
        $count = 0;
        foreach (self::page()->filter('*')->each(static fn (Crawler $node): ?\DOMNode => $node->getNode(0)) as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            foreach ($node->attributes as $attribute) {
                if (!preg_match('/^data-[a-z]+-send-value$/', $attribute->name)) {
                    continue;
                }
                $templates = json_decode($attribute->value, true, flags: \JSON_THROW_ON_ERROR);
                self::assertIsArray($templates);
                foreach ($templates as $template) {
                    self::assertContains($template['command'], CommandMap::COMMANDS);
                    $args = [];
                    foreach ($template['args'] as $name => $value) {
                        if (\is_string($value) && str_starts_with($value, '@')) {
                            self::assertTrue(self::has(substr($value, 1)), \sprintf('"%s" is not a state path.', $value));
                            // As in the browser: a value the device does not report is sent as 0
                            $value = self::get(substr($value, 1)) ?? 0;
                        }
                        $args[$name] = $value;
                    }
                    CommandMap::build($template['command'], $args);
                    ++$count;
                }
            }
        }
        self::assertGreaterThan(150, $count);
    }

    public function testEveryControllerAndActionExists(): void
    {
        $page = self::page();
        $controllers = [];
        foreach ($page->filter('[data-controller]')->each(static fn (Crawler $n): string => (string) $n->attr('data-controller')) as $names) {
            foreach (preg_split('/\s+/', trim($names)) ?: [] as $name) {
                $controllers[$name] = true;
            }
        }
        $sources = [];
        foreach (array_keys($controllers) as $name) {
            $file = \sprintf('%s/assets/controllers/%s_controller.js', self::ROOT, $name);
            self::assertFileExists($file);
            $sources[$name] = file_get_contents($file).file_get_contents(self::ROOT.'/assets/lib/bound_controller.js');
        }
        foreach ($page->filter('[data-action]')->each(static fn (Crawler $n): string => (string) $n->attr('data-action')) as $actions) {
            foreach (preg_split('/\s+/', trim($actions)) ?: [] as $action) {
                self::assertSame(1, preg_match('/^(?:[a-z]+->)?([a-z]+)#([A-Za-z]+)$/', $action, $m), \sprintf('Malformed action "%s".', $action));
                self::assertArrayHasKey($m[1], $sources, \sprintf('Action "%s" names no controller on the page.', $action));
                self::assertMatchesRegularExpression(\sprintf('/^\s+(?:async\s+)?%s\(/m', $m[2]), $sources[$m[1]], \sprintf('Action "%s" has no method.', $action));
            }
        }
    }
}
