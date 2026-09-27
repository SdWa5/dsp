import BoundController from '../lib/bound_controller.js';

/** A text field, written on change (channel names). */
export default class extends BoundController {
    change() {
        const value = this.element.value.trim();
        if ('' !== value && value !== this.current) {
            this.commit(value);
        }
    }

    render(value) {
        if (document.activeElement !== this.element) {
            this.element.value = value ?? '';
        }
    }
}
