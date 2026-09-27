// Frequency response of the PEQ bands and crossover filters, for drawing the curve.
// Port of the Python editor: RBJ cookbook biquads at 96 kHz, crossover slopes approximated.
import { indexToHz, indexToQ, peqGainToDb } from './scale.js';

const FS = 96000;

/** Biquad coefficients [b0, b1, b2, a0, a1, a2] for a PEQ type (Labels::PEQ_TYPES). */
export function biquad(type, f0, gainDb, q) {
    const a = 10 ** (gainDb / 40);
    const w0 = (2 * Math.PI * f0) / FS;
    const cw = Math.cos(w0);
    const sw = Math.sin(w0);
    const alpha = sw / (2 * q);
    switch (type) {
        case 0:
            return [1 + alpha * a, -2 * cw, 1 - alpha * a, 1 + alpha / a, -2 * cw, 1 - alpha / a];
        case 1:
        case 2: {
            const sq = 2 * Math.sqrt(a) * alpha;
            if (1 === type) {
                return [
                    a * (a + 1 - (a - 1) * cw + sq), 2 * a * (a - 1 - (a + 1) * cw), a * (a + 1 - (a - 1) * cw - sq),
                    a + 1 + (a - 1) * cw + sq, -2 * (a - 1 + (a + 1) * cw), a + 1 + (a - 1) * cw - sq,
                ];
            }
            return [
                a * (a + 1 + (a - 1) * cw + sq), -2 * a * (a - 1 + (a + 1) * cw), a * (a + 1 + (a - 1) * cw - sq),
                a + 1 - (a - 1) * cw + sq, 2 * (a - 1 - (a + 1) * cw), a + 1 - (a - 1) * cw - sq,
            ];
        }
        case 3:
            return [(1 - cw) / 2, 1 - cw, (1 - cw) / 2, 1 + alpha, -2 * cw, 1 - alpha];
        case 4:
            return [(1 + cw) / 2, -(1 + cw), (1 + cw) / 2, 1 + alpha, -2 * cw, 1 - alpha];
        case 5: {
            const k = Math.tan((Math.PI * f0) / FS);
            return [k - 1, k + 1, 0, k + 1, k - 1, 0];
        }
        default:
            return [1 - alpha, -2 * cw, 1 + alpha, 1 + alpha, -2 * cw, 1 - alpha];
    }
}

/** Complex response [re, im] of a biquad at frequency f. */
export function biquadResponse([b0, b1, b2, a0, a1, a2], f) {
    const w = (-2 * Math.PI * f) / FS;
    const z1 = [Math.cos(w), Math.sin(w)];
    const z2 = [Math.cos(2 * w), Math.sin(2 * w)];
    const num = [b0 + b1 * z1[0] + b2 * z2[0], b1 * z1[1] + b2 * z2[1]];
    const den = [a0 + a1 * z1[0] + a2 * z2[0], a1 * z1[1] + a2 * z2[1]];
    const d = den[0] * den[0] + den[1] * den[1];
    return [(num[0] * den[0] + num[1] * den[1]) / d, (num[1] * den[0] - num[0] * den[1]) / d];
}

/** Magnitude of a crossover filter (slope index into Labels::SLOPES, 0 = bypass). */
export function crossover(slopes, slope, fc, f, high) {
    if (!slope || slope <= 0) {
        return 1;
    }
    const name = slopes[slope];
    const order = Math.abs(parseInt(name.split(' ')[1], 10)) / 6;
    const x = high ? fc / f : f / fc;
    if (name.startsWith('LK')) {
        return (1 / Math.sqrt(1 + x ** order)) ** 2;
    }
    if (name.startsWith('BL')) {
        return 1 / Math.sqrt(1 + (x * 0.8) ** (2 * order));
    }
    return 1 / Math.sqrt(1 + x ** (2 * order));
}

