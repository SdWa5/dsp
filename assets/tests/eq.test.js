import assert from 'node:assert/strict';
import { test } from 'node:test';
import { crossover, Plot, response } from '../lib/eq.js';
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

test('plot mapping is invertible', () => {
    const plot = new Plot(48, 12, 600, 300);
    assert.equal(plot.x(20), 48);
    assert.equal(plot.x(20000), 648);
    assert.ok(Math.abs(plot.f(plot.x(1234)) - 1234) < 1e-6);
    assert.equal(plot.y(0), 162);
    assert.ok(Math.abs(plot.db(plot.y(-7.5)) + 7.5) < 1e-9);
});
