import { t } from '../lib/i18n.js';
import BoundController from '../lib/bound_controller.js';
import { getDefaults, getLabels, store } from '../lib/device.js';
import { compActive } from '../lib/meters.js';
import { faderDefault, knobHollow, knobMuted, paintRange } from '../lib/range.js';
import { parse } from '../lib/scale.js';

/**
 * A slider with a value field. Type a value into the field ("-6", "200ms", "1:4"), or double-click the
 * slider to go back to the default value. A gain fader's knob turns red while its gain sits at raw 0, the bottom,
 * or while the mute named by muted-by is set, the way a gain at raw 0 lights the Mute button. A compressor fader's
 * knob turns red while the compressor named by comp is off (ratio 1:1.0 or threshold +20 dB), a delay fader's while
 * it is at 0. The track keeps its colour.
 */
export default class extends BoundController {
    static targets = ['input', 'label'];
    static values = { ...BoundController.values, default: Number, mutedBy: String, comp: String };

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
        if (this.inputTarget.disabled) return;
        const value = faderDefault(getDefaults(), this.bindValue, Number(this.inputTarget.min),
            Number(this.inputTarget.max), this.hasDefaultValue ? this.defaultValue : undefined);
        if (null !== value) this.set(value);
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

    /** The filled part of the track, and the red knob of a muted gain: filled for its Mute button, a ring for its lowest step. */
    paint() {
        paintRange(this.inputTarget);
        const mute = '' !== this.mutedByValue && true === store.get(this.mutedByValue);
        const comp = '' !== this.compValue ? store.get(this.compValue) : null;
        // No state yet is not an off compressor
        const off = null !== comp && 'object' === typeof comp && !compActive(comp);
        this.element.classList.toggle('is-muted', off || knobMuted(this.fmtValue, Number(this.inputTarget.value), mute));
        this.element.classList.toggle('is-muted-gain', !off && knobHollow(this.fmtValue, Number(this.inputTarget.value), mute));
        if ('' !== this.mutedByValue || '' !== this.compValue || 'delay' === this.fmtValue) {
            // A state message goes on a line of its own above the tooltip from the page
            this.tip ??= this.element.title;
            const none = 'delay' === this.fmtValue && 0 === Number(this.inputTarget.value);
            const state = mute ? t("Muted by its Mute button") : off ? t("Compressor off: ratio 1:1.0 or threshold +20 dB") : none ? t("No delay") : '';
            this.element.title = [state, this.tip].filter(Boolean).join('\n');
        }
    }
}
