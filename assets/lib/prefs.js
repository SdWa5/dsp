/**
 * Per-browser view preferences (theme, matrix orientation, collapsed panels, link display, channel visibility, EQ overview) in
 * localStorage. Storage may be blocked or missing, so reads fall back and writes last for the visit only.
 * A string is stored as it is, which keeps the theme value of older versions readable. Anything else as JSON.
 */
const PREFIX = 'trackdsp-';
const session = new Map();
const listeners = new Set();

export function readPref(key, fallback) {
    let raw = session.get(key) ?? null;
    if (raw === null) {
        try { raw = globalThis.localStorage?.getItem(PREFIX + key) ?? null; } catch { /* Use the fallback. */ }
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
        return null !== value && typeof value === typeof fallback && Array.isArray(value) === Array.isArray(fallback) ? value : fallback;
    } catch {
        return fallback;
    }
}

/** Store the existing wire format and notify local copies, even if storage is unavailable. */
export function writePref(key, value) {
    const raw = 'string' === typeof value ? value : JSON.stringify(value);
    session.set(key, raw);
    try {
        globalThis.localStorage?.setItem(PREFIX + key, raw);
    } catch {
        // The session copy remains readable until this page is closed.
    }
    for (const entry of listeners) if (entry.keys.includes(key)) entry.listener(key);
}

/** Subscribe to specific preference keys. Returns cleanup for controller disconnect. */
export function onPref(keys, listener) {
    const entry = { keys: Array.isArray(keys) ? keys : [keys], listener };
    listeners.add(entry);
    return () => listeners.delete(entry);
}