/**
 * Response of a channel at f: {db, phase} with phase in degrees.
 * channel: {peq: [...], hpf?, lpf?, eq_bypass}
 */
export function response(channel, f, slopes) {
    let re = 1;
    let im = 0;
    if (!channel.eq_bypass) {
        for (const band of channel.peq ?? []) {
            if (band.bypass) {
                continue;
            }
            const [r, i] = biquadResponse(biquad(band.type, indexToHz(band.freq), peqGainToDb(band.gain), indexToQ(band.q)), f);
            [re, im] = [re * r - im * i, re * i + im * r];
        }
    }
    let mag = Math.hypot(re, im);
    if (channel.hpf) {
        mag *= crossover(slopes, channel.hpf.slope, indexToHz(channel.hpf.freq), f, true);
    }
    if (channel.lpf) {
        mag *= crossover(slopes, channel.lpf.slope, indexToHz(channel.lpf.freq), f, false);
    }
    return { db: 20 * Math.log10(Math.max(mag, 1e-6)), phase: (Math.atan2(im, re) * 180) / Math.PI };
}

/** Plot mapping: 20 Hz..20 kHz logarithmic, -18..+18 dB. */
export class Plot {
    constructor(left, top, width, height) {
        Object.assign(this, { left, top, width, height });
    }

    x(f) {
        return this.left + (this.width * Math.log(f / 20)) / Math.log(1000);
    }

    f(x) {
        return 20 * 1000 ** ((x - this.left) / this.width);
    }

    y(db) {
        return this.top + (this.height * (18 - db)) / 36;
    }

    db(y) {
        return 18 - ((y - this.top) / this.height) * 36;
    }
}

const GRID_HZ = [20, 30, 40, 50, 60, 70, 80, 90, 100, 200, 300, 400, 500, 600, 700, 800, 900, 1000, 2000, 3000, 4000,
    5000, 6000, 7000, 8000, 9000, 10000, 20000];

/** Clears a canvas and draws the frequency and dB grid of a plot with its labels. */
export function drawGrid(g, plot, width, height) {
    g.fillStyle = '#0d0f0d';
    g.fillRect(0, 0, width, height);
    g.strokeStyle = '#1f6f1f';
    g.lineWidth = 1;
    g.font = '11px sans-serif';
    g.fillStyle = '#cfcfcf';
    for (const f of GRID_HZ) {
        const x = Math.round(plot.x(f)) + 0.5;
        g.beginPath();
        g.moveTo(x, plot.top);
        g.lineTo(x, plot.top + plot.height);
        g.stroke();
    }
    g.textAlign = 'right';
    g.textBaseline = 'middle';
    for (let db = -18; db <= 18; db += 6) {
        const y = Math.round(plot.y(db)) + 0.5;
        g.strokeStyle = 0 === db ? '#3a8f3a' : '#1f6f1f';
        g.beginPath();
        g.moveTo(plot.left, y);
        g.lineTo(plot.left + plot.width, y);
        g.stroke();
        g.fillText(db > 0 ? `+${db}dB` : `${db}dB`, plot.left - 4, y);
    }
    g.textAlign = 'center';
    g.textBaseline = 'top';
    for (const [f, t] of [[20, '20Hz'], [100, '100Hz'], [1000, '1KHz'], [10000, '10KHz'], [20000, '20KHz']]) {
        g.fillText(t, plot.x(f), plot.top + plot.height + 4);
    }
}

/** Sizes a canvas for the device pixel ratio and returns its 2D context, null while it is hidden. */
export function prepare(canvas) {
    const ratio = window.devicePixelRatio || 1;
    const width = canvas.clientWidth;
    const height = canvas.clientHeight;
    if (0 === width || 0 === height) {
        return null;
    }
    canvas.width = width * ratio;
    canvas.height = height * ratio;
    const g = canvas.getContext('2d');
    g.setTransform(ratio, 0, 0, ratio, 0, 0);
    return { g, width, height };
}
