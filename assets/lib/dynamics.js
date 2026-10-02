import { thresholdToDb } from './scale.js';
// The static curve of a gate or compressor, input level against output level, as the vendor editor plots it on its
// Gate and Comp pages and in its Aux compressor dialog. Both axes run from -100 to +20 dB on the meter scale.

export const AXIS_MIN = -100;
export const AXIS_MAX = 20;

/** "1:4.0" → 4, "Limit" → Infinity, anything unknown → 1 (no compression). */
export function ratioValue(label) {
    if ('Limit' === label) {
        return Infinity;
    }
    const match = /^1:(\d+(?:\.\d+)?)$/.exec(String(label ?? ''));
    return match ? Number(match[1]) : 1;
}

/** Output level of a compressor with a hard knee: unchanged up to the threshold, above it divided by the ratio. */
export function compOut(level, threshold, ratio) {
    return level <= threshold ? level : threshold + (level - threshold) / ratio;
}

/** Output level of a gate: unchanged from the threshold up, below it closed, which the plot shows at its floor. */
export function gateOut(level, threshold) {
    return level >= threshold ? level : AXIS_MIN;
}

/**
 * The corner points of the curve in dB, from the left of the plot to its right, and the threshold point.
 * dynamics: the raw gate or compressor of the state, ratios: the labels of the ratio steps (Labels::COMP_RATIOS).
 * @returns {{points: number[][], threshold: number[]}}
 */
export function dynamicsCurve(kind, dynamics, ratios = []) {
    const t = Math.max(AXIS_MIN, Math.min(AXIS_MAX, thresholdToDb(dynamics.threshold)));
    if ('gate' === kind) {
        return { points: [[t, AXIS_MIN], [t, t], [AXIS_MAX, AXIS_MAX]], threshold: [t, t] };
    }
    const ratio = ratioValue(ratios[dynamics.ratio]);
    return { points: [[AXIS_MIN, AXIS_MIN], [t, t], [AXIS_MAX, compOut(AXIS_MAX, t, ratio)]], threshold: [t, t] };
}

/**
 * Draws a curve on a prepared canvas: the dB grid every 20 dB, the curve in white and the threshold as a red dot.
 * g, width, height: from prepare() in eq.js. A small plot leaves out the axis labels and the title.
 */
export function drawDynamics(g, width, height, curve, title = '') {
    const small = width < 260;
    const pad = small ? { left: 6, right: 6, top: 6, bottom: 6 } : { left: 38, right: 14, top: title ? 26 : 12, bottom: 24 };
    const size = Math.min(width - pad.left - pad.right, height - pad.top - pad.bottom);
    const left = pad.left + (width - pad.left - pad.right - size) / 2;
    const top = pad.top;
    const x = (db) => left + ((db - AXIS_MIN) / (AXIS_MAX - AXIS_MIN)) * size;
    const y = (db) => top + ((AXIS_MAX - db) / (AXIS_MAX - AXIS_MIN)) * size;
    g.fillStyle = '#0d0f0d';
    g.fillRect(0, 0, width, height);
    g.strokeStyle = '#1f6f1f';
    g.lineWidth = 1;
    g.font = '11px sans-serif';
    g.fillStyle = '#cfcfcf';
    for (let db = AXIS_MIN; db <= AXIS_MAX; db += 20) {
        const [gx, gy] = [Math.round(x(db)) + 0.5, Math.round(y(db)) + 0.5];
        g.strokeStyle = 0 === db ? '#3a8f3a' : '#1f6f1f';
        g.beginPath();
        g.moveTo(gx, top);
        g.lineTo(gx, top + size);
        g.moveTo(left, gy);
        g.lineTo(left + size, gy);
        g.stroke();
        if (!small) {
            g.textAlign = 'right';
            g.textBaseline = 'middle';
            g.fillText(String(db), left - 4, gy);
            g.textAlign = 'center';
            g.textBaseline = 'top';
            g.fillText(String(db), gx, top + size + 4);
        }
    }
    if (title && !small) {
        g.textAlign = 'center';
        g.textBaseline = 'top';
        g.font = '13px sans-serif';
        g.fillText(title, left + size / 2, 6);
    }
    g.save();
    g.beginPath();
    g.rect(left, top, size, size);
    g.clip();
    g.strokeStyle = '#fff';
    g.lineWidth = small ? 1.5 : 2;
    g.beginPath();
    curve.points.forEach(([px, py], i) => (0 === i ? g.moveTo(x(px), y(py)) : g.lineTo(x(px), y(py))));
    g.stroke();
    g.restore();
    g.fillStyle = '#ff5252';
    g.strokeStyle = '#fff';
    g.lineWidth = 1;
    g.beginPath();
    g.arc(x(curve.threshold[0]), y(curve.threshold[1]), small ? 3 : 5, 0, 2 * Math.PI);
    g.fill();
    g.stroke();
}
