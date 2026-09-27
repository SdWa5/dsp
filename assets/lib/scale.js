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
