import { t } from '../lib/i18n.js';
import { Controller } from '@hotwired/stimulus';
import { getLabels, store } from '../lib/device.js';
import { channelLabel } from '../lib/channels.js';
import { drawDynamics, dynamicsCurve } from '../lib/dynamics.js';
import { prepare } from '../lib/eq.js';

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
        this.resize = new ResizeObserver(() => this.schedule(true));
        this.resize.observe(this.canvasTarget);
        this.schedule(true);
    }

    disconnect() {
        this.unsubscribe?.();
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
