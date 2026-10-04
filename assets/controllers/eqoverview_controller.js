import { Controller } from '@hotwired/stimulus';
import { getLabels, store } from '../lib/device.js';
import { curveRuns, drawGrid, eqView, loadEqView, plotFor, prepare, response, saveEqView } from '../lib/eq.js';
import { getLinkView, onLinkView } from '../lib/linkview.js';
import { curveColourSource, linkedPaths, nearestCurve, overviewPlots } from '../lib/overview.js';
import { onPref } from '../lib/prefs.js';
import { overviewHidden, selectOverview, overviewSeparated, setOverviewSeparated } from '../lib/displayprefs.js';
import { getChannelView, onChannelView, channelVisible, selectionState } from '../lib/channelview.js';

// One colour per channel number: an input draws a solid line, the output of the same number a dashed one.
// The Aux has a colour of its own and a dotted line
const COLORS = ['#ff5252', '#ffd740', '#e040fb', '#40c4ff', '#69f0ae', '#448aff', '#b0bec5', '#ffab40'];
const AUX_COLOR = '#ff4081';
const DASHES = { inputs: [], outputs: [6, 4], aux: [2, 3] };
// A curve that is not the one pointed at, nor linked with it
const DIMMED = '#8a8a8a';
const VIEW = 'overview';

/**
 * The EQ curves of every input, output and Aux share one plot by default. A browser preference separates them
 * into three plots without changing channel selections. Hover geometry belongs to each canvas, never another plot.
 * Mag and Phase show the magnitude, the phase or both, as on a channel tab. The phase reads in degrees on the axis, and
 * with both shown it is the fainter, thinner curve of each channel.
 * A switch per channel, or one mixed-state switch per link group, shows or hides its curves. It only changes the plot, the EQ bypass of the channel
 * stays as it is (the curve itself is flat while the channel's EQ is bypassed, as on its tab).
 * Linked channels share one EQ, so their curves and switches take one colour, the group's while display settings
 * enable link colours, else the first member's. Pointing at a curve or a switch greys out every other curve and
 * switch, except the members of its link group.
 */
export default class extends Controller {
    static targets = ['canvas', 'plots', 'show', 'mag', 'phase', 'separated'];

    connect() {
        this.signature = '';
        this.hover = null;
        this.drawn = new Map();
        this.showPreferences();
        this.offPrefs = onPref(['eq-view', 'eq-overview-hidden', 'eq-overview-separated'], () => { this.showPreferences(); this.schedule(true); });
        this.unsubscribe = store.subscribe(() => this.schedule());
        this.offView = onLinkView(() => this.schedule(true));
        this.offDisplay = onChannelView(() => this.schedule(true));
        this.resize = new ResizeObserver(() => this.schedule(true));
        this.canvasTargets.forEach(canvas => this.resize.observe(canvas));
        this.schedule(true);
    }

    disconnect() {
        this.unsubscribe?.();
        this.offPrefs?.();
        this.offView?.();
        this.offDisplay?.();
        this.resize?.disconnect();
        if (this.frame) cancelAnimationFrame(this.frame);
        this.frame = null;
    }

    toggle(event) {
        const box = event.target;
        const paths = getChannelView().grouped ? linkedPaths(box.value, store.state) : [box.value];
        selectOverview(paths, box.checked);
        this.schedule(true);
    }

    showPreferences() {
        const separated = overviewSeparated();
        if (this.separated !== separated) {
            this.hover = null;
            this.drawn.clear();
        }
        this.separated = separated;
        this.separatedTarget.checked = separated;
        this.plotsTarget.classList.toggle('is-separated', separated);
        for (const pane of this.plotsTarget.children) {
            pane.hidden = (pane.dataset.overviewSide === 'combined') === separated;
        }
        this.hiddenPaths = overviewHidden();
        this.showTargets.forEach(box => { box.checked = !this.hiddenPaths.has(box.value); });
        const view = loadEqView(VIEW);
        if (this.hasMagTarget && this.hasPhaseTarget) {
            this.magTarget.checked = view.mag;
            this.phaseTarget.checked = view.phase;
        }
    }

    toggleView() {
        saveEqView(VIEW, this.view.mag, this.view.phase);
        this.schedule(true);
    }

    separate() { setOverviewSeparated(this.separatedTarget.checked); }

    get view() {
        return this.hasMagTarget && this.hasPhaseTarget ? eqView(this.magTarget.checked, this.phaseTarget.checked) : eqView();
    }

    /** "inputs.3" → its colour, "aux" → the Aux colour */
    color(path) {
        return 'aux' === path ? AUX_COLOR : COLORS[Number(path.split('.')[1]) % COLORS.length];
    }

    /** The pointer over the plot points at the nearest curve within a few pixels, or at none. */
    hoverPlot(event) {
        this.point(nearestCurve(this.drawn.get(event.currentTarget) ?? [], event.offsetX, event.offsetY));
    }

    /**
     * The pointer over a switch points at its curve, over a link group's block (its head, its border, the gaps
     * between its switches) at the group's curves. Anywhere else between the switches it points at none.
     */
    hoverSwitch(event) {
        const holder = event.target.closest('.eq-overview__switch') ?? event.target.closest('.link-block');
        this.point(holder?.querySelector('[data-eqoverview-target=show]')?.value ?? null);
    }

