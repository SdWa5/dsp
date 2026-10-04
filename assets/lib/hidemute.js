import { readPref, writePref } from './prefs.js';

// "Mute hidden channels": a per-browser option that sets the Mute of a channel when this browser hides it. It is the
// only display setting that writes to the device. It never unmutes: a channel shown again, or the option switched
// off, leaves every Mute as it is.
const KEY = 'hidden-mute';
const listeners = new Set();
let current = null;

export function hideMuteView(value) {
    return { on: true === value?.on };
}

export function getHideMute() {
    current ??= hideMuteView(readPref(KEY, {}));
    return current;
}

export function setHideMute(value) {
    current = hideMuteView(value);
    writePref(KEY, current);
    for (const listener of listeners) {
        listener(current);
    }
}

export function onHideMute(listener) {
    listeners.add(listener);
    return () => listeners.delete(listener);
}

/** The state path of a channel's Mute: inputs 0..7, outputs 8..15, Aux 18. */
export function mutePath(index) {
    return index < 8 ? `inputs.${index}.mute` : index < 16 ? `outputs.${index - 8}.mute` : `aux.mute`;
}

/**
 * The channels to mute when the hidden channels or the option change. prev and next are {hidden, on}, isMuted(index)
 * answers true, false or null for unknown. Acts on changes only, so a page load never mutes anything: a channel that
 * turns hidden while the option is on, or every hidden channel when the option goes on. Only a channel known to play
 * is muted. Nothing is ever unmuted.
 */
export function hidingMutes(prev, next, isMuted) {
    if (!next.on) {
        return [];
    }
    return next.hidden.filter((i) => (!prev.on || !prev.hidden.includes(i)) && false === isMuted(i));
}
