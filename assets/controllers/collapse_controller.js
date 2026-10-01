import { Controller } from '@hotwired/stimulus';
import { readPref, writePref } from '../lib/prefs.js';

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
    }

    toggle() {
        const collapsed = !this.element.classList.contains('is-collapsed');
        this.apply(collapsed);
        const all = readPref(KEY, {});
        if (collapsed) {
            all[this.keyValue] = true;
        } else {
            delete all[this.keyValue];
        }
        writePref(KEY, all);
        if (!collapsed) {
            // Canvases measure themselves on resize, and were 0 wide while folded
            window.dispatchEvent(new Event('resize'));
        }
    }

    apply(collapsed) {
        this.element.classList.toggle('is-collapsed', collapsed);
        this.toggleTargets.forEach((button) => button.setAttribute('aria-expanded', String(!collapsed)));
    }
}
