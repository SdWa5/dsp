// Value scales of the device, the same formulas as App\Protocol\Scale.

export const GAIN_MAX = 400;
export const GAIN_UNITY = 280;
export const FREQ_STEPS = 300;
export const FREQ_BASE = 19.6875;
export const PEQ_GAIN_UNITY = 120;
export const SAMPLE_RATE = 48000;
const SPEED_OF_SOUND = 343.0;
const FEET_PER_METRE = 3.2808;

const round1 = (v) => Math.round(v * 10) / 10;

export function gainToDb(raw) {
    return raw < 80 ? round1(raw / 2 - 60) : round1((raw - 80) / 10 - 20);
}

export function dbToGain(db) {
    db = Math.max(-60, Math.min(12, db));
    return db < -20 ? Math.round((db + 60) * 2) : Math.round((db + 20) * 10) + 80;
}

export function indexToHz(index) {
    return FREQ_BASE * 2 ** (index / 30);
}

export function hzToIndex(hz) {
    const index = Math.round(30 * Math.log2(Math.max(hz, FREQ_BASE) / FREQ_BASE));
    return Math.max(0, Math.min(FREQ_STEPS, index));
}

export function formatHz(hz) {
    return hz >= 999.95 ? `${(hz / 1000).toFixed(2)}KHz` : `${hz.toFixed(1)}Hz`;
}

export function peqGainToDb(raw) {
    return round1((raw - PEQ_GAIN_UNITY) / 10);
}

export function dbToPeqGain(db) {
    return Math.round(Math.max(-12, Math.min(12, db)) * 10) + PEQ_GAIN_UNITY;
}

export function indexToQ(index) {
    return 0.4 * 320 ** (index / 100);
}

export function qToIndex(q) {
    if (q <= 0.4) {
        return 0;
    }
    return Math.max(0, Math.min(100, Math.round((100 * Math.log(q / 0.4)) / Math.log(320))));
}

export function thresholdToDb(raw) {
    return raw / 2 - 90;
}

export function rawToMs(raw) {
    return raw + 1;
}

export function samplesToMs(samples) {
    return (samples * 1000) / SAMPLE_RATE;
}

export function delayLabel(samples, unit = 0) {
    const ms = samplesToMs(samples);
    if (1 === unit) {
        return `${((ms / 1000) * SPEED_OF_SOUND).toFixed(3)} m`;
    }
    if (2 === unit) {
        return `${((ms / 1000) * SPEED_OF_SOUND * FEET_PER_METRE).toFixed(3)} ft`;
    }
    return `${ms.toFixed(3)} ms`;
}

export function reverbTimeSeconds(raw) {
    return (raw * 1.96) / 139;
}

const signed = (v) => (v > 0 ? `+${v.toFixed(1)}` : v.toFixed(1));

/**
 * Display formats by name, as used in data-fmt. Some take the label lists (from the page) or the state.
 */
export const formats = {
    raw: (v) => String(v),
    gain: (v) => `${signed(gainToDb(v))}dB`,
    hz: (v) => formatHz(indexToHz(v)),
    // The editor rounds Q to one decimal and shows two: 25 = 1.70, 32 = 2.50
    q: (v) => (Math.round(indexToQ(v) * 10) / 10).toFixed(2),
    peq: (v) => `${signed(peqGainToDb(v))}dB`,
    threshold: (v) => `${signed(thresholdToDb(v))}dB`,
    ms: (v) => `${rawToMs(v)}ms`,
    plainMs: (v) => `${v}ms`,
    knee: (v) => `${v}dB`,
    percent: (v) => `${v}%`,
    reverbTime: (v) => `${reverbTimeSeconds(v).toFixed(2)}s`,
    delay: (v, state) => delayLabel(v, state?.delay_unit ?? 0),
    ratio: (v, _state, labels) => labels?.COMP_RATIOS?.[v] ?? String(v),
};

export function format(name, value, state = null, labels = null) {
    if (null === value || undefined === value) {
        return '–';
    }
    return (formats[name] ?? formats.raw)(value, state, labels);
}

/**
 * Preset number as the editor shows it: 0 = F00, 1..20 = U01..U20.
 */
export function presetLabel(number) {
    if (null === number || undefined === number) {
        return '–';
    }
    return 0 === number ? 'F00' : `U${String(number).padStart(2, '0')}`;
}

/**
 * Index of a preset number in preset_names and preset_stored: U01..U20 = 0..19, F00 = 20.
 */
export function presetSlot(number) {
    return 0 === number ? 20 : number - 1;
}

/**
 * Name, occupancy and option text of a preset number. stored is null while the occupancy is unknown,
 * and F00 always counts as stored.
 */
export function presetSlotInfo(state, number) {
    const slot = presetSlot(number);
    const name = (state?.preset_names?.[slot] ?? '').trim();
    const stored = 0 === number ? true : (state?.preset_stored?.[slot] ?? null);
    const shown = false === stored ? '(empty)' : name || '(no name)';

    return { name, stored, text: `${presetLabel(number)}  ${shown}` };
}

const number = (text) => {
    const value = parseFloat(String(text).trim().replace(',', '.'));
    return Number.isFinite(value) ? value : null;
};

/**
 * The inverse of the formats: a typed value ("-6", "-6 dB", "200ms", "1:4", "2.5k") as the raw value, null
 * for text that does not parse. The caller clamps to the fader's range.
 */
export const parsers = {
    raw: (t) => number(t),
    gain: (t) => (null === number(t) ? null : dbToGain(number(t))),
    hz: (t) => (null === number(t) ? null : hzToIndex(/k/i.test(t) ? number(t) * 1000 : number(t))),
    q: (t) => (null === number(t) ? null : qToIndex(number(t))),
    peq: (t) => (null === number(t) ? null : dbToPeqGain(number(t))),
    threshold: (t) => (null === number(t) ? null : Math.round((number(t) + 90) * 2)),
    ms: (t) => (null === number(t) ? null : Math.round(number(t)) - 1),
    plainMs: (t) => number(t),
    knee: (t) => number(t),
    percent: (t) => number(t),
    reverbTime: (t) => (null === number(t) ? null : Math.round((number(t) * 139) / 1.96)),
    delay: (t, state) => {
        const value = number(t);
        if (null === value) {
            return null;
        }
        const unit = state?.delay_unit ?? 0;
        const metres = /ft/i.test(t) || (2 === unit && !/m/i.test(t)) ? value / FEET_PER_METRE : value;
        const ms = /ms/i.test(t) || (0 === unit && !/m|ft/i.test(t)) ? value : (metres / SPEED_OF_SOUND) * 1000;
        return Math.round((ms * SAMPLE_RATE) / 1000);
    },
    ratio: (t, _state, labels) => {
        const text = String(t).trim().toLowerCase();
        const ratios = Object.entries(labels?.COMP_RATIOS ?? {});
        const named = ratios.find(([, label]) => label.toLowerCase() === text);
        if (named) {
            return Number(named[0]);
        }
        const n = number(text.replace(/^1:/, ''));
        const match = null === n ? null : ratios.find(([, label]) => number(label.replace(/^1:/, '')) === n);
        return match ? Number(match[0]) : null;
    },
};

export function parse(name, text, state = null, labels = null) {
    const value = (parsers[name] ?? parsers.raw)(String(text), state, labels);
    return null === value || Number.isNaN(value) ? null : Math.round(value);
}
