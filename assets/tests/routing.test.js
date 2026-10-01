import { test } from 'node:test';
import assert from 'node:assert/strict';
import { routingEdges, routingRows, sourceChannel } from '../lib/routing.js';

test('routingEdges lists every set bit of every output, Aux as source 8', () => {
    const outputs = [{ route: 0b1 }, { route: 0b11 }, { route: 1 << 8 }, { route: 0 }, {}, null];
    assert.deepEqual(routingEdges(outputs), [
        { source: 0, output: 0 },
        { source: 0, output: 1 },
        { source: 1, output: 1 },
        { source: 8, output: 2 },
    ]);
});

test('routingEdges without a state has no edges', () => {
    assert.deepEqual(routingEdges(undefined), []);
});

test('sourceChannel maps Aux to its channel index', () => {
    assert.equal(sourceChannel(0), 0);
    assert.equal(sourceChannel(7), 7);
    assert.equal(sourceChannel(8), 18);
});

test('routingRows centres the shorter column', () => {
    const rows = routingRows(9, 8, 26, 8);
    assert.equal(rows.height, 9 * 34 - 8);
    assert.deepEqual(rows.sources.slice(0, 2), [13, 47]);
    assert.equal(rows.outputs[0], 17 + 13);
    assert.equal(rows.outputs.length, 8);
});
