import BoundController from '../lib/bound_controller.js';
import { getLabels, store } from '../lib/device.js';
import { parse } from '../lib/scale.js';

/**
 * A slider with a value field. Type a value into the field ("-6", "200ms", "1:4"), or double-click the
 * slider to go back to the default value.
 */
export default class extends BoundController {
    static targets = ['input', 'label'];
    static values = { ...BoundController.values, default: Number };

    connect() {
        this.inputTarget.addEventListener('pointerdown', () => { this.busy = true; });
        window.addEventListener('pointerup', this.release = () => { this.busy = false; });
        super.connect();
    }

    disconnect() {
        window.removeEventListener('pointerup', this.release);
        super.disconnect();
    }

    input() {
        const value = Number(this.inputTarget.value);
        this.labelTarget.value = this.text(value);
        this.commit(value);
    }

    /** Mouse wheel moves one step. */
    wheel(event) {
        if (this.inputTarget.disabled) {
            return;
        }
        event.preventDefault();
        this.set(Number(this.inputTarget.value) + (event.deltaY < 0 ? 1 : -1));
    }

    reset() {
        if (this.hasDefaultValue) {
            this.set(this.defaultValue);
        }
    }

    typed() {
        const value = parse(this.fmtValue, this.labelTarget.value, store.state, getLabels());
        if (null === value) {
            this.labelTarget.value = this.text(this.current);
            return;
        }
        this.set(value);
    }

    key(event) {
        if ('Enter' === event.key) {
            this.labelTarget.blur();
        } else if ('Escape' === event.key) {
            this.labelTarget.value = this.text(this.current);
            this.labelTarget.blur();
        }
    }

    focus() {
        this.labelTarget.select();
    }

    blur() {
        this.labelTarget.value = this.text(this.current);
    }

    set(value) {
        const min = Number(this.inputTarget.min);
        const max = Number(this.inputTarget.max);
        this.inputTarget.value = Math.max(min, Math.min(max, value));
        this.input();
    }

    render(value) {
        if (null !== value && undefined !== value) {
            this.inputTarget.value = value;
        }
        if (document.activeElement !== this.labelTarget) {
            this.labelTarget.value = this.text(value);
        }
    }
}
