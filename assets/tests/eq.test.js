import assert from 'node:assert/strict';
import { test } from 'node:test';
import { axisLabel, bandBypassed, bandIdle, crossover, crossoverResponse, curveRuns, curveValue, eqView, filterIdle, loadEqView, Plot, plotFor, response, saveEqView, singleFilter } from '../lib/eq.js';
import { hzToIndex } from '../lib/scale.js';

const SLOPES = ['Bypass', 'BW -6', 'BL -6', 'BW -12', 'BL -12', 'LK -12', 'BW -18', 'BL -18', 'BW -24', 'BL -24',
    'LK -24', 'BW -30', 'BL -30', 'BW -36', 'BL -36', 'LK -36', 'BW -42', 'BL -42', 'BW -48', 'BL -48', 'LK -48'];
const band = (freq, gain, q = 25, type = 0, bypass = false) => ({ freq, gain, q, type, bypass });

test('flat bands give a flat response', () => {
    const channel = { peq: [band(49, 120), band(101, 120)], eq_bypass: false };
    for (const f of [20, 100, 1000, 10000]) {
        assert.ok(Math.abs(response(channel, f, SLOPES).db) < 1e-9);
    }
});

test('a peak band has its gain at its frequency', () => {
    const f = 1000;
    const channel = { peq: [band(hzToIndex(f), 180)], eq_bypass: false };
    const at = response(channel, 19.6875 * 2 ** (hzToIndex(f) / 30), SLOPES).db;
    assert.ok(Math.abs(at - 6) < 0.01, `${at}`);
    assert.ok(Math.abs(response(channel, 20, SLOPES).db) < 0.1);
});

test('bypass and EQ bypass switch the band off', () => {
    assert.ok(Math.abs(response({ peq: [band(170, 240, 25, 0, true)] }, 1000, SLOPES).db) < 1e-9);
    assert.ok(Math.abs(response({ peq: [band(170, 240)], eq_bypass: true }, 1000, SLOPES).db) < 1e-9);
});

test('crossover slopes', () => {
    assert.equal(crossover(SLOPES, 0, 100, 10, true), 1);
    // Linkwitz-Riley is -6 dB at the corner, Butterworth -3 dB
    assert.ok(Math.abs(20 * Math.log10(crossover(SLOPES, 20, 100, 100, true)) + 6.02) < 0.01);
    assert.ok(Math.abs(20 * Math.log10(crossover(SLOPES, 18, 100, 100, false)) + 3.01) < 0.01);
    // BW -24 falls 24 dB per octave far from the corner
    const a = 20 * Math.log10(crossover(SLOPES, 8, 1000, 100, true));
    const b = 20 * Math.log10(crossover(SLOPES, 8, 1000, 50, true));
    assert.ok(Math.abs(a - b - 24) < 0.1);
});

test('crossover phase at the corner', () => {
    const deg = ([re, im]) => (Math.atan2(im, re) * 180) / Math.PI;
    const at = (slope, high) => deg(crossoverResponse(SLOPES, slope, 1000, 1000, high));
    // A Butterworth of order n turns 45° per order at its corner, lagging as a low pass and leading as a high pass
    assert.ok(Math.abs(at(1, false) + 45) < 1e-9);
    assert.ok(Math.abs(at(1, true) - 45) < 1e-9);
    assert.ok(Math.abs(at(3, false) + 90) < 1e-9);
    assert.ok(Math.abs(at(6, false) + 135) < 1e-9);
    // LK -24 is two BW -12 in series, 180° at the corner
    assert.ok(Math.abs(Math.abs(at(10, false)) - 180) < 1e-9);
    assert.deepEqual(crossoverResponse(SLOPES, 0, 1000, 1000, true), [1, 0]);
});

