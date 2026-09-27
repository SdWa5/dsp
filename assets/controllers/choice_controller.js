import BoundController from '../lib/bound_controller.js';

/** A <select> or a group of radio buttons for an integer. */
export default class extends BoundController {
    change(event) {
        this.commit(Number(event.target.value));
    }

    render(value) {
        const select = this.element.matches('select') ? this.element : null;
        if (select) {
            select.value = null === value ? '' : String(value);
            return;
        }
        for (const radio of this.element.querySelectorAll('input[type=radio]')) {
            radio.checked = null !== value && Number(radio.value) === value;
        }
    }
}
