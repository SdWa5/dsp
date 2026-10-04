import { GAIN_MUTED } from './scale.js';
import { getPath } from './store.js';
// Range inputs draw their own track and knob (app.css), so the knob can take a colour of its own. The filled part
// of the track comes from --fill, which paintRange() keeps in step with the value.

/** Where a value lies on a range, 0 at min and 1 at max, clamped. */
export function rangeFill(value, min, max) {
    return max > min ? Math.max(0, Math.min(1, (value - min) / (max - min))) : 0;
}

/** Sets --fill on a range input from its current value. */
export function paintRange(input) {
    input.style.setProperty('--fill', rangeFill(Number(input.value), Number(input.min), Number(input.max)).toFixed(4));
}

/** Resolve a numeric fader default, preserving explicit overrides and clamping to the available range. */
export function faderDefault(defaults, path, min, max, override) {
    const value = override ?? getPath(defaults, path);
    return Number.isFinite(value) ? Math.max(min, Math.min(max, value)) : null;
}

/**
 * Whether a fader shows a red knob: a gain at raw 0 (the bottom, which mutes) or with its Mute button set, an
 * output delay at 0 samples, which is no delay. fmt: the fader's value format, which decides the rule.
 */
export function knobMuted(fmt, value, mute) {
    return true === mute || ('gain' === fmt && GAIN_MUTED === value) || ('delay' === fmt && 0 === value);
}

/**
 * Whether the red knob is a ring rather than filled: a gain muted by its lowest step alone, not by its Mute button.
 */
export function knobHollow(fmt, value, mute) {
    return true !== mute && 'gain' === fmt && GAIN_MUTED === value;
}
