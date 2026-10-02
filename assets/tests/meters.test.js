import assert from 'node:assert/strict';
import { test } from 'node:test';
import { compActive, gateActive, meterFraction, meterSource, meterThreshold, meterView, showsThreshold } from '../lib/meters.js';

const state = {
    inputs: [{ gate: { threshold: 100, attack: 0, release: 2999 } }, { gate: { threshold: 0, attack: 0, release: 2999 } }],
    outputs: [{ comp: { ratio: 15, threshold: 180 } }, { comp: { ratio: 0, threshold: 220 } }],
    aux: { comp: { ratio: 9, threshold: 160 } },
};

test('the meter scale runs from -60 to +12 dB', () => {
    assert.equal(meterFraction(-60), 0);
    assert.equal(meterFraction(12), 1);
    assert.equal(meterFraction(-24), 0.5);
    assert.equal(meterFraction(-90), 0);
    assert.equal(meterFraction(20), 1);
});

test('meter slots name their channel', () => {
    assert.equal(meterSource(0), 'inputs.0');
    assert.equal(meterSource(7), 'inputs.7');
    assert.equal(meterSource(8), 'outputs.0');
    assert.equal(meterSource(15), 'outputs.7');
    assert.equal(meterSource(16), 'aux');
});

test('a gate works above -90 dB, a compressor off 1:1.0 below +20 dB', () => {
    assert.equal(gateActive({ threshold: 0 }), false);
    assert.equal(gateActive({ threshold: 1 }), true);
    assert.equal(compActive({ ratio: 0, threshold: 100 }), false);
    assert.equal(compActive({ ratio: 15, threshold: 220 }), false);
    assert.equal(compActive({ ratio: 1, threshold: 219 }), true);
});

test('inputs mark their gate, outputs and the Aux their compressor', () => {
    assert.deepEqual(meterThreshold(state, 0), { kind: 'gate', db: -40, active: true });
    assert.deepEqual(meterThreshold(state, 1), { kind: 'gate', db: -90, active: false });
    assert.deepEqual(meterThreshold(state, 8), { kind: 'comp', db: 0, active: true });
    assert.deepEqual(meterThreshold(state, 9), { kind: 'comp', db: 20, active: false });
    assert.deepEqual(meterThreshold(state, 16), { kind: 'comp', db: -10, active: true });
    assert.equal(meterThreshold(state, 2), null);
    assert.equal(meterThreshold(null, 0), null);
});

test('the settings decide which marks show, off the scale none does', () => {
    const all = meterView({ show: true, active: false });
    assert.deepEqual(meterView(), { show: true, active: true });
    assert.equal(showsThreshold(meterThreshold(state, 0), meterView()), true);
    assert.equal(showsThreshold(meterThreshold(state, 0), meterView({ show: false })), false);
    assert.equal(showsThreshold({ kind: 'comp', db: 0, active: false }, meterView()), false);
    assert.equal(showsThreshold({ kind: 'comp', db: 0, active: false }, all), true);
    assert.equal(showsThreshold(meterThreshold(state, 1), all), false);
    assert.equal(showsThreshold(meterThreshold(state, 9), all), false);
    assert.equal(showsThreshold(null, all), false);
});
