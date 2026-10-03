import { Controller } from '@hotwired/stimulus';
import { getLabels, store } from '../lib/device.js';
import { curveRuns, drawGrid, eqView, loadEqView, plotFor, prepare, response, saveEqView } from '../lib/eq.js';
import { readPref, writePref } from '../lib/prefs.js';

// One colour per channel number: an input draws a solid line, the output of the same number a dashed one.
// The Aux has a colour of its own and a dotted line
const COLORS = ['#ff5252', '#ffd740', '#e040fb', '#40c4ff', '#69f0ae', '#448aff', '#b0bec5', '#ffab40'];
const AUX_COLOR = '#ff4081';
const DASHES = { inputs: [], outputs: [6, 4], aux: [2, 3] };
const HIDDEN = 'eq-overview-hidden';
const VIEW = 'overview';

/**
 * The EQ curves of every input, every output and the Aux in one plot, as the vendor editor shows them on its Gain page.
 * Mag and Phase show the magnitude, the phase or both, as on a channel tab. The phase reads in degrees on the axis, and
 * with both shown it is the fainter, thinner curve of each channel.
 * A switch per channel shows or hides its curve. It only changes the plot, the EQ bypass of the channel
 * stays as it is (the curve itself is flat while the channel's EQ is bypassed, as on its tab).
 */
export default class extends Controller {
    static targets = ['canvas', 'show', 'mag', 'phase'];

    connect() {
        const hidden = readPref(HIDDEN, []);
        this.showTargets.forEach((box) => {
            box.checked = !hidden.includes(box.value);
            box.closest('label').style.setProperty('--curve', this.color(box.value));
        });
        const view = loadEqView(VIEW);
        if (this.hasMagTarget && this.hasPhaseTarget) {
            this.magTarget.checked = view.mag;
            this.phaseTarget.checked = view.phase;
        }
        this.signature = '';
        this.unsubscribe = store.subscribe(() => this.schedule());
        this.resize = new ResizeObserver(() => this.schedule(true));
        this.resize.observe(this.canvasTarget);
        this.schedule(true);
    }

    disconnect() {
        this.unsubscribe?.();
        this.resize?.disconnect();
    }

    toggle() {
        writePref(HIDDEN, this.showTargets.filter((box) => !box.checked).map((box) => box.value));
        this.schedule(true);
    }

    toggleView() {
        saveEqView(VIEW, this.view.mag, this.view.phase);
        this.schedule(true);
    }

    get view() {
        return this.hasMagTarget && this.hasPhaseTarget ? eqView(this.magTarget.checked, this.phaseTarget.checked) : eqView();
    }

    /** "inputs.3" → its colour, "aux" → the Aux colour */
    color(path) {
        return 'aux' === path ? AUX_COLOR : COLORS[Number(path.split('.')[1]) % COLORS.length];
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
        const shown = this.showTargets.filter((box) => box.checked).map((box) => box.value);
        const channels = shown.map((path) => [path, store.get(path)]).filter(([, channel]) => channel);
        const view = this.view;
        const signature = JSON.stringify([view, channels.map(([path, c]) => [path, c.peq, c.hpf, c.lpf, c.eq_bypass])]);
        if (signature === this.signature) {
            return;
        }
        const canvas = prepare(this.canvasTarget);
        if (null === canvas) {
            return;
        }
        this.signature = signature;
        const { g, width, height } = canvas;
        const plot = plotFor(view, width, height);
        drawGrid(g, plot, width, height, view);
        const slopes = getLabels().SLOPES ?? [];
        const n = Math.max(200, Math.round(plot.width / 2));
        const both = view.mag && view.phase;
        for (const [path, channel] of channels) {
            const points = Array.from({ length: n + 1 }, (_, i) => {
                const f = 20 * 1000 ** (i / n);
                return [plot.x(f), response(channel, f, slopes)];
            });
            g.strokeStyle = this.color(path);
            g.setLineDash(DASHES[path.split('.')[0]]);
            for (const kind of ['phase', 'mag'].filter((k) => view[k])) {
                // With both curves shown the phase is the fainter, thinner one
                g.globalAlpha = both && 'phase' === kind ? 0.5 : 1;
                g.lineWidth = both && 'phase' === kind ? 1 : 1.5;
                g.beginPath();
                for (const run of curveRuns(points, kind)) {
                    run.forEach(([x, v], i) => (0 === i ? g.moveTo(x, plot.y(v)) : g.lineTo(x, plot.y(v))));
                }
                g.stroke();
            }
        }
        g.globalAlpha = 1;
        g.setLineDash([]);
    }
}
