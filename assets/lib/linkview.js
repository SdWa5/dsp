import { linkView } from './link.js';
import { readPref, writePref } from './prefs.js';

// The link display settings (code, icon, colour, placement) shared by the channel tabs, the Link dialog and every
// section that shows channels. Kept per browser, every change reaches all listeners at once.
const KEY = 'link-view';
const listeners = new Set();
let current = null;

export function getLinkView() {
    current ??= linkView(readPref(KEY, {}));
    return current;
}

export function setLinkView(settings) {
    current = linkView(settings);
    writePref(KEY, current);
    for (const listener of listeners) {
        listener(current);
    }
}

export function onLinkView(listener) {
    listeners.add(listener);
    return () => listeners.delete(listener);
}
