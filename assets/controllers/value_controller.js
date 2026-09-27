import BoundController from '../lib/bound_controller.js';

/** Shows a formatted state value (preset name, device ID, IP). */
export default class extends BoundController {
    render(value) {
        this.element.textContent = this.text(value);
    }

    refresh(state) {
        if (null === state) {
            this.element.textContent = '–';
            return;
        }
        super.refresh(state);
    }
}
