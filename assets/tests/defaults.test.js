import assert from 'node:assert/strict';
import { test } from 'node:test';
import { changedPaths, isChanged } from '../lib/defaults.js';

const defaults = {
    inputs: [{ name: 'In1', gain: 0, link: 1, peq: [{ gain: 120, bypass: false }] }],
    outputs: [{ route: 0b1, mute: false }],
};

test('scalars and missing defaults', () => {
    const state = { inputs: [{ name: 'In1', gain: 220, link: 1, phantom: true, peq: [{ gain: 120, bypass: false }] }] };
    assert.equal(isChanged(state, defaults, 'inputs.0.gain'), true);
    assert.equal(isChanged(state, defaults, 'inputs.0.name'), false);
    assert.equal(isChanged(state, defaults, 'inputs.0.phantom'), false);
    assert.equal(isChanged(null, defaults, 'inputs.0.gain'), false);
    assert.equal(isChanged({ inputs: [{ gain: null }] }, defaults, 'inputs.0.gain'), false);
});

test('one bit of a mask', () => {
    const state = { outputs: [{ route: 0b101, mute: false }] };
    assert.equal(isChanged(state, defaults, 'outputs.0.route', 0), false);
    assert.equal(isChanged(state, defaults, 'outputs.0.route', 1), false);
    assert.equal(isChanged(state, defaults, 'outputs.0.route', 2), true);
    assert.equal(isChanged(state, defaults, 'outputs.0.route'), true);
});

test('changed leaves of a channel', () => {
    const state = { name: 'Kick', gain: 0, link: 1, peq: [{ gain: 132, bypass: false }], extra: 5 };
    assert.deepEqual(changedPaths(state, defaults.inputs[0], 'inputs.0'), ['inputs.0.name', 'inputs.0.peq.0.gain']);
    assert.deepEqual(changedPaths({ ...defaults.inputs[0] }, defaults.inputs[0]), []);
    assert.deepEqual(changedPaths(undefined, defaults.inputs[0]), []);
});
