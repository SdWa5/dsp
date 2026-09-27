import BoundController from '../lib/bound_controller.js';

export default class extends BoundController {
    static targets = ['input', 'label'];

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
        this.labelTarget.textContent = this.text(value);
        this.commit(value);
    }

    /** Mouse wheel moves one step. */
    wheel(event) {
        if (this.inputTarget.disabled) {
            return;
        }
        event.preventDefault();
        const step = event.deltaY < 0 ? 1 : -1;
        const value = Math.max(Number(this.inputTarget.min), Math.min(Number(this.inputTarget.max), Number(this.inputTarget.value) + step));
        this.inputTarget.value = value;
        this.input();
    }

    render(value) {
        if (null !== value && undefined !== value) {
            this.inputTarget.value = value;
        }
        this.labelTarget.textContent = this.text(value);
    }
}
