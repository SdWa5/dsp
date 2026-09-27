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

    private static function has(string $path): bool
    {
        $node = self::state();
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
        foreach (array_keys($paths) as $path) {
            self::assertTrue(self::has((string) $path), \sprintf('"%s" is not a state path.', $path));
        }
    }

    public function testEveryPresetSetsStatePaths(): void
    {
        $presets = self::page()->filter('[data-preset-set-value]')->each(static fn (Crawler $n): string => (string) $n->attr('data-preset-set-value'));
        // DJ and 1:1 on every compressor: 8 outputs and the aux
        self::assertCount(18, $presets);
        foreach ($presets as $json) {
            $set = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($set);
            self::assertNotSame([], $set);
            foreach ($set as $path => $value) {
                self::assertTrue(self::has((string) $path), \sprintf('"%s" is not a state path.', $path));
                self::assertIsInt($value);
            }
        }
        $dj = json_decode($presets[0], true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['outputs.0.comp.ratio' => 9, 'outputs.0.comp.knee' => 0, 'outputs.0.comp.attack' => 9, 'outputs.0.comp.release' => 199, 'outputs.0.comp.threshold' => 168], $dj);
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
