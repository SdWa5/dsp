import { GAIN_MUTED } from './scale.js';
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

/**
 * Whether a gain fader shows a red knob: its gain sits at raw 0 (the bottom, which mutes) or its Mute button is
 * set. fmt: the fader's value format, only gain faders (fmt gain) have the bottom rule.
 */
export function knobMuted(fmt, value, mute) {
    return true === mute || ('gain' === fmt && GAIN_MUTED === value);
}
