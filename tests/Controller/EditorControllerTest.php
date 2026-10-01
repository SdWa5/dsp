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
        // The changed-setting count stays hidden until the state arrives
        self::assertCount(7, $page->filter('.tab > .tab__count[hidden]'));
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

    public function testMatrixHasBothOrientations(): void
    {
        $tables = self::page()->filter('section[data-page=matrix] table.matrix');
        self::assertCount(2, $tables);
        [$normal, $swapped] = [$tables->eq(0), $tables->eq(1)];
        self::assertNull($normal->attr('hidden'));
        self::assertNotNull($swapped->attr('hidden'));
        self::assertCount(8, $normal->filter('tbody tr'));
        self::assertCount(9, $swapped->filter('tbody tr'));
        foreach ([$normal, $swapped] as $table) {
            self::assertCount(72, $table->filter('.toggle--cell'));
            self::assertSame('editor#swapMatrix', $table->filter('.matrix__corner')->attr('data-action'));
        }
        // The signal runs from In to Out: In headers left or on top, Out headers right or at the bottom
        self::assertSame('In1', $normal->filter('thead th')->first()->filter('.ch__id')->text());
        self::assertSame('Out1', $normal->filter('tbody tr')->first()->filter('th')->last()->filter('.ch__id')->text());
        self::assertSame('In1', $swapped->filter('tbody tr')->first()->filter('th')->first()->filter('.ch__id')->text());
        self::assertCount(8, $swapped->filter('tfoot th .ch'));
        // Row In3 of the swapped table holds bit 2 of every output
        $row = $swapped->filter('tbody tr')->eq(2)->filter('.toggle--cell');
        self::assertSame('outputs.7.route', $row->last()->attr('data-toggle-bind-value'));
        self::assertSame(['2'], array_values(array_unique($row->each(static fn (Crawler $n): string => (string) $n->attr('data-toggle-bit-value')))));
    }

    public function testFoldablePanelsHaveOneToggleAndAKeyOfTheirOwn(): void
    {
        $panels = self::page()->filter('[data-controller~=collapse]');
        $keys = $panels->each(static fn (Crawler $n): string => (string) $n->attr('data-collapse-key-value'));
        self::assertContains('gain.input', $keys);
        self::assertContains('gain.groups', $keys);
        self::assertContains('autoeq.fit', $keys);
        self::assertContains('channel.hpf', $keys);
        self::assertContains('aux.automix', $keys);
        // Within a page two panels never fold together. The channel pages share their keys on purpose
        self::page()->filter('section[data-page]')->each(static function (Crawler $page): void {
            $keys = $page->filter('[data-controller~=collapse]')->each(static fn (Crawler $n): string => (string) $n->attr('data-collapse-key-value'));
            self::assertSame($keys, array_unique($keys), 'Two panels would fold together on '.$page->attr('data-page'));
        });
        self::assertCount(16, self::page()->filter('[data-collapse-key-value="channel.curve"]'));
        $panels->each(static function (Crawler $panel): void {
            self::assertCount(1, $panel->filter('[data-collapse-target=toggle]'));
            self::assertSame('true', $panel->filter('[data-collapse-target=toggle]')->attr('aria-expanded'));
        });
        // The dialogs show the same strips without a fold
        self::assertCount(0, self::page()->filter('dialog [data-controller~=collapse]'));
    }

    public function testLinkDisplayOptions(): void
    {
        // The same settings in the Channels menu and below the Link matrices, each copy with a radio group of its own
        foreach (['.menu' => 'link-placement', '#dialog-link' => 'link-dialog-placement'] as $scope => $name) {
            $boxes = self::page()->filter($scope.' input[data-editor-target=linkView]');
            self::assertSame(['code', 'shape', 'colour'], $boxes->each(static fn (Crawler $n): string => (string) $n->attr('value')));
            $radios = self::page()->filter($scope.' input[data-editor-target=linkPlacement]');
            self::assertSame(['normal', 'side', 'stacked'], $radios->each(static fn (Crawler $n): string => (string) $n->attr('value')));
            self::assertSame([$name], array_values(array_unique($radios->each(static fn (Crawler $n): string => (string) $n->attr('name')))));
        }
        // The Link dialog is one channel x channel matrix per side, with the diagonal disabled
        $matrices = self::page()->filter('#dialog-link table.link-matrix');
        self::assertSame(['inputs', 'outputs'], $matrices->each(static fn (Crawler $n): string => (string) $n->attr('data-side')));
        $outputs = $matrices->eq(1);
        self::assertCount(64, $outputs->filter('.link-cell'));
        self::assertCount(8, $outputs->filter('.link-cell[disabled]'));
        self::assertSame('Out1', $outputs->filter('thead th .ch__id')->first()->text());
        // Headers and cells carry their channel, so the editor can order and merge them by the placement
        self::assertSame(['0', '1', '2', '3', '4', '5', '6', '7'], $outputs->filter('tbody th')->each(static fn (Crawler $n): string => (string) $n->attr('data-index')));
        self::assertSame('Out3 with Out5', $outputs->filter('tbody tr')->eq(2)->filter('.link-cell')->eq(4)->attr('aria-label'));
        self::assertSame('editor#linkCell', $outputs->filter('.link-cell')->first()->attr('data-action'));
        // The gap between inputs and outputs starts at Out1 until the tabs are reordered
        self::assertSame('outputs0', self::page()->filter('.chtab.is-split')->attr('data-page'));
    }

    public function testEqOverviewHasASwitchPerChannelAndSendsNothing(): void
    {
        $overview = self::page()->filter('section[data-page=gain] [data-controller~=eqoverview]');
        self::assertCount(1, $overview);
        $paths = $overview->filter('[data-eqoverview-target=show]')->each(static fn (Crawler $n): string => (string) $n->attr('value'));
        self::assertCount(16, $paths);
        foreach ($paths as $path) {
            self::assertTrue(self::has($path.'.peq'), $path);
        }
        // Showing a curve is a view setting, never a write to the device
        self::assertCount(0, $overview->filter('[data-toggle-send-value], [data-fader-send-value]'));
    }

    public function testChannelSectionsFollowTheLinkPlacement(): void
    {
        $page = self::page();
        $placed = [
            'section[data-page=gain] .strips[data-collapse-key-value="gain.input"]' => 1,
            'section[data-page=gain] .strips[data-collapse-key-value="gain.output"]' => 1,
            'section[data-page=gain] .eq-overview__side' => 2,
            'section[data-page=gain] .strip' => 16 + 10,
            'section[data-page=gate] .strips' => 1,
            'section[data-page=comp] .strips' => 1,
            'section[data-page=delay] .strips' => 1,
            'section[data-page=matrix] table.matrix' => 2,
            'section[data-page=aux] fieldset[data-collapse-key-value="aux.select"]' => 1,
            'section[data-page=autoeq] .checks' => 1,
        ];
        foreach ($placed as $selector => $count) {
            self::assertCount($count, $page->filter($selector), $selector);
            $page->filter($selector)->each(static function (Crawler $node) use ($selector): void {
                if (str_ends_with($selector, '.strip') && null === $node->filter('.toggle--route')->getNode(0)) {
                    return;
                }
                self::assertContains('linkplace', explode(' ', (string) $node->attr('data-controller')), $selector);
            });
        }
        // The Link dialog shows the groups being edited, so its channel labels leave out the groups on the device
        self::assertCount(32, $page->filter('dialog#dialog-link .ch[data-channel-link-value=false]'));
    }

    public function testMatrixPageCarriesTheRoutingGraph(): void
    {
        $graph = self::page()->filter('section[data-page=matrix] .matrix-page > [data-controller~=routing]');
        self::assertCount(1, $graph);
        self::assertSame('matrix.graph', $graph->attr('data-collapse-key-value'));
        self::assertCount(1, $graph->filter('svg[data-routing-target=svg]'));
        // The graph only shows the routing, the matrix sets it
        self::assertCount(0, $graph->filter('[data-toggle-send-value], [data-fader-send-value]'));
    }

    public function testGainWarningButtonsNameTheGainTheySet(): void
    {
        $warnings = self::page()->filter('[data-controller~=gainwarning]');
        // One per output on the Comp page and on the Gain page
        self::assertCount(16, $warnings);
        $warnings->each(static function (Crawler $warning): void {
            self::assertCount(1, $warning->filter('[data-action="gainwarning#exact"] [data-gainwarning-target=exact]'));
            self::assertCount(1, $warning->filter('[data-action="gainwarning#keepLevel"] [data-gainwarning-target=keep]'));
        });
    }

    public function testEveryBindingIsAStatePath(): void
    {
        $paths = [];
        foreach (self::page()->filter('*')->each(static fn (Crawler $node): ?\DOMNode => $node->getNode(0)) as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            foreach ($node->attributes as $attribute) {
                if (preg_match('/^data-[a-z]+-(bind|enabled|path|muted-by)-value$/', $attribute->name)) {
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
