import BoundController from '../lib/bound_controller.js';
import { send, store } from '../lib/device.js';

/**
 * A button that sets several values at once and sends the commands, e.g. the DJ compressor. It is lit
 * while the state holds exactly these values. Values: set ({path: raw value}), send.
 */
export default class extends BoundController {
    static values = { ...BoundController.values, set: Object };

    apply() {
        if (null === store.state) {
            return;
        }
        for (const [path, value] of Object.entries(this.setValue)) {
            store.set(path, value);
        }
        store.notify();
        send(this.sendValue.map((template) => this.resolve(template, null, 0, null)));
    }

    render() {
        const on = Object.entries(this.setValue).every(([path, value]) => store.get(path) === value);
        this.element.classList.toggle('is-on', on);
        this.element.setAttribute('aria-pressed', on ? 'true' : 'false');
    }
}
