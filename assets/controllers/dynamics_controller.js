import { t } from '../lib/i18n.js';
import { Controller } from '@hotwired/stimulus';
import { getLabels, store } from '../lib/device.js';
import { channelLabel } from '../lib/channels.js';
import { drawDynamics, dynamicsCurve } from '../lib/dynamics.js';
import { prepare } from '../lib/eq.js';
import { onChannelView, displayedChannel, representative } from '../lib/channelview.js';

/**
 * The curve of a gate (kind gate, path an input) or compressor (kind comp, path an output or the Aux), input level
 * against output level. With follow, the plot shows the channel of the strip last touched: every element with
 * data-dynamics-source="outputs.3" inside the controller names its channel, and the plotted one gets is-plotted.
 */
export default class extends Controller {
    static targets = ['canvas'];
    static values = { kind: String, path: String, follow: Boolean };

    connect() {
        this.signature = '';
        this.unsubscribe = store.subscribe(() => this.schedule());
        this.offDisplay = onChannelView(() => this.schedule(true));
        this.resize = new ResizeObserver(() => this.schedule(true));
        this.resize.observe(this.canvasTarget);
        this.schedule(true);
    }

    disconnect() {
        this.unsubscribe?.();
        this.offDisplay?.();
        this.resize?.disconnect();
        cancelAnimationFrame(this.frame);
    }

    /** focusin or pointerdown inside a strip: plot its channel. */
    select(event) {
        const source = this.followValue ? event.target.closest('[data-dynamics-source]') : null;
        if (source && source.dataset.dynamicsSource !== this.pathValue) {
            this.pathValue = source.dataset.dynamicsSource;
        }
    }

    pathValueChanged() {
        for (const source of this.element.querySelectorAll('[data-dynamics-source]')) {
            source.classList.toggle('is-plotted', source.dataset.dynamicsSource === this.pathValue);
        }
        this.schedule(true);
    }

    /** At most one drawing per frame, and none while the curve stays the same. */
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
        if (this.followValue) {
            const [side, raw] = this.pathValue.split('.');
            const index = raw === undefined ? 18 : Number(raw) + (side === 'outputs' ? 8 : 0);
            if (!displayedChannel(index, store.state)) {
                const same = representative(index, store.state);
                const base = this.kindValue === 'gate' ? 0 : 8;
                const candidates = Array.from({ length: 8 }, (_, i) => base + i);
                if (this.kindValue === 'comp') candidates.push(18);
                const next = same ?? candidates.find(i => displayedChannel(i, store.state));
                if (next !== undefined && next !== null) this.pathValue = next === 18 ? 'aux' : `${base ? 'outputs' : 'inputs'}.${next - base}`;
                else {
                    const message = t(this.kindValue === 'gate' ? 'No visible input channels.' : 'No visible compressor channels.');
                    const canvas = prepare(this.canvasTarget);
                    if (canvas) { canvas.g.clearRect(0, 0, canvas.width, canvas.height); canvas.g.fillStyle = '#aaa'; canvas.g.fillText(message, 12, 24); }
                    this.canvasTarget.setAttribute('aria-label', message);
                    this.signature = '';
                    return;
                }
            }
        }
        const dynamics = store.get(`${this.pathValue}.${this.kindValue}`);
        if (!dynamics) {
            return;
        }
        const title = this.title();
        const signature = JSON.stringify([title, dynamics.threshold, dynamics.ratio]);
        if (signature === this.signature) {
            return;
        }
        const canvas = prepare(this.canvasTarget);
        if (null === canvas) {
            return;
        }
        this.signature = signature;
        drawDynamics(canvas.g, canvas.width, canvas.height, dynamicsCurve(this.kindValue, dynamics, getLabels().COMP_RATIOS ?? []), title);
        this.canvasTarget.setAttribute('aria-label', title);
    }

    title() {
        const [side, index] = this.pathValue.split('.');
        const channel = channelLabel('inputs' === side ? Number(index) : 'outputs' === side ? 8 + Number(index) : 18, store.state);
        return 'gate' === this.kindValue ? t('%channel% Gate', { channel }) : t('%channel% Compressor', { channel });
    }
}
