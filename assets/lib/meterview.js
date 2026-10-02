import { meterView } from './meters.js';
import { readPref, writePref } from './prefs.js';

// The display settings of the threshold mark on the meters, shared by every meter. Kept per browser, every change
// reaches all listeners at once.
const KEY = 'meter-threshold';
const listeners = new Set();
let current = null;

export function getMeterView() {
    current ??= meterView(readPref(KEY, {}));
    return current;
}

export function setMeterView(settings) {
    current = meterView(settings);
    writePref(KEY, current);
    for (const listener of listeners) {
        listener(current);
    }
}

export function onMeterView(listener) {
    listeners.add(listener);
    return () => listeners.delete(listener);
}
