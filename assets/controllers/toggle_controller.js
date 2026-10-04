import { t } from '../lib/i18n.js';
import BoundController from '../lib/bound_controller.js';
import { isChanged } from '../lib/defaults.js';
import { getDefaults, store } from '../lib/device.js';
import { GAIN_MUTED } from '../lib/scale.js';

/**
 * A button for a boolean, or with bit >= 0 for one bit of a mask (matrix, source selection).
 * text-on replaces the label while active (Normal/Inverse). muted-by (a gain path) lights a Mute button while that
 * gain sits at raw 0, which mutes the channel as well. That only shows, the mute flag stays as it is. The title
 * the page renders (the tooltip) stays, a state message goes above it.
 */
export default class extends BoundController {
    static values = { ...BoundController.values, bit: { type: Number, default: -1 }, textOn: String, textOff: String, mutedBy: String };

    click() {
        const current = this.current;
        if (this.bitValue >= 0) {
            this.commit((current ?? 0) ^ (1 << this.bitValue));
        } else {
            this.commit(!current);
        }
    }

    changed(state) {
        return isChanged(state, getDefaults(), this.bindValue, this.bitValue);
    }

    render(value) {
        const byGain = '' !== this.mutedByValue && GAIN_MUTED === store.get(this.mutedByValue);
        const own = this.bitValue >= 0 ? Boolean(((value ?? 0) >> this.bitValue) & 1) : Boolean(value);
        const on = byGain || own;
        this.element.classList.toggle('is-on', on);
        // Muted by the gain alone: outlined, so it reads apart from a pressed Mute button
        this.element.classList.toggle('is-gain', byGain && !own);
        this.element.classList.toggle('is-unknown', null === value);
        this.element.setAttribute('aria-pressed', on ? 'true' : 'false');
        // A state message goes on a line of its own above the tooltip from the page
        this.tip ??= this.element.title;
        const status = null === value ? t("Unknown: the device does not report this value") : byGain ? t("Muted by its gain at the lowest step") : '';
        this.element.title = [status, this.tip].filter(Boolean).join('\n');
        if (this.textOnValue) {
            this.element.textContent = on ? this.textOnValue : this.textOffValue;
        }
    }
}
