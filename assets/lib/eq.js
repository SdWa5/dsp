// Frequency response of the PEQ bands and crossover filters, for drawing the curve.
// Port of the Python editor: RBJ cookbook biquads at 96 kHz, crossover slopes approximated.
import { indexToHz, indexToQ, PEQ_GAIN_UNITY, peqGainToDb } from './scale.js';
import { readPref, writePref } from './prefs.js';

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

/**
 * Complex response [re, im] of a crossover filter (slope index into Labels::SLOPES, 0 = bypass), from the analogue
 * Butterworth prototype. BW -n is a Butterworth of order n/6, LK -n two Butterworths of half that order in series.
 * BL -n stands in for the Bessel as a Butterworth at 0.8 times the frequency ratio, which the magnitude has always
 * drawn, so its phase is an approximation as well.
 */
export function crossoverResponse(slopes, slope, fc, f, high) {
    if (!slope || slope <= 0) {
        return [1, 0];
    }
    const name = slopes[slope];
    const order = Math.abs(parseInt(name.split(' ')[1], 10)) / 6;
    const x = (high ? fc / f : f / fc) * (name.startsWith('BL') ? 0.8 : 1);
    // A high pass is the low pass at 1/s, which turns s = jx into -jx
    const s = high ? -x : x;
    const butterworth = (n) => {
        let [re, im] = [1, 0];
        for (let k = 1; k <= n; ++k) {
            const angle = (Math.PI * (2 * k + n - 1)) / (2 * n);
            const [dr, di] = [-Math.cos(angle), s - Math.sin(angle)];
            const d = dr * dr + di * di;
            [re, im] = [(re * dr + im * di) / d, (im * dr - re * di) / d];
        }

        return [re, im];
    };
    if (name.startsWith('LK')) {
        const [re, im] = butterworth(order / 2);

        return [re * re - im * im, 2 * re * im];
    }

    return butterworth(order);
}

/** Magnitude of a crossover filter (slope index into Labels::SLOPES, 0 = bypass). */
export function crossover(slopes, slope, fc, f, high) {
    return Math.hypot(...crossoverResponse(slopes, slope, fc, f, high));
}

/**
 * Whether band i of a channel is out of the curve: by its own bypass, or by the channel's EQ Bypass, which takes
 * every PEQ band out but leaves the HPF and LPF. byEq says the EQ Bypass does it, as a gain at its lowest step
 * mutes a channel without its mute.
 */
export function bandBypassed(channel, i) {
    const byEq = Boolean(channel.eq_bypass);
    return { on: byEq || Boolean(channel.peq?.[i]?.bypass), byEq };
}

// Peak, Low Shelf and High Shelf do nothing at 0 dB. Low Pass, High Pass and the Allpasses filter at any gain
const GAIN_TYPES = [0, 1, 2];

/**
 * Why band i of a channel does nothing, or null while it works: 'bypass' by its own Bypass or by EQ Bypass, 'flat'
 * for a Peak or shelf at 0 dB. Its faders' knobs turn red then.
 * @returns {'bypass'|'flat'|null}
 */
export function bandIdle(channel, i) {
    const band = channel?.peq?.[i];
    if (!band) {
        return null;
    }
    if (bandBypassed(channel, i).on) {
        return 'bypass';
    }

    return GAIN_TYPES.includes(band.type) && PEQ_GAIN_UNITY === band.gain ? 'flat' : null;
}

/** Whether a HPF or LPF does nothing: its Bypass is on (slope 0). EQ Bypass is left out until measured. */
export function filterIdle(filter) {
    return Boolean(filter) && !filter.slope;
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
    for (const [filter, high] of [[channel.hpf, true], [channel.lpf, false]]) {
        if (filter) {
            const [r, i] = crossoverResponse(slopes, filter.slope, indexToHz(filter.freq), f, high);
            [re, im] = [re * r - im * i, re * i + im * r];
        }
    }
    return { db: 20 * Math.log10(Math.max(Math.hypot(re, im), 1e-6)), phase: (Math.atan2(im, re) * 180) / Math.PI };
}

/**
 * A channel that holds only one of its filters, so response() gives that filter's curve as it would look alone.
 * which: {kind: 'band', i} for a PEQ band, {kind: 'hpf'} or {kind: 'lpf'}. A bypassed band and the EQ Bypass keep
 * a band flat, and a filter at slope 0 stays flat, as in the channel curve.
 */
