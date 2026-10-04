import assert from 'node:assert/strict';
import { test } from 'node:test';
import { faderDefault, knobHollow, knobMuted, rangeFill } from '../lib/range.js';

test('the fill runs from 0 at min to 1 at max', () => {
    assert.equal(rangeFill(0, 0, 400), 0);
    assert.equal(rangeFill(100, 0, 400), 0.25);
    assert.equal(rangeFill(400, 0, 400), 1);
    assert.equal(rangeFill(9, 9, 2999), 0);
    // Out of range and an empty range stay inside the track
    assert.equal(rangeFill(500, 0, 400), 1);
    assert.equal(rangeFill(-1, 0, 400), 0);
    assert.equal(rangeFill(5, 5, 5), 0);
});

test('a gain knob turns red at the bottom or with its Mute', () => {
    assert.equal(knobMuted('gain', 0, false), true);
    assert.equal(knobMuted('gain', 280, true), true);
    assert.equal(knobMuted('gain', 280, false), false);
    assert.equal(knobMuted('gain', 1, undefined), false);
    // A percent gain (Sub, Group) at 0 is not known to mute, only its Mute button counts
    assert.equal(knobMuted('percent', 0, false), false);
    assert.equal(knobMuted('percent', 0, true), true);
});

test('an output delay knob turns red at 0, no delay', () => {
    assert.equal(knobMuted('delay', 0, undefined), true);
    assert.equal(knobMuted('delay', 1, undefined), false);
    assert.equal(knobMuted('delay', 32640, undefined), false);
    // An echo delay at 0 ms still repeats, so it keeps its colour
    assert.equal(knobMuted('plainMs', 0, undefined), false);
});


test('double-click defaults preserve zero, channel and band values, overrides and range bounds', () => {
    const defaults = { inputs: [{ gain: 0, gate: { threshold: 0, release: 2999 },
        peq: [{ freq: 41, q: 25, gain: 120 }, { freq: 101 }], hpf: { freq: 0 }, lpf: { freq: 300 } }],
        aux: { comp: { threshold: 220, attack: 49 }, effect: { gain: 280 } }, groups: [{ gain: 100 }] };
    assert.equal(faderDefault(defaults, 'inputs.0.gate.threshold', 0, 220), 0);
    assert.equal(faderDefault(defaults, 'inputs.0.gate.release', 0, 2999), 2999);
    assert.equal(faderDefault(defaults, 'inputs.0.peq.0.freq', 0, 300), 41);
    assert.equal(faderDefault(defaults, 'inputs.0.peq.1.freq', 0, 300), 101);
    assert.equal(faderDefault(defaults, 'inputs.0.peq.0.q', 0, 100), 25);
    assert.equal(faderDefault(defaults, 'inputs.0.peq.0.gain', 0, 240), 120);
    assert.equal(faderDefault(defaults, 'inputs.0.hpf.freq', 0, 300), 0);
    assert.equal(faderDefault(defaults, 'inputs.0.lpf.freq', 0, 300), 300);
    assert.equal(faderDefault(defaults, 'inputs.0.gain', 0, 400, 280), 280);
    assert.equal(faderDefault(defaults, 'aux.effect.gain', 0, 400), 280);
    assert.equal(faderDefault(defaults, 'groups.0.gain', 0, 100), 100);
    assert.equal(faderDefault(defaults, 'aux.comp.threshold', 0, 180), 180);
    assert.equal(faderDefault(defaults, 'aux.comp.attack', 99, 199), 99);
    assert.equal(faderDefault(defaults, 'missing.value', 0, 100), null);
    assert.equal(faderDefault(null, 'aux.comp.attack', 0, 199), null);
    for (const value of [null, undefined, '25', false, NaN, Infinity]) {
        assert.equal(faderDefault({ value }, 'value', 0, 100), null);
    }
});

test('a gain knob muted by its lowest step alone is hollow', () => {
    assert.equal(knobHollow('gain', 0, false), true);
    assert.equal(knobHollow('gain', 0, undefined), true);
    // Its Mute button wins: a filled knob
    assert.equal(knobHollow('gain', 0, true), false);
    assert.equal(knobHollow('gain', 280, false), false);
    assert.equal(knobHollow('percent', 0, false), false);
    assert.equal(knobHollow('delay', 0, false), false);
});
