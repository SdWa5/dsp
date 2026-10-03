<?php

namespace App\Tests\Controller;

use App\Bridge\CommandMap;
use App\Transport\DemoTransport;
use PHPUnit\Framework\Attributes\DataProvider;
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
        self::assertCount(20, $page->filter('dialog'));
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

    public function testConnectDialogHasADemoButton(): void
    {
        $dialog = self::page()->filter('dialog#dialog-connect');
        $demo = $dialog->filter('button[data-action="editor#connectDemo"]');
        self::assertCount(1, $demo);
        self::assertSame('button', $demo->attr('type'));
        self::assertSame('Demo', $demo->text());
        // The simulated device is no target to type
        self::assertStringNotContainsString('demo', (string) $dialog->filter('input[name=target]')->attr('placeholder'));
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
        self::assertCount(9, $normal->filter('tbody tr'));
        self::assertCount(9, $swapped->filter('tbody tr'));
        foreach ([$normal, $swapped] as $table) {
            self::assertCount(80, $table->filter('.toggle--cell'));
            self::assertSame('editor#swapMatrix', $table->filter('.matrix__corner')->attr('data-action'));
        }
        // The signal runs from In to Out: In headers left or on top, Out headers right or at the bottom
        self::assertSame('In1', $normal->filter('thead th')->first()->filter('.ch__id')->text());
        self::assertSame('Out1', $normal->filter('tbody tr')->first()->filter('th')->last()->filter('.ch__id')->text());
        self::assertSame('In1', $swapped->filter('tbody tr')->first()->filter('th')->first()->filter('.ch__id')->text());
        self::assertCount(9, $swapped->filter('tfoot th .ch'));
        // Row In3 of the swapped table holds bit 2 of every output
        $row = $swapped->filter('tbody tr')->eq(2)->filter('.toggle--cell[data-toggle-bind-value^="outputs."]');
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

    public function testEveryMeterCanMarkItsThreshold(): void
    {
        $meters = self::page()->filter('[data-controller=meter]');
        self::assertGreaterThan(0, $meters->count());
        $meters->each(static function (Crawler $meter): void {
            $mark = $meter->filter('.meter__threshold[data-meter-target=threshold]');
            self::assertCount(1, $mark);
            // Hidden until the state and the settings show it
            self::assertNotNull($mark->attr('hidden'));
        });
        // Show the mark and only while it works, once in the Channels menu
        $settings = self::page()->filter('.menu [data-controller=meterview]');
        self::assertCount(1, $settings);
        self::assertSame(['show', 'active'], $settings->filter('input[type=checkbox]')->each(static fn (Crawler $n): string => (string) $n->attr('data-meterview-target')));
        // A view setting, never a write to the device
        self::assertCount(0, $settings->filter('[data-toggle-send-value], [data-fader-send-value]'));
    }

    public function testOnlyOutputMetersHaveTheLimitLamp(): void
    {
        $meters = self::page()->filter('[data-controller=meter]');
        $meters->each(static function (Crawler $meter): void {
            $index = (int) $meter->attr('data-meter-index-value');
            $output = $index >= 8 && $index < 16;
            self::assertCount($output ? 1 : 0, $meter->filter('.meter__limit'), 'Meter '.$index);
            self::assertSame($output, str_contains((string) $meter->attr('class'), 'meter--output'));
        });
        self::assertGreaterThan(0, self::page()->filter('.meter__limit')->count());
    }

    public function testEveryGainFaderTurnsRedWithItsMute(): void
    {
        $faders = self::page()->filter('[data-controller=fader][data-fader-bind-value$=".gain"]');
        // Inputs, outputs and the Aux in their strips, tabs and Sub Control, and Sub Control and Group Control
        self::assertGreaterThan(40, $faders->count());
        $mutes = self::page()->filter('.toggle--mute')->each(static fn (Crawler $n): string => (string) $n->attr('data-toggle-bind-value'));
        $faders->each(static function (Crawler $fader) use ($mutes): void {
            $bind = (string) $fader->attr('data-fader-bind-value');
            // The effect level of the Aux has no mute of its own
            if ('aux.effect.gain' === $bind) {
                return;
            }
            $mute = substr($bind, 0, -\strlen('gain')).'mute';
            self::assertSame($mute, $fader->attr('data-fader-muted-by-value'), $bind);
            self::assertContains($mute, $mutes, $bind);
        });
    }

    public function testGateAndCompressorCurvesArePlotted(): void
    {
        $page = self::page();
        // One large curve per page, which follows the strips: every strip names the channel it plots
        foreach (['gate' => ['gate', 'inputs.0', 8], 'comp' => ['comp', 'outputs.0', 9]] as $name => [$kind, $first, $strips]) {
            $plot = $page->filter("section[data-page=$name] [data-controller=dynamics]");
            self::assertCount(1, $plot, $name);
            self::assertSame($kind, $plot->attr('data-dynamics-kind-value'));
            self::assertSame($first, $plot->attr('data-dynamics-path-value'));
            self::assertSame('true', $plot->attr('data-dynamics-follow-value'));
            self::assertCount(1, $plot->filter('.dynamics-panel canvas[data-dynamics-target=canvas]'));
            $sources = $plot->filter('.strip[data-dynamics-source]')->each(static fn (Crawler $n): string => (string) $n->attr('data-dynamics-source'));
            self::assertCount($strips, $sources, $name);
            foreach ($sources as $source) {
                self::assertTrue(self::has($source.'.'.$kind), $source);
            }
        }
        self::assertSame('aux', $page->filter('section[data-page=comp] .strip[data-dynamics-source]')->last()->attr('data-dynamics-source'));
        // A small curve in the Gate group of every input tab and the Comp group of every output tab
        for ($i = 0; $i < 8; ++$i) {
            self::assertSame(["inputs.$i"], $page->filter("section[data-page=inputs$i] fieldset[data-collapse-key-value=\"channel.gate\"] [data-dynamics-kind-value=gate]")->each(static fn (Crawler $n): string => (string) $n->attr('data-dynamics-path-value')));
            self::assertSame(["outputs.$i"], $page->filter("section[data-page=outputs$i] fieldset[data-collapse-key-value=\"channel.comp\"] [data-dynamics-kind-value=comp]")->each(static fn (Crawler $n): string => (string) $n->attr('data-dynamics-path-value')));
        }
        // The Aux compressor on its tab and beside the faders of its dialog, as in the vendor's Compressor Setting
        self::assertCount(1, $page->filter('section[data-page=aux] fieldset[data-collapse-key-value="aux.comp"] [data-controller=dynamics][data-dynamics-path-value=aux]'));
        self::assertCount(1, $page->filter('dialog#dialog-auxcomp .dynamics-row [data-controller=dynamics][data-dynamics-path-value=aux]'));
        // A view, never a write to the device
        self::assertCount(0, $page->filter('.dynamics-panel [data-fader-send-value], .dynamics [data-fader-send-value]'));
    }

    public function testEqOverviewHasASwitchPerChannelAndSendsNothing(): void
    {
        $overview = self::page()->filter('section[data-page=gain] [data-controller~=eqoverview]');
        self::assertCount(1, $overview);
        $paths = $overview->filter('[data-eqoverview-target=show]')->each(static fn (Crawler $n): string => (string) $n->attr('value'));
        // Every input and output, then the Aux
        self::assertCount(17, $paths);
        self::assertSame('aux', end($paths));
        foreach ($paths as $path) {
            self::assertTrue(self::has($path.'.peq'), $path);
        }
        // Mag and Phase as on a channel tab, two switches that can both be on
        $view = $overview->filter('.peq__bar input[type=checkbox]');
        self::assertSame(['mag', 'phase'], $view->each(static fn (Crawler $n): string => (string) $n->attr('data-eqoverview-target')));
        self::assertNotNull($view->eq(0)->attr('checked'));
        self::assertNull($view->eq(1)->attr('checked'));
        // Pointing at a curve or at its switch picks it out, and leaving lets go
        self::assertSame('pointermove->eqoverview#hoverPlot pointerleave->eqoverview#unhover', $overview->filter('canvas')->attr('data-action'));
        // One handler on the switches, so a link group's whole block points at its curves
        self::assertSame('pointerover->eqoverview#hoverSwitch pointerleave->eqoverview#unhover', $overview->filter('.eq-overview__switches')->attr('data-action'));
        self::assertCount(0, $overview->filter('.eq-overview__switch[data-action]'));
        // Showing a curve is a view setting, never a write to the device
        self::assertCount(0, $overview->filter('[data-toggle-send-value], [data-fader-send-value]'));
    }

    public function testEveryChannelCurveShowsMagnitudeAndPhaseIndependently(): void
    {
        $page = self::page();
        $peqs = $page->filter('[data-controller=peq]');
        // 8 inputs, 8 outputs and the Aux
        self::assertCount(17, $peqs);
        $peqs->each(static function (Crawler $peq): void {
            $view = $peq->filter('.peq__bar input[data-peq-target=mag], .peq__bar input[data-peq-target=phase]');
            self::assertSame(['checkbox', 'checkbox'], $view->each(static fn (Crawler $n): string => (string) $n->attr('type')));
            self::assertSame(['mag', 'phase'], $view->each(static fn (Crawler $n): string => (string) $n->attr('data-peq-target')));
            self::assertSame(['peq#toggleView', 'peq#toggleView'], $view->each(static fn (Crawler $n): string => (string) $n->attr('data-action')));
        });
        self::assertCount(0, $page->filter('input[type=radio][name^=view-]'));
    }

    public function testEveryControlNamesTheFilterItEditsOrPointsAtForTheSingleCurve(): void
    {
        self::page()->filter('[data-controller=peq]')->each(static function (Crawler $peq): void {
            self::assertSame('focusin->peq#focus focusout->peq#blur pointerover->peq#hoverControl pointerleave->peq#unhover pointerdown->peq#press', $peq->attr('data-action'));
            self::assertStringContainsString('wheel->peq#wheel', (string) $peq->filter('canvas.peq__curve')->attr('data-action'));
            self::assertStringEndsWith('pointerleave->peq#unhover', (string) $peq->filter('canvas.peq__curve')->attr('data-action'));
            // peq#hoverControl finds a band by its row, a filter by its group's data-kind
            self::assertCount(\count($peq->filter('.peq__table tbody tr')), $peq->filter('tr[data-peq-target=row]'));
            $peq->filter('.group--filter')->each(static fn (Crawler $group) => self::assertCount(1, $group->filter('[data-kind]')));
            // peq#focus tells the filter by the band or kind param, or by the selected band's fader target
            $bands = $peq->filter('.peq__table input, .peq__table select, .peq__table button')->each(static fn (Crawler $n): string => (string) $n->attr('data-peq-band-param'));
            self::assertNotEmpty($bands);
            foreach ($bands as $band) {
                self::assertMatchesRegularExpression('/^\d$/', $band);
            }
            $kinds = $peq->filter('.group--filter input[data-peq-target], .group--filter select[data-peq-target], .group--filter button[data-peq-target]')->each(static fn (Crawler $n): string => (string) $n->attr('data-peq-kind-param'));
            foreach ($kinds as $kind) {
                self::assertContains($kind, ['hpf', 'lpf']);
            }
            self::assertSame(['paramFreq', 'paramQ', 'paramGain'], $peq->filter('.group--params input')->each(static fn (Crawler $n): string => (string) $n->attr('data-peq-target')));
        });
    }

    public function testChannelSectionsFollowTheLinkPlacement(): void
    {
        $page = self::page();
        $placed = [
            'section[data-page=gain] .strips[data-collapse-key-value="gain.input"]' => 1,
            'section[data-page=gain] .strips[data-collapse-key-value="gain.output"]' => 1,
            'section[data-page=gain] .eq-overview__side' => 2,
            'section[data-page=gain] .strip' => 16 + 3 + 8,
            'section[data-page=gate] .strips' => 1,
            'section[data-page=comp] .strips' => 1,
            'section[data-page=delay] .strips' => 1,
            'section[data-page=matrix] table.matrix' => 2,
            'section[data-page=aux] .aux-routes__column' => 2,
            'section[data-page=autoeq] .checks' => 1,
        ];
        foreach ($placed as $selector => $count) {
            self::assertCount($count, $page->filter($selector), $selector);
            $page->filter($selector)->each(static function (Crawler $node) use ($selector): void {
                if (str_ends_with($selector, '.strip') && $node->filter('.aux-routes')->count() > 0) {
                    self::assertCount(2, $node->filter('.aux-routes__column[data-controller=linkplace]'));

                    return;
                }
                if (str_ends_with($selector, '.strip') && null === $node->filter('.toggle--route')->getNode(0)) {
                    return;
                }
                self::assertContains('linkplace', explode(' ', (string) $node->attr('data-controller')), $selector);
            });
        }
        // The Link dialog shows the groups being edited, so its channel labels leave out the groups on the device
        self::assertCount(32, $page->filter('dialog#dialog-link .ch[data-channel-link-value=false]'));
        // The name fields are built per link group, the hint stands in while there is none
        self::assertCount(1, $page->filter('dialog#dialog-link [data-editor-target=linkNames]'));
        self::assertCount(1, $page->filter('dialog#dialog-link [data-editor-target=linkNamesEmpty]'));
    }

    public function testMatrixPageCarriesTheRoutingGraph(): void
    {
        $graph = self::page()->filter('section[data-page=matrix] .matrix-page > [data-controller~=routing]');
        self::assertCount(1, $graph);
        self::assertSame('matrix.graph', $graph->attr('data-collapse-key-value'));
        // The matrix folds on its own, with a title as the graph has
        self::assertCount(1, self::page()->filter('section[data-page=matrix] .matrix-wrap.fold-panel[data-collapse-key-value="matrix.matrix"] > .fold-panel__title [data-collapse-target=toggle]'));
        self::assertCount(1, $graph->filter('svg[data-routing-target=svg]'));
        // The graph only shows the routing, the matrix sets it
        self::assertCount(0, $graph->filter('[data-toggle-send-value], [data-fader-send-value]'));
    }

    public function testMatrixCellsAndHeadersNameTheirRoutingForTheHoverFocus(): void
    {
        $page = self::page()->filter('section[data-page=matrix] .matrix-page');
        self::assertSame('routefocus', $page->attr('data-controller'));
        self::assertSame('pointerover->routefocus#over pointerleave->routefocus#leave routing:rendered->routefocus#reapply', $page->attr('data-action'));
        $page->filter('table.matrix')->each(static function (Crawler $table): void {
            // Every cell is one connection, its source and output match the bit and path of its toggle
            $table->filter('td[data-source]')->each(static function (Crawler $cell): void {
                $toggle = $cell->filter('[data-controller~=toggle]');
                self::assertSame((string) $toggle->attr('data-toggle-bit-value'), $cell->attr('data-source'));
                self::assertSame('8' === $cell->attr('data-output') ? 'aux.select' : 'outputs.'.$cell->attr('data-output').'.route', $toggle->attr('data-toggle-bind-value'));
            });
            self::assertCount(81, $table->filter('td'));
            self::assertCount(1, $table->filter('td button.matrix__unsupported[disabled]'));
            // Aux appears as both source and destination; its unsupported self-cell has no focus attributes
            self::assertCount(9, $table->filter('th[data-source]'));
            self::assertCount(9, $table->filter('th[data-output]'));
        });
    }

    public function testChannelPagesCarryTheirGateCompAndDelay(): void
    {
        $page = self::page();
        $binds = static fn (Crawler $section, string $panel): array => $section
            ->filter("fieldset[data-collapse-key-value=\"channel.$panel\"] [data-fader-bind-value]")
            ->each(static fn (Crawler $n): string => (string) $n->attr('data-fader-bind-value'));
        for ($i = 0; $i < 8; ++$i) {
            $input = $page->filter("section[data-page=inputs$i]");
            self::assertSame(["inputs.$i.gate.threshold", "inputs.$i.gate.attack", "inputs.$i.gate.release"], $binds($input, 'gate'));
            self::assertCount(0, $input->filter('fieldset[data-collapse-key-value="channel.comp"], fieldset[data-collapse-key-value="channel.delay"]'));
            $output = $page->filter("section[data-page=outputs$i]");
            $comp = array_map(static fn (string $v): string => "outputs.$i.comp.$v", ['threshold', 'ratio', 'attack', 'release']);
            self::assertSame($comp, $binds($output, 'comp'));
            self::assertSame(["outputs.$i.delay"], $binds($output, 'delay'));
            self::assertCount(1, $output->filter('fieldset[data-collapse-key-value="channel.delay"] select[data-choice-bind-value=delay_unit]'));
            self::assertCount(0, $output->filter('fieldset[data-collapse-key-value="channel.gate"]'));
            // The Gain group keeps the channel's only meter
            self::assertCount(1, $page->filter("section[data-page=inputs$i] [data-controller=meter]"));
            self::assertCount(1, $output->filter('[data-controller=meter]'));
        }
    }

    public function testCompPageEndsWithTheAuxCompressor(): void
    {
        $strips = self::page()->filter('section[data-page=comp] .strip');
        self::assertCount(9, $strips);
        $aux = $strips->last();
        self::assertSame('18', $aux->filter('.ch')->attr('data-channel-index-value'));
        $binds = $aux->filter('[data-fader-bind-value]')->each(static fn (Crawler $n): string => (string) $n->attr('data-fader-bind-value'));
        self::assertSame(['aux.comp.threshold', 'aux.comp.ratio', 'aux.comp.attack', 'aux.comp.release'], $binds);
        self::assertSame('16', $aux->filter('[data-controller=meter]')->attr('data-meter-index-value'));
        self::assertCount(0, $aux->filter('[data-controller~=gainwarning], [data-fader-link-value]'));
    }

    public function testGateThresholdRunsFromMinus90ToPlus20Db(): void
    {
        // The vendor editor's gate threshold fader ends at +20.0 dB (tools/shots/gate_threshold_max.png)
        $ranges = self::page()->filter('[data-fader-bind-value$=".gate.threshold"] input[type=range]')
            ->each(static fn (Crawler $n): array => [$n->attr('min'), $n->attr('max')]);
        // Gate page and input tab of every input
        self::assertCount(16, $ranges);
        self::assertSame([['0', '220']], array_values(array_unique($ranges, \SORT_REGULAR)));
    }

    /**
     * The fader ranges of the vendor editor, from the range survey in tools/shots/ranges/: per bind path suffix the
     * expected [min, max] of every fader and how many there are.
     *
     * @return iterable<string, array{string, int, list<array{string, string}>}>
     */
    public static function vendorRanges(): iterable
    {
        // Comp page, output tabs and Aux: release from 1 ms (comp.png, aux_comp_min.png)
        yield 'output release' => ['outputs.%d.comp.release', 16, [['0', '2999']]];
        yield 'output attack' => ['outputs.%d.comp.attack', 16, [['0', '998']]];
        // The Aux compressor stops at 200 ms attack and 999 ms release (aux_comp_max.png), Comp page and dialog
        yield 'aux release' => ['aux.comp.release', 2, [['0', '998']]];
        yield 'aux attack' => ['aux.comp.attack', 2, [['0', '199']]];
        // AutoMix and Camera from -50 dB (automix_min.png, camera_min.png)
        yield 'automix threshold' => ['aux.automix.threshold', 1, [['80', '180']]];
        yield 'camera threshold' => ['aux.camera.threshold', 1, [['80', '180']]];
        // Echo delay up to 400 ms (effect_max.png)
        yield 'echo delay' => ['aux.effect.echo_delay', 1, [['0', '400']]];
    }

    /**
     * @param list<array{string, string}> $expected
     */
    #[DataProvider('vendorRanges')]
    public function testFadersSpanTheVendorRanges(string $path, int $count, array $expected): void
    {
        $pattern = '/^'.str_replace('%d', '\\d', preg_quote($path, '/')).'$/';
        $ranges = [];
        self::page()->filter('[data-controller=fader]')->each(static function (Crawler $fader) use ($pattern, &$ranges): void {
            if (preg_match($pattern, (string) $fader->attr('data-fader-bind-value'))) {
                $input = $fader->filter('input[type=range]');
                $ranges[] = [$input->attr('min'), $input->attr('max')];
            }
        });
        self::assertCount($count, $ranges);
        self::assertSame($expected, array_values(array_unique($ranges, \SORT_REGULAR)));
    }

    public function testEveryCompressorFaderTurnsRedWhileItsCompressorIsOff(): void
    {
        $faders = self::page()->filter('[data-controller=fader][data-fader-bind-value*=".comp."]');
        // Comp page (8 outputs and the Aux), the 8 output tabs and the Aux dialog with 4 faders each, the Aux page 1
        self::assertCount(18 * 4 + 1, $faders);
        $faders->each(static function (Crawler $fader): void {
            $bind = (string) $fader->attr('data-fader-bind-value');
            self::assertSame(substr($bind, 0, (int) strrpos($bind, '.')), $fader->attr('data-fader-comp-value'), $bind);
        });
        self::assertCount($faders->count(), self::page()->filter('[data-fader-comp-value]'));
    }

    public function testNoKneeControlButTheByteGoesBackAsHeld(): void
    {
        $page = self::page();
        self::assertCount(0, $page->filter('[data-fader-bind-value$=".knee"]'));
        $sends = $page->filter('[data-fader-bind-value$=".comp.threshold"]')->each(static fn (Crawler $n): string => (string) $n->attr('data-fader-send-value'));
        self::assertNotEmpty($sends);
        foreach ($sends as $send) {
            $command = json_decode($send, true, flags: \JSON_THROW_ON_ERROR)[0];
            self::assertSame('compressor', $command['command']);
            self::assertMatchesRegularExpression('/^@(outputs\.\d|aux)\.comp\.knee$/', $command['args']['knee']);
        }
    }

    public function testSubControlHasTheAuxBetweenInputAndOutput(): void
    {
        foreach (['section[data-page=gain] .strips[data-collapse-key-value="gain.sub"]', 'dialog#dialog-sub'] as $scope) {
            $strips = self::page()->filter("$scope .strip");
            self::assertCount(3, $strips, $scope);
            $aux = $strips->eq(1);
            self::assertSame('18', $aux->filter('.strip__title .ch')->attr('data-channel-index-value'), $scope);
            self::assertSame(['aux.gain'], $aux->filter('[data-fader-bind-value]')->each(static fn (Crawler $n): string => (string) $n->attr('data-fader-bind-value')));
            self::assertSame('16', $aux->filter('[data-controller=meter]')->attr('data-meter-index-value'));
            self::assertCount(1, $aux->filter('.toggle--mute[data-toggle-bind-value="aux.mute"]'));
            // The inputs that feed the Aux, as on the Aux page
            $select = $aux->filter('.toggle--route[data-toggle-bind-value="aux.select"]');
            self::assertSame(range(0, 7), $select->each(static fn (Crawler $n): int => (int) $n->attr('data-toggle-bit-value')));
            self::assertSame(range(0, 7), $select->each(static fn (Crawler $n): int => (int) $n->filter('.ch')->attr('data-channel-index-value')));
            self::assertCount(2, $aux->filter('.aux-routes__column[data-controller=linkplace]'));
            self::assertCount(0, $aux->filter('[data-fader-link-value], [data-toggle-link-value]'));
        }
    }

    public function testGainWarningButtonsNameTheGainTheySet(): void
    {
        $warnings = self::page()->filter('[data-controller~=gainwarning]');
        // One per output on the Comp page, and one on each output's channel page
        self::assertCount(16, $warnings);
        self::assertCount(8, self::page()->filter('section[data-page=comp] [data-controller~=gainwarning]'));
        for ($o = 0; $o < 8; ++$o) {
            $warning = self::page()->filter("section[data-page=outputs$o] [data-controller~=gainwarning]");
            self::assertCount(1, $warning);
            self::assertSame("outputs.$o", $warning->attr('data-gainwarning-path-value'));
        }
        $warnings->each(static function (Crawler $warning): void {
            self::assertCount(1, $warning->filter('[data-action="gainwarning#exact"] [data-gainwarning-target=exact]'));
            self::assertCount(1, $warning->filter('[data-action="gainwarning#keepLevel"] [data-gainwarning-target=keep]'));
            self::assertCount(1, $warning->filter('input[type=checkbox][data-gainwarning-target=keepThreshold]'));
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
                self::assertSame(1, preg_match('/^(?:[a-z]+(?::[a-z]+)?->)?([a-z]+)#([A-Za-z]+)$/', $action, $m), \sprintf('Malformed action "%s".', $action));
                self::assertArrayHasKey($m[1], $sources, \sprintf('Action "%s" names no controller on the page.', $action));
                self::assertMatchesRegularExpression(\sprintf('/^\s+(?:async\s+)?%s\(/m', $m[2]), $sources[$m[1]], \sprintf('Action "%s" has no method.', $action));
            }
        }
    }
}