export function singleFilter(channel, which) {
    if ('band' === which.kind) {
        const band = channel.peq?.[which.i];

        return { peq: band ? [band] : [], eq_bypass: Boolean(channel.eq_bypass) };
    }

    return { peq: [], [which.kind]: channel[which.kind], eq_bypass: false };
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

/** Phase in degrees on the dB grid: ±180° spans the ±18 dB of a plot, so each 6 dB line is 60°. */
export const DEG_PER_DB = 10;
const VIEW_PREF = 'eq-view';
const MARGIN = { left: 48, top: 14, right: 12, axis: 44, bottom: 22 };

/**
 * What an EQ plot shows: the magnitude, the phase or both, and the unit each side of the grid labels. The left axis
 * carries dB while the magnitude shows or nothing does, degrees for the phase alone. With both, degrees go right.
 * @returns {{mag: boolean, phase: boolean, left: 'db'|'deg', right: 'deg'|null}}
 */
export function eqView(mag = true, phase = false) {
    const shown = { mag: Boolean(mag), phase: Boolean(phase) };
    return { ...shown, left: shown.phase && !shown.mag ? 'deg' : 'db', right: shown.mag && shown.phase ? 'deg' : null };
}

/** The saved view of a plot, key a channel path or "overview". The magnitude alone without one. */
export function loadEqView(key) {
    const saved = readPref(VIEW_PREF, {})[key] ?? {};
    return eqView(saved.mag ?? true, saved.phase ?? false);
}

export function saveEqView(key, mag, phase) {
    writePref(VIEW_PREF, { ...readPref(VIEW_PREF, {}), [key]: { mag: Boolean(mag), phase: Boolean(phase) } });
}

/** The plot area of a canvas for a view, with room on the right for the degree labels when both curves show. */
export function plotFor(view, width, height) {
    const right = view.right ? MARGIN.axis : MARGIN.right;
    return new Plot(MARGIN.left, MARGIN.top, width - MARGIN.left - right, height - MARGIN.top - MARGIN.bottom);
}

/** Label of a grid line at db (-18..+18 in 6 dB steps) in the unit of an axis: "+6dB" or "+60°". */
export function axisLabel(unit, db) {
    const value = 'deg' === unit ? db * DEG_PER_DB : db;
    const sign = value > 0 ? '+' : '';
    return 'deg' === unit ? `${sign}${value}°` : `${sign}${value}dB`;
}

/**
 * The lines of one curve as runs of [x, height on the dB grid]. The magnitude is one run. The phase wraps at ±180°:
 * where it steps across that edge between two points, the run ends on the edge it leaves and the next one starts on
 * the opposite edge, so a turning filter runs off the bottom and comes back in at the top instead of being drawn as
 * a vertical line through the plot.
 */
export function curveRuns(points, kind) {
    if ('phase' !== kind) {
        return [points.map(([x, r]) => [x, curveValue(r, kind)])];
    }
    const edge = 180 / DEG_PER_DB;
    const runs = [];
    let run = [];
    points.forEach(([x, r], i) => {
        const v = r.phase / DEG_PER_DB;
        if (i > 0) {
            const [px, pv] = run[run.length - 1];
            const step = v - pv;
            if (Math.abs(step) > edge) {
                // Unwrapped, the step crosses the edge it leaves: -180° when it jumps up, +180° when it jumps down
                const leave = step > 0 ? -edge : edge;
                const t = (leave - pv) / (v - (step > 0 ? 2 * edge : -2 * edge) - pv);
                const cx = px + (x - px) * t;
                run.push([cx, leave]);
                runs.push(run);
                run = [[cx, -leave]];
            }
        }
        run.push([x, v]);
    });
    runs.push(run);

    return runs;
}

/** Height on the dB grid of one response point for a curve kind, clipped just outside the grid. */
export function curveValue(r, kind) {
    return Math.max(-18.5, Math.min(18.5, 'phase' === kind ? r.phase / DEG_PER_DB : r.db));
}

/** Clears a canvas and draws the frequency grid with the level lines, labelled for a view (dB unless told). */
export function drawGrid(g, plot, width, height, view = eqView()) {
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
    g.textBaseline = 'middle';
    for (let db = -18; db <= 18; db += 6) {
        const y = Math.round(plot.y(db)) + 0.5;
        g.strokeStyle = 0 === db ? '#3a8f3a' : '#1f6f1f';
        g.beginPath();
        g.moveTo(plot.left, y);
        g.lineTo(plot.left + plot.width, y);
        g.stroke();
        g.textAlign = 'right';
        g.fillText(axisLabel(view.left, db), plot.left - 4, y);
        if (view.right) {
            g.textAlign = 'left';
            g.fillText(axisLabel(view.right, db), plot.left + plot.width + 4, y);
        }
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
