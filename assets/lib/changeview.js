import { t } from './i18n.js';
import { readPref, writePref } from './prefs.js';

// How this browser shows settings that differ from the factory default: the marks (the bar on a field, the
// triangle on a channel tab, the bar on a page tab) and the counts on the page tabs, each on its own.
const KEY = 'changed-view';
const listeners = new Set();
let current = null;

export function changeView(value) {
    const flag = (field) => ('boolean' === typeof value?.[field] ? value[field] : true);
    return { marks: flag('marks'), counts: flag('counts') };
}

export function getChangeView() {
    current ??= changeView(readPref(KEY, {}));
    return current;
}

export function setChangeView(value) {
    current = changeView(value);
    writePref(KEY, current);
    for (const listener of listeners) {
        listener(current);
    }
}

export function onChangeView(listener) {
    listeners.add(listener);
    return () => listeners.delete(listener);
}

/** The tooltip of a changed tab: the count while counts show, a plain note while only the marks do, else nothing. */
export function changedTitle(count, view) {
    if (count <= 0) {
        return '';
    }
    if (view.counts) {
        return t(1 === count
            ? '%count% setting differs from the factory default'
            : '%count% settings differ from the factory default', { count });
    }
    return view.marks ? t('Settings differ from the factory default') : '';
}

/** Switches the marks and the counts off through data attributes on root, which app.css hides them by. */
export function applyChangeView(view, root) {
    root.toggleAttribute('data-changed-marks', !view.marks);
    root.toggleAttribute('data-changed-counts', !view.counts);
}
