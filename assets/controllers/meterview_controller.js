import { Controller } from '@hotwired/stimulus';
import { getMeterView, onMeterView, setMeterView } from '../lib/meterview.js';

/**
 * The Meters settings on the Display Settings page: whether the meters mark the gate or compressor threshold, and only
 * while it works. "Only when active" has no effect while the mark is off, so it is disabled then.
 */
export default class extends Controller {
    static targets = ['show', 'active'];

    connect() {
        this.off = onMeterView(() => this.render());
        this.render();
    }

    disconnect() {
        this.off?.();
    }

    render() {
        const view = getMeterView();
        this.showTarget.checked = view.show;
        this.activeTarget.checked = view.active;
        this.activeTarget.disabled = !view.show;
    }

    change() {
        setMeterView({ show: this.showTarget.checked, active: this.activeTarget.checked });
    }
}