    unhover() {
        this.point(null);
    }

    point(path) {
        if (path === this.hover) {
            return;
        }
        this.hover = path;
        this.schedule();
    }

    /** Each switch's link group and colour, which the canvas then reads back so curve and switch match. */
    paintSwitches(state, colours) {
        const lit = null === this.hover ? null : linkedPaths(this.hover, state);
        const result = new Map();
        for (const box of this.showTargets) {
            const label = box.closest('label');
            const { group, lead } = curveColourSource(box.value, state);
            label.dataset.group = colours && group > 0 ? String(group) : '';
            const css = '' === label.dataset.group ? '' : getComputedStyle(label).getPropertyValue('--group').trim();
            const colour = '' === css ? this.color(lead) : css;
            label.style.setProperty('--curve', colour);
            label.classList.toggle('is-dimmed', null !== lit && !lit.includes(box.value));
            result.set(box.value, colour);
        }
        // A link group's block greys out with its switches
        for (const block of this.element.querySelectorAll('.link-block')) {
            const boxes = [...block.querySelectorAll('[data-eqoverview-target=show]')];
            block.classList.toggle('is-dimmed', null !== lit && !boxes.some((box) => lit.includes(box.value)));
        }

        return { colour: result, lit };
    }

    /** At most one drawing per frame, and none when no curve changed (the store also carries unrelated values). */
    schedule(force = false) {
        if (force) {
            this.signature = '';
        }
        if (!this.frame) {
            this.frame = requestAnimationFrame(() => {
                this.frame = null;
                this.draw();
            });
        }
    }

    draw() {
        const state = store.state;
        for (const box of this.showTargets) {
            const paths = getChannelView().grouped ? linkedPaths(box.value, state) : [box.value];
            const selection = selectionState(paths.map(path => !this.hiddenPaths.has(path)));
            box.checked = selection.selected; box.indeterminate = selection.mixed;
        }
        const shown = this.showTargets.map(box => box.value).filter(path => {
            const [side, raw] = path.split('.');
            return !this.hiddenPaths.has(path) && channelVisible(raw === undefined ? 18 : Number(raw) + (side === 'outputs' ? 8 : 0));
        });
        const channels = shown.map((path) => [path, store.get(path)]).filter(([, channel]) => channel);
        const view = this.view;
        const { colour, lit } = this.paintSwitches(state, getLinkView().colour);
        const signature = JSON.stringify([this.separated, view, this.hover, lit, [...colour], channels.map(([path, c]) => [path, c.peq, c.hpf, c.lpf, c.eq_bypass])]);
        if (signature === this.signature) {
            return;
        }
        // A collapsed or hidden page has zero-size canvases. Cache only after all visible plots were drawn.
        const plots = overviewPlots(channels, this.separated);
        let complete = true;
        for (const { key, channels: selected } of plots) {
            const target = this.canvasTargets.find(canvas => canvas.dataset.overviewSide === key);
            target.parentElement.querySelector('.eq-overview__empty').hidden = selected.length > 0 && (view.mag || view.phase);
            complete = this.drawCanvas(target, selected, view, colour, lit) && complete;
        }
        if (complete) this.signature = signature;
    }

    /** Draw and retain hit-test coordinates in this canvas's CSS pixels. */
    drawCanvas(target, channels, view, colour, lit) {
        const canvas = prepare(target);
        if (null === canvas) {
            return false;
        }
        const { g, width, height } = canvas;
        const plot = plotFor(view, width, height);
        drawGrid(g, plot, width, height, view);
        const slopes = getLabels().SLOPES ?? [];
        const n = Math.max(200, Math.round(plot.width / 2));
        const both = view.mag && view.phase;
        const curves = channels.map(([path, channel]) => {
            const points = Array.from({ length: n + 1 }, (_, i) => {
                const f = 20 * 1000 ** (i / n);
                return [plot.x(f), response(channel, f, slopes)];
            });
            const runs = ['phase', 'mag'].filter((k) => view[k]).map((kind) => [kind, curveRuns(points, kind).map((run) => run.map(([x, v]) => [x, plot.y(v)]))]);
            return { path, runs, dimmed: null !== lit && !lit.includes(path) };
        });
        // The greyed curves go below, so the ones pointed at stay readable on top
        curves.sort((a, b) => Number(b.dimmed) - Number(a.dimmed));
        for (const { path, runs, dimmed } of curves) {
            g.strokeStyle = dimmed ? DIMMED : colour.get(path);
            g.setLineDash(DASHES[path.split('.')[0]]);
            for (const [kind, lines] of runs) {
                // With both curves shown the phase is the fainter, thinner one
                g.globalAlpha = (both && 'phase' === kind ? 0.5 : 1) * (dimmed ? 0.4 : 1);
                g.lineWidth = both && 'phase' === kind ? 1 : 1.5;
                g.beginPath();
                for (const line of lines) {
                    line.forEach(([x, y], i) => (0 === i ? g.moveTo(x, y) : g.lineTo(x, y)));
                }
                g.stroke();
            }
        }
        g.globalAlpha = 1;
        g.setLineDash([]);
        this.drawn.set(target, curves.map(({ path, runs }) => ({ path, runs: runs.flatMap(([, lines]) => lines) })));
        return true;
    }
}