test('the HPF and LPF turn the phase of a channel', () => {
    const corner = 19.6875 * 2 ** (100 / 30);
    const hpf = response({ peq: [], hpf: { freq: 100, slope: 1 }, eq_bypass: false }, corner, SLOPES);
    assert.ok(Math.abs(hpf.phase - 45) < 1e-6, `${hpf.phase}`);
    const lpf = response({ peq: [], lpf: { freq: 100, slope: 3 }, eq_bypass: false }, corner, SLOPES);
    assert.ok(Math.abs(lpf.phase + 90) < 1e-6, `${lpf.phase}`);
});

test('the phase wraps from -180° to +180° without a line through the plot', () => {
    const points = [[0, { phase: -150 }], [10, { phase: -170 }], [20, { phase: 170 }], [30, { phase: 150 }]];
    const runs = curveRuns(points, 'phase');
    assert.equal(runs.length, 2);
    // -170° to 170° is 20° on, so it leaves at -180° halfway and comes back at +180°
    assert.deepEqual(runs[0].at(-1), [15, -18]);
    assert.deepEqual(runs[1][0], [15, 18]);
    assert.deepEqual(runs[1].at(-1), [30, 15]);
    // The other way round it leaves at the top
    const up = curveRuns([[0, { phase: 170 }], [10, { phase: -170 }]], 'phase');
    assert.deepEqual(up.map((run) => run.map(([x, v]) => [x, v])), [[[0, 17], [5, 18]], [[5, -18], [10, -17]]]);
    // The magnitude stays one run, clipped just outside the grid
    assert.deepEqual(curveRuns([[0, { db: -40 }], [10, { db: 3 }]], 'mag'), [[[0, -18.5], [10, 3]]]);
});

test('a single filter draws as it would alone', () => {
    const peak = band(hzToIndex(1000), 180);
    const channel = { peq: [band(hzToIndex(100), 60), peak], hpf: { freq: 100, slope: 20 }, lpf: { freq: 300, slope: 0 }, eq_bypass: false };
    const at = (which, f) => response(singleFilter(channel, which), f, SLOPES).db;
    // The band alone: its own +6 dB, without the other band's cut and without the HPF
    assert.ok(Math.abs(at({ kind: 'band', i: 1 }, 19.6875 * 2 ** (hzToIndex(1000) / 30)) - 6) < 0.01);
    assert.ok(Math.abs(at({ kind: 'band', i: 1 }, 20)) < 0.1);
    // The HPF alone falls below its corner and is flat above it, without either band
    assert.ok(at({ kind: 'hpf' }, 20) < -60);
    assert.ok(Math.abs(at({ kind: 'hpf' }, 5000)) < 0.01);
    // A filter at slope 0, a bypassed band and the EQ Bypass stay flat, as in the channel curve
    assert.ok(Math.abs(at({ kind: 'lpf' }, 20000)) < 1e-9);
    assert.deepEqual(singleFilter({ ...channel, eq_bypass: true }, { kind: 'band', i: 1 }).eq_bypass, true);
    assert.ok(Math.abs(response(singleFilter({ ...channel, peq: [band(170, 240, 25, 0, true)] }, { kind: 'band', i: 0 }), 1000, SLOPES).db) < 1e-9);
    assert.deepEqual(singleFilter(channel, { kind: 'band', i: 9 }).peq, []);
});

test('a band or filter that does nothing', () => {
    const channel = (peq, eqBypass = false) => ({ peq, eq_bypass: eqBypass });
    // Peak, Low Shelf and High Shelf at 0 dB are flat
    for (const type of [0, 1, 2]) {
        assert.equal(bandIdle(channel([band(100, 120, 25, type)]), 0), 'flat');
        assert.equal(bandIdle(channel([band(100, 121, 25, type)]), 0), null);
    }
    // Low Pass, High Pass and the Allpasses filter at any gain
    for (const type of [3, 4, 5, 6]) {
        assert.equal(bandIdle(channel([band(100, 120, 25, type)]), 0), null);
    }
    // A bypass wins, by the band's own Bypass or by EQ Bypass
    assert.equal(bandIdle(channel([band(100, 180, 25, 0, true)]), 0), 'bypass');
    assert.equal(bandIdle(channel([band(100, 120, 25, 4)], true), 0), 'bypass');
    assert.equal(bandIdle(channel([]), 0), null);
    assert.equal(bandIdle(undefined, 0), null);
    // A HPF or LPF does nothing at slope 0 only
    assert.equal(filterIdle({ freq: 100, slope: 0 }), true);
    assert.equal(filterIdle({ freq: 100, slope: 20 }), false);
    assert.equal(filterIdle(undefined), false);
});

