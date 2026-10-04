import { Controller } from '@hotwired/stimulus';
import { getChangeView, onChangeView, setChangeView } from '../lib/changeview.js';

/** The Changed settings section on the Display Settings page: whether marks and counts show, each on its own. */
export default class extends Controller {
    static targets = ['marks', 'counts'];

    connect() {
        this.off = onChangeView(() => this.render());
        this.render();
    }

    disconnect() {
        this.off?.();
    }

    render() {
        const view = getChangeView();
        this.marksTarget.checked = view.marks;
        this.countsTarget.checked = view.counts;
    }

    change() {
        setChangeView({ marks: this.marksTarget.checked, counts: this.countsTarget.checked });
    }
}
