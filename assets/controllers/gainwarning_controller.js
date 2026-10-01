import { Controller } from '@hotwired/stimulus';
import { send, store } from '../lib/device.js';
import { EXACT_RATIO, EXACT_THRESHOLD, exaggeratesGain } from '../lib/scale.js';

/**
 * Warns on an output strip while the output runs at compressor 1:1.0 with a gain other than 0 dB, and offers
 * 1:1.1 from +20 dB, which keeps the gain exact. Values: path ("outputs.3"), channel (11).
 */
export default class extends Controller {
    static values = { path: String, channel: Number };

    connect() {
        this.unsubscribe = store.subscribe(() => this.render());
        this.render();
    }

    disconnect() {
        this.unsubscribe?.();
    }

    render() {
        this.element.hidden = !exaggeratesGain(store.get(this.pathValue));
    }

    exact() {
        const comp = store.get(`${this.pathValue}.comp`);
        if (!comp) {
            return;
        }
        store.set(`${this.pathValue}.comp.ratio`, EXACT_RATIO);
        store.set(`${this.pathValue}.comp.threshold`, EXACT_THRESHOLD);
        store.notify();
        send([{ command: 'compressor', args: { ...comp, channel: this.channelValue, ratio: EXACT_RATIO, threshold: EXACT_THRESHOLD } }]);
    }
}
