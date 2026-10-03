/**
 * Per-browser view preferences (theme, matrix orientation, collapsed panels, link display, channel visibility, EQ overview) in
 * localStorage. Storage may be blocked or missing, so reads fall back and writes last for the visit only.
 * A string is stored as it is, which keeps the theme value of older versions readable. Anything else as JSON.
 */
const PREFIX = 'trackdsp-';

export function readPref(key, fallback) {
    let raw = null;
    try {
        raw = globalThis.localStorage?.getItem(PREFIX + key) ?? null;
    } catch {
        return fallback;
    }
    if (null === raw) {
        return fallback;
    }
    if ('string' === typeof fallback) {
        return raw;
    }
    try {
        const value = JSON.parse(raw);
        // A value of another type than the fallback was written by something else
        return null !== value && typeof value === typeof fallback ? value : fallback;
    } catch {
        return fallback;
    }
}

export function writePref(key, value) {
    try {
        globalThis.localStorage?.setItem(PREFIX + key, 'string' === typeof value ? value : JSON.stringify(value));
    } catch {
        // Only this visit then
    }
}
