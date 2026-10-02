import { thresholdToDb } from './scale.js';
// The level meters: their scale, the channel behind each meter slot and the gate or compressor threshold
// that a meter marks.

export const METER_FLOOR = -60;
export const METER_TOP = 12;
/** Gate threshold raw 0 is -90 dB, the factory value, where the gate never closes. */
const GATE_OFF = 0;
/** Compressor ratio 0 is 1:1.0, which never compresses. */
const RATIO_OFF = 0;
/** Compressor threshold raw 220 is +20 dB, above the output ceiling, where the compressor never works. */
const THRESHOLD_OFF = 220;

/** Height of a level on the meter, 0 at -60 dB and 1 at +12 dB. */
export function meterFraction(db) {
    return Math.max(0, Math.min(1, (db - METER_FLOOR) / (METER_TOP - METER_FLOOR)));
}

/**
 * Whether the meter at index shows its limiter working. limiting: byte 51 of the meter reply, a bitmask of
 * Out1–8 (bit 0 = Out1) as the captures show it. Only output meters (8–15) have the lamp.
 */
export function limits(limiting, index) {
    return index >= 8 && index < 16 && 0 !== (limiting & (1 << (index - 8)));
}

/** The state path of the channel behind a meter slot: In1–8 = 0–7, Out1–8 = 8–15, Aux = 16. */
export function meterSource(index) {
    if (index < 8) {
        return `inputs.${index}`;
    }
    return index < 16 ? `outputs.${index - 8}` : 'aux';
}

export function gateActive(gate) {
    return (gate?.threshold ?? GATE_OFF) > GATE_OFF;
}

export function compActive(comp) {
    return (comp?.ratio ?? RATIO_OFF) !== RATIO_OFF && (comp?.threshold ?? THRESHOLD_OFF) < THRESHOLD_OFF;
}

/**
 * The threshold a meter marks: the gate of an input, the compressor of an output or the Aux. Meter and
 * threshold share one dB scale. null without state.
 * @returns {{kind: 'gate'|'comp', db: number, active: boolean}|null}
 */
export function meterThreshold(state, index) {
    const channel = index < 8 ? state?.inputs?.[index] : index < 16 ? state?.outputs?.[index - 8] : state?.aux;
    const kind = index < 8 ? 'gate' : 'comp';
    const dynamics = channel?.[kind];
    if (!dynamics) {
        return null;
    }
    return { kind, db: thresholdToDb(dynamics.threshold), active: 'gate' === kind ? gateActive(dynamics) : compActive(dynamics) };
}

/** The display settings of the threshold mark with defaults for missing keys: show it, only while it works. */
export function meterView(settings = {}) {
    return { show: settings.show ?? true, active: settings.active ?? true };
}

/** Whether a meter shows the mark of a threshold under the display settings. Off the meter's scale it never shows. */
export function showsThreshold(threshold, view) {
    if (null === threshold || !view.show || (view.active && !threshold.active)) {
        return false;
    }
    return threshold.db >= METER_FLOOR && threshold.db <= METER_TOP;
}