test('plot mapping is invertible', () => {
    const plot = new Plot(48, 12, 600, 300);
    assert.equal(plot.x(20), 48);
    assert.equal(plot.x(20000), 648);
    assert.ok(Math.abs(plot.f(plot.x(1234)) - 1234) < 1e-6);
    assert.equal(plot.y(0), 162);
    assert.ok(Math.abs(plot.db(plot.y(-7.5)) + 7.5) < 1e-9);
});

test('EQ Bypass shows every band as bypassed, and says so', () => {
    const channel = { peq: [band(49, 120), band(101, 120, 25, 0, true)], eq_bypass: false };
    assert.deepEqual(bandBypassed(channel, 0), { on: false, byEq: false });
    assert.deepEqual(bandBypassed(channel, 1), { on: true, byEq: false });
    channel.eq_bypass = true;
    assert.deepEqual(bandBypassed(channel, 0), { on: true, byEq: true });
    assert.deepEqual(bandBypassed(channel, 1), { on: true, byEq: true });
    // The bands keep their own bypass, so switching EQ Bypass off brings back what each band had
    assert.equal(channel.peq[0].bypass, false);
});

test('magnitude, phase or both, with the axis each side labels', () => {
    assert.deepEqual(eqView(), { mag: true, phase: false, left: 'db', right: null });
    assert.deepEqual(eqView(false, true), { mag: false, phase: true, left: 'deg', right: null });
    assert.deepEqual(eqView(true, true), { mag: true, phase: true, left: 'db', right: 'deg' });
    // Nothing shown keeps the dB grid
    assert.deepEqual(eqView(false, false), { mag: false, phase: false, left: 'db', right: null });
});

test('the phase reads in degrees, ±180° on the ±18 dB lines', () => {
    assert.deepEqual([-18, -6, 0, 6, 18].map((db) => axisLabel('deg', db)), ['-180°', '-60°', '0°', '+60°', '+180°']);
    assert.deepEqual([-18, 0, 18].map((db) => axisLabel('db', db)), ['-18dB', '0dB', '+18dB']);
    assert.equal(curveValue({ db: 3, phase: -90 }, 'phase'), -9);
    assert.equal(curveValue({ db: 3, phase: -90 }, 'mag'), 3);
    assert.equal(curveValue({ db: 40, phase: 0 }, 'mag'), 18.5);
});

test('the plot makes room for the degree axis only beside the magnitude', () => {
    assert.deepEqual({ ...plotFor(eqView(), 660, 336) }, { left: 48, top: 14, width: 600, height: 300 });
    assert.deepEqual({ ...plotFor(eqView(false, true), 660, 336) }, { left: 48, top: 14, width: 600, height: 300 });
    assert.equal(plotFor(eqView(true, true), 660, 336).width, 568);
});

test('the view is saved per plot', () => {
    const items = new Map();
    globalThis.localStorage = { getItem: (k) => items.get(k) ?? null, setItem: (k, v) => items.set(k, String(v)) };
    try {
        assert.deepEqual(loadEqView('inputs.0'), eqView());
        saveEqView('inputs.0', true, true);
        saveEqView('overview', false, true);
        assert.deepEqual(loadEqView('inputs.0'), eqView(true, true));
        assert.deepEqual(loadEqView('overview'), eqView(false, true));
        assert.deepEqual(loadEqView('outputs.3'), eqView());
    } finally {
        delete globalThis.localStorage;
    }
});
