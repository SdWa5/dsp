import { test } from 'node:test';
import assert from 'node:assert/strict';
import { inRoutingFocus, routingEdges, routingFocus, routingLit, routingRows, sourceChannel } from '../lib/routing.js';

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

test('a hovered element stands for a channel, a connection or nothing', () => {
    assert.deepEqual(routingFocus({ source: '3' }), { source: 3 });
    assert.deepEqual(routingFocus({ output: '0' }), { output: 0 });
    assert.deepEqual(routingFocus({ source: '8', output: '7' }), { source: 8, output: 7 });
    assert.equal(routingFocus({}), null);
    assert.equal(routingFocus(undefined), null);
});

test('a connection lies in the focus of either end, a channel in a focus naming it', () => {
    const cell = { source: 2, output: 5 };
    assert.equal(inRoutingFocus({ source: 2 }, cell), true);
    assert.equal(inRoutingFocus({ output: 5 }, cell), true);
    assert.equal(inRoutingFocus({ source: 2, output: 5 }, cell), true);
    assert.equal(inRoutingFocus({ source: 2, output: 4 }, cell), false);
    assert.equal(inRoutingFocus({ source: 2, output: 4 }, { source: 2 }), true);
    assert.equal(inRoutingFocus({ source: 2, output: 4 }, { output: 4 }), true);
    assert.equal(inRoutingFocus({ source: 2 }, { output: 4 }), false);
    assert.equal(inRoutingFocus(null, cell), false);
});

test('a cell lights its two channels and its connection, a channel itself and its connections', () => {
    const edges = [{ source: 0, output: 0 }, { source: 0, output: 1 }, { source: 3, output: 1 }];
    const lit = (focus, element) => routingLit(focus, element, edges);
    // Hovering the cell In1 → Out2: both channels and that connection, nothing else
    assert.equal(lit({ source: 0, output: 1 }, { source: 0 }), true);
    assert.equal(lit({ source: 0, output: 1 }, { output: 1 }), true);
    assert.equal(lit({ source: 0, output: 1 }, { source: 0, output: 1 }), true);
    assert.equal(lit({ source: 0, output: 1 }, { source: 0, output: 0 }), false);
    assert.equal(lit({ source: 0, output: 1 }, { source: 3 }), false);
    assert.equal(lit({ source: 0, output: 1 }, { output: 0 }), false);
    // A cell without a connection still lights itself and its two channels
    assert.equal(lit({ source: 5, output: 6 }, { source: 5, output: 6 }), true);
    // Hovering Out2: itself and its incoming connections, not the inputs they come from
    assert.equal(lit({ output: 1 }, { output: 1 }), true);
    assert.equal(lit({ output: 1 }, { source: 0, output: 1 }), true);
    assert.equal(lit({ output: 1 }, { source: 3, output: 1 }), true);
    assert.equal(lit({ output: 1 }, { source: 5, output: 1 }), false);
    assert.equal(lit({ output: 1 }, { source: 0 }), false);
    // Hovering In1: itself and its outgoing connections
    assert.equal(lit({ source: 0 }, { source: 0, output: 0 }), true);
    assert.equal(lit({ source: 0 }, { source: 0, output: 2 }), false);
    assert.equal(lit({ source: 0 }, { output: 0 }), false);
    assert.equal(lit(null, { source: 0 }), false);
});
