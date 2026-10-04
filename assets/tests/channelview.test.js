import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { displayView, channelVisible, displayedChannel, representative, channelMembers, displayChannelParts, visibleRuns, channelLayout, visibleEdge, selectionState, setMaskBits, getChannelView, setChannelView, onChannelView } from '../lib/channelview.js';
const side = () => Array.from({ length: 8 }, (_, i) => ({ link: 1 << i }));
const state = () => { const inputs = side(), outputs = side(); inputs[0].link = 5; inputs[2].link = 0; outputs[1].link = 10; outputs[3].link = 0; return { inputs, outputs }; };
const groups = [1, 0, 1, 0, 0, 0, 0, 0];

test('display defaults and malformed stored preferences accept Aux but reject non-channel indices or invalid grouping', () => {
    assert.deepEqual(displayView(null), { hidden: [], grouped: false });
    assert.deepEqual(displayView({ hidden: 'all', grouped: 'true' }), { hidden: [], grouped: false });
    assert.deepEqual(displayView({ hidden: [15, 0, 15, 7, 8, 18, 16, 17, -1, '2', 0.5], grouped: true }), { hidden: [0, 7, 8, 15, 18], grouped: true });
    assert.equal(channelVisible(18, displayView({ hidden: [18] })), false);
});
test('visibility has independent input/output boundary indices and survives blocked storage for this visit', () => {
    const previous = globalThis.localStorage;
    globalThis.localStorage = { setItem() { throw new Error('blocked'); } };
    let seen = null; const off = onChannelView(value => { seen = value; });
    try {
        setChannelView({ hidden: [0, 7, 8, 15], grouped: true });
        assert.deepEqual(seen, getChannelView());
        for (const index of [0, 7, 8, 15]) assert.equal(channelVisible(index), false);
        assert.equal(channelVisible(1), true);
        assert.equal(channelVisible(18), true);
    } finally { off(); globalThis.localStorage = previous; setChannelView({}); }
});
test('hidden leaders choose the lowest visible member without changing group members', () => {
    const data = state(), view = displayView({ hidden: [0], grouped: true });
    assert.deepEqual(channelMembers(2, data), [0, 2]);
    assert.equal(representative(0, data, view), 2);
    assert.equal(displayedChannel(0, data, view), false);
    assert.equal(displayedChannel(2, data, view), true);
    assert.equal(displayedChannel(3, data, view), true);
    assert.equal(representative(0, data, displayView({ hidden: [0, 2] })), null);
    data.inputs[0].link = 1; data.inputs[2].link = 4;
    assert.deepEqual(channelMembers(2, data), [2]);
    assert.equal(displayedChannel(2, data, view), true);
});
test('group collapsing never changes Matrix/graph runs or original group numbers', () => {
    const view = displayView({ hidden: [0], grouped: true });
    for (const placement of ['normal', 'side', 'stacked']) {
        const separate = visibleRuns(groups, placement, 0, view);
        assert.equal(separate.flatMap(run => run.channels).length, 7);
        const collapsed = visibleRuns(groups, placement, 0, view, true);
        assert.equal(collapsed.flatMap(run => run.channels).length, 7);
        assert.ok(separate.some(run => run.channels.includes(2)));
    }
    assert.equal(visibleRuns(groups, 'stacked', 0, displayView({ grouped: true }), true).flatMap(run => run.channels).length, 7);
    assert.equal(visibleRuns(groups, 'stacked', 0, displayView({ grouped: true })).flatMap(run => run.channels).length, 8);
    assert.deepEqual(visibleRuns(groups, 'stacked', 0, displayView({ hidden: [0, 2] })).filter(run => run.group > 0), []);
});
test('all tab placements compact sparse channels and empty sides without zero row divisors', () => {
    for (const placement of ['normal', 'side', 'stacked']) {
        const layout = channelLayout(groups, placement, 0, displayView({ hidden: [0, 7], grouped: true }));
        assert.equal(layout.cells[0], undefined);
        assert.equal(layout.cells[7], undefined);
        assert.equal(layout.cells.filter(Boolean).length, 6);
        assert.equal(layout.cells[2].column >= 1, true);
        const empty = channelLayout(groups, placement, 0, displayView({ hidden: Array.from({ length: 8 }, (_, i) => i) }));
        assert.equal(empty.columns, 0); assert.equal(empty.rows, 1);
    }
});
test('hidden endpoints remove direct and Aux graph edges without changing route masks', () => {
    const view = displayView({ hidden: [0, 15], grouped: true });
    assert.equal(visibleEdge({ source: 0, output: 0 }, view), false);
    assert.equal(visibleEdge({ source: 1, output: 7 }, view), false);
    assert.equal(visibleEdge({ source: 0, output: 8 }, view), false);
    assert.equal(visibleEdge({ source: 8, output: 7 }, view), false);
    assert.equal(visibleEdge({ source: 8, output: 0 }, view), true);
    assert.equal(visibleEdge({ source: 7, output: 8 }, view), true);
    const hiddenAux = displayView({ hidden: [18] });
    assert.equal(visibleEdge({ source: 8, output: 0 }, hiddenAux), false);
    assert.equal(visibleEdge({ source: 7, output: 8 }, hiddenAux), false);
    assert.equal(visibleEdge({ source: 7, output: 0 }, hiddenAux), true);
});
test('aggregate selectors distinguish none, mixed and all; masked writes preserve unrelated bits', () => {
    assert.deepEqual(selectionState([false, false]), { selected: false, mixed: false, next: true });
    assert.deepEqual(selectionState([true, false]), { selected: false, mixed: true, next: true });
    assert.deepEqual(selectionState([true, true]), { selected: true, mixed: false, next: false });
    const mask = (1 << 12) | (1 << 3);
    assert.equal(setMaskBits(mask, [0, 7], true), mask | 129);
    assert.equal(setMaskBits(mask | 129, [0, 7], false), mask);
    assert.equal(setMaskBits(mask, [8], true), mask | 256);
    assert.equal(setMaskBits(mask | 256, [8], false), mask);
});
test('persisted preferences round-trip in a fresh module and unsubscribe stops notifications', async () => {
    const previous = globalThis.localStorage; const values = new Map();
    globalThis.localStorage = { getItem: key => values.get(key) ?? null, setItem: (key, value) => values.set(key, value) };
    try {
        setChannelView({ hidden: [1, 9], grouped: true });
        const fresh = await import('../lib/channelview.js?reload');
        assert.deepEqual(fresh.getChannelView(), { hidden: [1, 9], grouped: true });
        let calls = 0; const off = fresh.onChannelView(() => calls++); off(); fresh.setChannelView({});
        assert.equal(calls, 0);
    } finally { globalThis.localStorage = previous; setChannelView({}); }
});


