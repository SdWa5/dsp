import { readPref, writePref } from './prefs.js';

// "Mute hidden channels": a per-browser option that sets the Mute of a channel while this browser hides it. It is the
// only display setting that writes to the device. The record lists the channels this browser muted itself, so showing
// a channel again unmutes only those and leaves a channel muted that was muted before it was hidden.
const KEY = 'hidden-mute';
const listeners = new Set();
let current = null;

const channel = (i) => Number.isInteger(i) && i >= 0 && (i < 16 || 18 === i);

export function hideMuteView(value) {
    const muted = Array.isArray(value?.muted) ? [...new Set(value.muted.filter(channel))].sort((a, b) => a - b) : [];
    return { on: true === value?.on, muted };
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
 * What to write when the hidden channels or the option change. prev and next are {hidden, on}, muted is the record,
 * isMuted(index) answers true, false or null for unknown. Acts on changes only, so a page load never mutes anything.
 * A channel is muted only while it is known to play, and unmuted only when this browser muted it and it still is.
 * Returns the channels to mute and to unmute and the new record.
 */
export function hidingMutes(prev, next, muted, isMuted) {
    const record = new Set(muted);
    const shouldMute = next.on ? next.hidden.filter((i) => !prev.on || !prev.hidden.includes(i)) : [];
    const release = prev.on && !next.on ? [...record] : [...record].filter((i) => !next.hidden.includes(i));
    const mute = shouldMute.filter((i) => !record.has(i) && false === isMuted(i));
    const unmute = release.filter((i) => true === isMuted(i));
    release.forEach((i) => record.delete(i));
    mute.forEach((i) => record.add(i));
    return { mute, unmute, muted: [...record].sort((a, b) => a - b) };
}
