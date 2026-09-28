import BoundController from '../lib/bound_controller.js';
import { isChanged } from '../lib/defaults.js';
import { getDefaults } from '../lib/device.js';

/**
 * A button for a boolean, or with bit >= 0 for one bit of a mask (matrix, source selection).
 * text-on replaces the label while active (Normal/Inverse).
 */
export default class extends BoundController {
    static values = { ...BoundController.values, bit: { type: Number, default: -1 }, textOn: String, textOff: String };

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
        const on = this.bitValue >= 0 ? Boolean(((value ?? 0) >> this.bitValue) & 1) : Boolean(value);
        this.element.classList.toggle('is-on', on);
        this.element.classList.toggle('is-unknown', null === value);
        this.element.setAttribute('aria-pressed', on ? 'true' : 'false');
        this.element.title = null === value ? 'Unknown: the device does not report this value' : '';
        if (this.textOnValue) {
            this.element.textContent = on ? this.textOnValue : this.textOffValue;
        }
    }
}
