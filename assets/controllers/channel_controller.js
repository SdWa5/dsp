import { Controller } from '@hotwired/stimulus';
import { store } from '../lib/device.js';
import { channelLabel, channelParts } from '../lib/channels.js';

/**
 * A channel ID with the channel's name next to it: "In1 DJ L". In an <option> as plain text "In1 · DJ L".
 * Value: index (0..7 In, 8..15 Out, 18 Aux).
 */
export default class extends Controller {
    static values = { index: Number };

    connect() {
        this.shown = null;
        this.off = store.subscribe((state) => this.render(state));
        this.render(store.state);
    }

    disconnect() {
        this.off?.();
    }

    render(state) {
        const { id, name } = channelParts(this.indexValue, state);
        const key = `${id}|${name}`;
        if (key === this.shown) {
            return;
        }
        this.shown = key;
        if (this.element.matches('option')) {
            this.element.textContent = channelLabel(this.indexValue, state);
            return;
        }
        const nameNode = this.element.querySelector('.ch__name');
        if (nameNode) {
            nameNode.textContent = name;
        }
        this.element.classList.toggle('is-named', '' !== name);
        this.element.title = channelLabel(this.indexValue, state);
    }
}
