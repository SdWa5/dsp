import { Controller } from '@hotwired/stimulus';
import { readPref, onPref } from '../lib/prefs.js';
import { setPanelCollapsed } from '../lib/displayprefs.js';

const KEY = 'collapsed';

/**
 * A panel that folds down to its title. The title holds the toggle button, everything else in the panel is
 * hidden by `.is-collapsed`. Folded panels are kept per browser, by the key of the panel.
 */
export default class extends Controller {
    static targets = ['toggle'];
    static values = { key: String };

    connect() {
        this.apply(true === readPref(KEY, {})[this.keyValue]);
        this.offPrefs = onPref(KEY, () => this.apply(true === readPref(KEY, {})[this.keyValue]));
    }

    disconnect() { this.offPrefs?.(); }

    toggle() { setPanelCollapsed(this.keyValue, !this.element.classList.contains('is-collapsed')); }

    apply(collapsed) {
        const reopening = !collapsed && this.element.classList.contains('is-collapsed');
        this.element.classList.toggle('is-collapsed', collapsed);
        this.toggleTargets.forEach((button) => button.setAttribute('aria-expanded', String(!collapsed)));
        if (reopening) window.dispatchEvent(new Event('resize'));
    }
}
