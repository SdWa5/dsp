import { t } from '../lib/i18n.js';
import BoundController from '../lib/bound_controller.js';
import { getLabels, store } from '../lib/device.js';
import { knobMuted, paintRange } from '../lib/range.js';
import { parse } from '../lib/scale.js';

/**
 * A slider with a value field. Type a value into the field ("-6", "200ms", "1:4"), or double-click the
 * slider to go back to the default value. A gain fader's knob turns red while its gain sits at raw 0, the bottom,
 * or while the mute named by muted-by is set, the way a gain at raw 0 lights the Mute button. The track keeps its
 * colour.
 */
export default class extends BoundController {
    static targets = ['input', 'label'];
    static values = { ...BoundController.values, default: Number, mutedBy: String };

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
        this.paint();
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
        this.paint();
        if (document.activeElement !== this.labelTarget) {
            this.labelTarget.value = this.text(value);
        }
    }

    /** The filled part of the track, and the red knob of a muted gain. */
    paint() {
        paintRange(this.inputTarget);
        const mute = '' !== this.mutedByValue && true === store.get(this.mutedByValue);
        this.element.classList.toggle('is-muted', knobMuted(this.fmtValue, Number(this.inputTarget.value), mute));
        if ('' !== this.mutedByValue) {
            // A state message goes on a line of its own above the tooltip from the page
            this.tip ??= this.element.title;
            this.element.title = [mute ? t("Muted by its Mute button") : '', this.tip].filter(Boolean).join('\n');
        }
    }
}