test('section Show all actions preserve Aux, the other side and grouping', () => {
    const source = readFileSync(new URL('../controllers/channelview_controller.js', import.meta.url), 'utf8')
        .replace(/^import .*;\n/gm, '').replace('export default class', 'return class');
    const Display = new Function('Controller', 'getChannelView', 'setChannelView', source)(class {}, getChannelView, setChannelView);
    const controller = new Display();
    try {
        setChannelView({ hidden: [0, 7, 8, 15, 18], grouped: true });
        controller.showSide({ params: { side: 'inputs' } });
        assert.deepEqual(getChannelView(), { hidden: [8, 15, 18], grouped: true });
        controller.showSide({ params: { side: 'outputs' } });
        assert.deepEqual(getChannelView(), { hidden: [18], grouped: true });
        controller.showSide({ params: { side: 'invalid' } });
        assert.deepEqual(getChannelView(), { hidden: [18], grouped: true });
        controller.showAll();
        assert.deepEqual(getChannelView(), { hidden: [], grouped: true });
    } finally { setChannelView({}); }
});

test('grouped captions include every input/output rename and hidden member in channel order', () => {
    const data = state(), view = displayView({ hidden: [0], grouped: true });
    data.inputs[0].name = 'DJ L'; data.inputs[2].name = 'DJ R';
    data.outputs[1].name = 'Main L'; data.outputs[3].name = 'Main R';
    assert.deepEqual(displayChannelParts(2, data, view), { members: [0, 2], id: 'In1, In3', name: 'DJ L, DJ R', label: 'In1 (DJ L), In3 (DJ R)' });
    assert.equal(displayChannelParts(9, data, view).name, 'Main L, Main R');
    data.inputs[2].name = 'New <R>';
    assert.equal(displayChannelParts(0, data, view).name, 'DJ L, New <R>');
    data.inputs[0].link = 9;
    assert.equal(displayChannelParts(0, data, view).name, 'DJ L, In4');
    assert.equal(displayChannelParts(0, data, { grouped: false }).name, 'DJ L');
});
test('group captions suppress redundant defaults and keep single channels and Aux individual', () => {
    const data = state(), view = { grouped: true };
    data.inputs[0].name = 'in 1'; data.inputs[2].name = 'In3';
    assert.equal(displayChannelParts(0, data, view).name, '');
    assert.deepEqual(displayChannelParts(7, null, view), { members: [7], id: 'In8', name: '', label: 'In8' });
    data.aux = { name: 'FX' };
    assert.deepEqual(displayChannelParts(18, data, view), { members: [18], id: 'Aux', name: 'FX', label: 'Aux (FX)' });
    data.inputs[0].link = 255;
    data.inputs.forEach((channel, index) => { channel.name = 'Name' + index; });
    assert.equal(displayChannelParts(0, data, view).name, Array.from({ length: 8 }, (_, index) => 'Name' + index).join(', '));
});
