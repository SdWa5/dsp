import { Controller } from '@hotwired/stimulus';
import { send, store } from '../lib/device.js';
import { EXACT_RATIO, EXACT_THRESHOLD, exaggeratesGain, format, heardGain } from '../lib/scale.js';

/**
 * Warns on an output strip while the output runs at compressor 1:1.0 with a gain other than 0 dB, and offers
 * 1:1.1 from +20 dB, which keeps the gain exact: either with the gain as set ("Exact gain"), or with the gain
 * moved to the level the output had at 1:1.0 ("Keep level"). Each button names the gain it sets.
 * Values: path ("outputs.3"), channel (11).
 */
export default class extends Controller {
    static values = { path: String, channel: Number };
    static targets = ['exact', 'keep'];

    connect() {
        this.unsubscribe = store.subscribe(() => this.render());
        this.render();
    }

    disconnect() {
        this.unsubscribe?.();
    }

    render() {
        const output = store.get(this.pathValue);
        this.element.hidden = !exaggeratesGain(output);
        if (this.element.hidden) {
            return;
        }
        this.exactTarget.textContent = `(${format('gain', output.gain)})`;
        this.keepTarget.textContent = `(${format('gain', heardGain(output.gain))})`;
    }

    exact() {
        this.fix(false);
    }

    keepLevel() {
        this.fix(true);
    }

    fix(keepLevel) {
        const comp = store.get(`${this.pathValue}.comp`);
        const gain = store.get(`${this.pathValue}.gain`);
        if (!comp || undefined === gain) {
            return;
        }
        const commands = [];
        if (keepLevel) {
            // The gain first, so the level never jumps up between the two writes
            const raw = heardGain(gain);
            store.set(`${this.pathValue}.gain`, raw);
            commands.push({ command: 'gain', args: { channel: this.channelValue, raw } });
        }
        store.set(`${this.pathValue}.comp.ratio`, EXACT_RATIO);
        store.set(`${this.pathValue}.comp.threshold`, EXACT_THRESHOLD);
        store.notify();
        commands.push({ command: 'compressor', args: { ...comp, channel: this.channelValue, ratio: EXACT_RATIO, threshold: EXACT_THRESHOLD } });
        send(commands, false);
    }
}
