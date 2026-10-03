import assert from 'node:assert/strict';
import { test } from 'node:test';
import { curveColourSource, linkedPaths, nearestCurve } from '../lib/overview.js';

// In2+In3 linked (master In2 holds the mask), Out5..Out8 linked
const channel = (link = 0) => ({ link });
const state = {
    inputs: [channel(), channel(0b110), channel(), channel(), channel(), channel(), channel(), channel()],
    outputs: [channel(), channel(), channel(), channel(), channel(0b11110000), channel(), channel(), channel()],
};

test('a linked curve belongs with every member of its group', () => {
    assert.deepEqual(linkedPaths('inputs.2', state), ['inputs.1', 'inputs.2']);
    assert.deepEqual(linkedPaths('outputs.6', state), ['outputs.4', 'outputs.5', 'outputs.6', 'outputs.7']);
    assert.deepEqual(linkedPaths('inputs.0', state), ['inputs.0']);
    assert.deepEqual(linkedPaths('aux', state), ['aux']);
    assert.deepEqual(linkedPaths('inputs.3', null), ['inputs.3']);
});

test('the members of a group take one colour source', () => {
    assert.deepEqual(curveColourSource('inputs.2', state), { group: 1, lead: 'inputs.1' });
    assert.deepEqual(curveColourSource('outputs.7', state), { group: 1, lead: 'outputs.4' });
    assert.deepEqual(curveColourSource('inputs.5', state), { group: 0, lead: 'inputs.5' });
    assert.deepEqual(curveColourSource('aux', state), { group: 0, lead: 'aux' });
});

test('the pointer picks the nearest curve within reach', () => {
    const curves = [
        { path: 'inputs.0', runs: [[[0, 100], [100, 100]]] },
        { path: 'outputs.0', runs: [[[0, 110], [50, 110]], [[50, 0], [100, 0]]] },
    ];
    assert.equal(nearestCurve(curves, 40, 103), 'inputs.0');
    assert.equal(nearestCurve(curves, 40, 108), 'outputs.0');
    assert.equal(nearestCurve(curves, 80, 4), 'outputs.0');
    // Off every curve, and past the end of a run
    assert.equal(nearestCurve(curves, 40, 50), null);
    assert.equal(nearestCurve(curves, 80, 109), null);
    assert.equal(nearestCurve([], 0, 0), null);
});
