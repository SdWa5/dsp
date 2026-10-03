import { test } from 'node:test';
import assert from 'node:assert/strict';
import { inRoutingFocus, routingEdges, routingFocus, routingLayout, routingLit, sourceChannel } from '../lib/routing.js';

// A focus or an element as routingFocus() reads it from the data attributes, null stays null
const at = (data) => (null === data ? null : routingFocus(Object.fromEntries(Object.entries(data).map(([key, value]) => [key, String(value)]))));

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

test('routingLayout centres the shorter column', () => {
    const single = (count) => Array.from({ length: count }, (_, i) => ({ group: 0, channels: [i] }));
    const layout = routingLayout({ sources: single(9), outputs: single(8) }, { height: 26, gap: 8 });
    assert.equal(layout.height, 9 * 34 - 8);
    assert.deepEqual(layout.sources.y.slice(0, 2), [13, 47]);
    assert.equal(layout.outputs.y[0], 17 + 13);
    assert.equal(layout.outputs.y.length, 8);
    assert.deepEqual(layout.sources.frames, []);
});

test('routingLayout frames a stacked group under its head', () => {
    const layout = routingLayout({
        sources: [{ group: 1, channels: [0, 3] }, { group: 0, channels: [1] }],
        outputs: [{ group: 0, channels: [0] }],
    }, { height: 26, gap: 8, head: 14, pad: 4 });
    // Head 14 + pad 4, node 26, pad 4, node 26, pad 4 = 78, then gap 8 and the channel alone
    assert.deepEqual(layout.sources.frames, [{ group: 1, channels: [0, 3], top: 0, bottom: 78 }]);
    assert.equal(layout.sources.y[0], 18 + 13);
    assert.equal(layout.sources.y[3], 48 + 13);
    assert.equal(layout.sources.y[1], 86 + 13);
    assert.equal(layout.height, 112);
    assert.equal(layout.outputs.y[0], (112 - 26) / 2 + 13);
});

test('a hovered element stands for a channel, a connection, a link group or nothing', () => {
    assert.deepEqual(routingFocus({ source: '3' }), { sources: [3], outputs: [] });
    assert.deepEqual(routingFocus({ output: '0' }), { sources: [], outputs: [0] });
    assert.deepEqual(routingFocus({ source: '8', output: '7' }), { sources: [8], outputs: [7] });
    assert.deepEqual(routingFocus({ sources: '0 1 3' }), { sources: [0, 1, 3], outputs: [] });
    assert.deepEqual(routingFocus({ outputs: '' }), null);
    assert.equal(routingFocus({}), null);
    assert.equal(routingFocus(undefined), null);
});

test('a connection lies in the focus of either end, a channel in a focus naming it', () => {
    const inFocus = (focus, element) => inRoutingFocus(at(focus), at(element));
    const cell = { source: 2, output: 5 };
    assert.equal(inFocus({ source: 2 }, cell), true);
    assert.equal(inFocus({ output: 5 }, cell), true);
    assert.equal(inFocus({ source: 2, output: 5 }, cell), true);
    assert.equal(inFocus({ source: 2, output: 4 }, cell), false);
    assert.equal(inFocus({ source: 2, output: 4 }, { source: 2 }), true);
    assert.equal(inFocus({ source: 2, output: 4 }, { output: 4 }), true);
    assert.equal(inFocus({ source: 2 }, { output: 4 }), false);
    assert.equal(inFocus(null, cell), false);
    // A group lies only in a focus naming all of its channels, so a member alone does not light it
    assert.equal(inFocus({ sources: '0 1 3' }, { sources: '0 1 3' }), true);
    assert.equal(inFocus({ source: 1 }, { sources: '0 1 3' }), false);
    assert.equal(inFocus({ source: 1, output: 4 }, { sources: '0 1 3' }), false);
});

test('a cell lights its two channels and its connection, a channel itself and its connections', () => {
    const edges = [{ source: 0, output: 0 }, { source: 0, output: 1 }, { source: 3, output: 1 }];
    const lit = (focus, element) => routingLit(at(focus), at(element), edges);
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
    // Hovering the group In1 + In4: both members and their connections, not In2 or the outputs
    assert.equal(lit({ sources: '0 3' }, { source: 3 }), true);
    assert.equal(lit({ sources: '0 3' }, { source: 0, output: 0 }), true);
    assert.equal(lit({ sources: '0 3' }, { source: 3, output: 1 }), true);
    assert.equal(lit({ sources: '0 3' }, { source: 3, output: 0 }), false);
    assert.equal(lit({ sources: '0 3' }, { source: 1 }), false);
    assert.equal(lit({ sources: '0 3' }, { output: 1 }), false);
});
