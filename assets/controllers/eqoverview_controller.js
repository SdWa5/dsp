import { Controller } from '@hotwired/stimulus';
import { getLabels, store } from '../lib/device.js';
import { drawGrid, Plot, prepare, response } from '../lib/eq.js';
import { readPref, writePref } from '../lib/prefs.js';

// One colour per channel number: an input draws a solid line, the output of the same number a dashed one
const COLORS = ['#ff5252', '#ffd740', '#e040fb', '#40c4ff', '#69f0ae', '#448aff', '#b0bec5', '#ffab40'];
const HIDDEN = 'eq-overview-hidden';

/**
 * The EQ curves of every input and output in one plot, as the vendor editor shows them on its Gain page.
 * A switch per channel shows or hides its curve. It only changes the plot, the EQ bypass of the channel
 * stays as it is (the curve itself is flat while the channel's EQ is bypassed, as on its tab).
 */
export default class extends Controller {
    static targets = ['canvas', 'show'];

    connect() {
        const hidden = readPref(HIDDEN, []);
        this.showTargets.forEach((box) => {
            box.checked = !hidden.includes(box.value);
            box.closest('label').style.setProperty('--curve', this.color(box.value));
        });
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

    /** "inputs.3" → its colour */
    color(path) {
        return COLORS[Number(path.split('.')[1]) % COLORS.length];
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
        const signature = JSON.stringify(channels.map(([path, c]) => [path, c.peq, c.hpf, c.lpf, c.eq_bypass]));
        if (signature === this.signature) {
            return;
        }
        const canvas = prepare(this.canvasTarget);
        if (null === canvas) {
            return;
        }
        this.signature = signature;
        const { g, width, height } = canvas;
        const plot = new Plot(48, 14, width - 60, height - 36);
        drawGrid(g, plot, width, height);
        const slopes = getLabels().SLOPES ?? [];
        const n = Math.max(200, Math.round(plot.width / 2));
        g.lineWidth = 1.5;
        for (const [path, channel] of channels) {
            g.strokeStyle = this.color(path);
            g.setLineDash(path.startsWith('outputs') ? [6, 4] : []);
            g.beginPath();
            for (let i = 0; i <= n; i += 1) {
                const f = 20 * 1000 ** (i / n);
                const [x, y] = [plot.x(f), plot.y(Math.max(-18.5, Math.min(18.5, response(channel, f, slopes).db)))];
                if (0 === i) {
                    g.moveTo(x, y);
                } else {
                    g.lineTo(x, y);
                }
            }
            g.stroke();
        }
        g.setLineDash([]);
    }
}
