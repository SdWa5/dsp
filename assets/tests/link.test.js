import assert from 'node:assert/strict';
import { test } from 'node:test';
import { LINK_MATRIX_LAYOUTS, lcm, linkCellShown, linkCommands, linkFocus, linkGaps, linkGroups, linkLayout, linkMark, linkMatrixLayout, linkName, linkNameKey, linkPlacement, linkRuns, linkUnits, linkedMembers, linkOrder, linkView, toggleLink, toggleLinkCell } from '../lib/link.js';

// An unlinked channel holds its own bit (factory default 1, 2, 4 …), a slave holds 0
const side = (...links) => [0, 1, 2, 3, 4, 5, 6, 7].map((i) => ({ link: links[i] ?? 1 << i }));

test('members and groups', () => {
    const channels = side(0b11, 0, 0b1100, 0);
    assert.deepEqual(linkGroups(side()), [0, 0, 0, 0, 0, 0, 0, 0]);
    assert.deepEqual(linkedMembers(channels, 1), [0, 1]);
    assert.deepEqual(linkedMembers(channels, 3), [2, 3]);
    assert.deepEqual(linkedMembers(channels, 5), [5]);
    assert.deepEqual(linkGroups(channels), [1, 1, 2, 2, 0, 0, 0, 0]);
});

test('linking copies the master to the new slave first and keeps the slave\'s name', () => {
    const channels = side();
    channels[1].name = 'Main R';
    const commands = linkCommands(channels, [1, 1, 0, 0, 0, 0, 0, 0], 8);
    assert.deepEqual(commands, [
        { command: 'copyChannel', args: { source: 8, target: 9 } },
        { command: 'channelName', args: { channel: 9, name: 'Main R' } },
        { command: 'link', args: { channel: 8, flags: 3 } },
        { command: 'link', args: { channel: 9, flags: 0 } },
    ]);
});

test('a slave that moves to another group gets the new master copied', () => {
    // In1 + In2 and In3 + In4 become In1 + In2 + In4: In4 keeps its flags 0, but its master changes
    assert.deepEqual(linkCommands(side(0b11, 0, 0b1100, 0), [1, 1, 0, 1, 0, 0, 0, 0], 0), [
        { command: 'copyChannel', args: { source: 0, target: 3 } },
        { command: 'channelName', args: { channel: 3, name: '' } },
        { command: 'link', args: { channel: 0, flags: 0b1011 } },
        { command: 'link', args: { channel: 2, flags: 0b100 } },
    ]);
});

test('unlinking clears the flags only', () => {
    assert.deepEqual(linkCommands(side(0b11, 0), [0, 0, 0, 0, 0, 0, 0, 0], 0), [
        { command: 'link', args: { channel: 0, flags: 1 } },
        { command: 'link', args: { channel: 1, flags: 2 } },
    ]);
    assert.deepEqual(linkCommands(side(0b11, 0), [1, 1, 0, 0, 0, 0, 0, 0], 0), []);
});

test('linked channels are ordered next to each other', () => {
    assert.deepEqual(linkOrder([0, 0, 0, 0, 0, 0, 0, 0]), [0, 1, 2, 3, 4, 5, 6, 7]);
    // In1 + In5 and In3 + In8: each group takes the place of its lowest member
    assert.deepEqual(linkOrder([1, 0, 2, 0, 1, 0, 0, 2]), [0, 4, 1, 2, 7, 3, 5, 6]);
    assert.deepEqual(linkOrder(linkGroups(side(0, 0, 0b111))), [0, 1, 2, 3, 4, 5, 6, 7]);
});

test('the stored link view migrates the old side-by-side checkbox', () => {
    assert.deepEqual(linkView(null), { code: true, colour: false, shape: false, placement: 'normal', names: {} });
    // Before 0.30.0 a group without the code showed its shape
    assert.deepEqual(linkView({ code: false, colour: true, columns: true }), { code: false, colour: true, shape: true, placement: 'side', names: {} });
    assert.equal(linkView({ code: false, shape: false }).shape, false);
    assert.deepEqual(linkView({ columns: true, placement: 'stacked' }).placement, 'stacked');
    assert.deepEqual(linkView({ placement: 'diagonal' }).placement, 'normal');
});

test('a link layout places the channels per placement', () => {
    // In1 + In5 and In3 + In4 + In8
    const groups = [1, 0, 2, 2, 1, 0, 0, 2];
    const at = (layout) => layout.cells.map((c) => [c.column, c.row, c.span]);
    assert.deepEqual(at(linkLayout(groups, 'normal')), [[1, 1, 1], [2, 1, 1], [3, 1, 1], [4, 1, 1], [5, 1, 1], [6, 1, 1], [7, 1, 1], [8, 1, 1]]);
    // Side by side: In1 In5 In2 In3 In4 In8 In6 In7
    assert.deepEqual(linkLayout(groups, 'side').cells.map((c) => c.column), [1, 3, 4, 5, 2, 7, 8, 6]);
    const stacked = linkLayout(groups, 'stacked');
    assert.equal(stacked.columns, 5);
    assert.equal(stacked.rows, 6);
    // Columns: In1/In5, In2, In3/In4/In8, In6, In7. Six rows: a pair spans 3 each, a trio 2 each, a single all 6
    assert.deepEqual(at(stacked), [[1, 1, 3], [2, 1, 6], [3, 1, 2], [3, 3, 2], [1, 4, 3], [4, 1, 6], [5, 1, 6], [3, 5, 2]]);
    assert.deepEqual(linkLayout([0, 0, 0, 0, 0, 0, 0, 0], 'stacked').columns, 8);
});

test('a click in the Link matrix links two channels and merges their groups', () => {
    const none = [0, 0, 0, 0, 0, 0, 0, 0];
    assert.deepEqual(toggleLink(none, 0, 1), [1, 1, 0, 0, 0, 0, 0, 0]);
    // In3 joins In1 + In2, from either half of the matrix
    assert.deepEqual(toggleLink([1, 1, 0, 0, 0, 0, 0, 0], 2, 1), [1, 1, 1, 0, 0, 0, 0, 0]);
    assert.deepEqual(toggleLink([1, 1, 0, 0, 0, 0, 0, 0], 0, 2), [1, 1, 1, 0, 0, 0, 0, 0]);
    // Two groups merge, and the groups are numbered by their lowest member again
    assert.deepEqual(toggleLink([0, 0, 0, 1, 1, 2, 2, 0], 4, 6), [0, 0, 0, 1, 1, 1, 1, 0]);
    assert.deepEqual(toggleLink([0, 0, 0, 0, 0, 0, 1, 1], 0, 1), [1, 1, 0, 0, 0, 0, 2, 2]);
    // The diagonal does nothing
    assert.deepEqual(toggleLink(none, 3, 3), none);
});

test('a click on a lit cell takes the column channel out of its group', () => {
    assert.deepEqual(toggleLink([1, 1, 1, 0, 0, 0, 0, 0], 0, 2), [1, 1, 0, 0, 0, 0, 0, 0]);
    // A pair falls apart completely, and the next group moves up to L1
    assert.deepEqual(toggleLink([1, 1, 0, 0, 2, 2, 0, 0], 1, 0), [0, 0, 0, 0, 1, 1, 0, 0]);
});

test('link units list each group and each channel alone in link order', () => {
    // In1 + In5 and In3 + In4 + In8
    assert.deepEqual(linkUnits([1, 0, 2, 2, 1, 0, 0, 2]), [[0, 4], [1], [2, 3, 7], [5], [6]]);
    assert.deepEqual(linkUnits([0, 0, 0, 0, 0, 0, 0, 0]), [[0], [1], [2], [3], [4], [5], [6], [7]]);
});

test('a link mark shows the code, the shape or both', () => {
    const shape = '<span class="link-shape" data-shape="2" aria-hidden="true"></span>';
    assert.equal(linkMark({ code: true, shape: false }, 2), 'L2');
    assert.equal(linkMark({ code: false, shape: true }, 2), shape);
    assert.equal(linkMark({ code: true, shape: true }, 2), `L2${shape}`);
    assert.equal(linkMark({ code: false, shape: false }, 2), '');
    assert.equal(linkMark({ code: true, shape: true }, 0), '');
});

test('the least common multiple sizes the stacked rows', () => {
    assert.equal(lcm(1, 1), 1);
    assert.equal(lcm(2, 3), 6);
    assert.equal(lcm(4, 6), 12);
    assert.equal(lcm(5, 3), 15);
});

test('linkPlacement keeps channel order, or groups members, with blocks only when stacked', () => {
    const groups = [1, 0, 2, 1, 0, 2, 2, 0];
    assert.deepEqual(linkPlacement(groups, 'normal'), { order: [0, 1, 2, 3, 4, 5, 6, 7], blocks: [] });
    assert.deepEqual(linkPlacement(groups, 'side'), { order: [0, 3, 1, 2, 5, 6, 4, 7], blocks: [] });
    assert.deepEqual(linkPlacement(groups, 'stacked'), { order: [0, 3, 1, 2, 5, 6, 4, 7], blocks: [[0, 3], [2, 5, 6]] });
});

test('linkRuns heads each stacked group once and leaves every other channel alone', () => {
    const groups = [1, 0, 2, 1, 0, 2, 2, 0];
    assert.deepEqual(linkRuns(groups, 'stacked'), [
        { group: 1, channels: [0, 3] }, { group: 0, channels: [1] }, { group: 2, channels: [2, 5, 6] },
        { group: 0, channels: [4] }, { group: 0, channels: [7] },
    ]);
    assert.deepEqual(linkRuns(groups, 'side').map((run) => run.group), [0, 0, 0, 0, 0, 0, 0, 0]);
    assert.deepEqual(linkRuns(groups, 'normal').map((run) => run.channels[0]), [0, 1, 2, 3, 4, 5, 6, 7]);
});

test('linkGaps separates link groups from their neighbours only when stacked', () => {
    const groups = [1, 0, 2, 1, 0, 2, 2, 0];
    // Order 0 3 | 1 | 2 5 6 | 4 7
    assert.deepEqual([...linkGaps(groups, 'stacked')], [1, 2, 4]);
    assert.deepEqual([...linkGaps(groups, 'side')], []);
    assert.deepEqual([...linkGaps([0, 0, 0, 0, 0, 0, 0, 0], 'stacked')], []);
});

test('a group name replaces the code, escaped, and stays with the group by its lowest channel', () => {
    const view = linkView({ names: { 'inputs:2': '  Stage <L>  ', 'outputs:9': 'x', 'inputs:3': '', 'inputs:4': 'A very long group name' } });
    assert.deepEqual(view.names, { 'inputs:2': 'Stage <L>', 'inputs:4': 'A very long ' });
    const groups = [0, 0, 1, 0, 1, 0, 0, 0];
    assert.equal(linkNameKey('inputs', groups, 1), 'inputs:2');
    assert.equal(linkName(view, 'inputs', groups, 1), 'Stage <L>');
    assert.equal(linkName(view, 'outputs', groups, 1), '');
    assert.equal(linkName(view, 'inputs', groups, 0), '');
    assert.equal(linkMark(view, 1, 'Stage <L>'), '<span class="link-label" title="Stage &#60;L&#62;">Stage &#60;L&#62;</span>');
    assert.equal(linkMark({ ...view, shape: true }, 1, 'Stage'), '<span class="link-label" title="Stage">Stage</span><span class="link-shape" data-shape="1" aria-hidden="true"></span>');
    assert.equal(linkMark({ ...view, code: false }, 1, 'Stage'), '');
});

test('a Link matrix layout falls back to the lower half', () => {
    assert.deepEqual(LINK_MATRIX_LAYOUTS, ['lower', 'upper', 'full']);
    assert.equal(linkMatrixLayout('upper'), 'upper');
    assert.equal(linkMatrixLayout('full'), 'full');
    assert.equal(linkMatrixLayout(undefined), 'lower');
    assert.equal(linkMatrixLayout('diagonal'), 'lower');
});

test('each half shows every channel pair once, by display position, and mirrors the other', () => {
    const order = linkPlacement([1, 0, 2, 1, 0, 2, 2, 0], 'side').order;
    const cells = (layout) => order.flatMap((row) => order.filter((column) => linkCellShown(order, row, column, layout)).map((column) => [row, column]));
    assert.equal(cells('lower').length, 28);
    assert.equal(cells('upper').length, 28);
    assert.equal(cells('full').length, 64);
    assert.deepEqual(cells('upper').map(([r, c]) => `${c},${r}`).sort(), cells('lower').map(([r, c]) => `${r},${c}`).sort());
    for (const layout of ['lower', 'upper']) {
        assert.ok(order.every((i) => !linkCellShown(order, i, i, layout)));
    }
    // Side by side puts In4 (3) right after In1 (0): the lower half has the cell in In4's row
    assert.equal(linkCellShown(order, 3, 0, 'lower'), true);
    assert.equal(linkCellShown(order, 3, 1, 'lower'), false);
});

test('a lit cell of a half takes the later channel out, the full matrix the column channel', () => {
    const groups = [0, 0, 0, 0, 1, 0, 1, 1];
    // The lower half has no cell in In8's column, yet In8 leaves through its row
    assert.deepEqual(toggleLinkCell(groups, 7, 4, 'lower'), [0, 0, 0, 0, 1, 0, 1, 0]);
    assert.deepEqual(toggleLinkCell(groups, 4, 7, 'upper'), [0, 0, 0, 0, 1, 0, 1, 0]);
    assert.deepEqual(toggleLinkCell(groups, 7, 4, 'full'), toggleLink(groups, 7, 4));
    assert.deepEqual(toggleLinkCell(groups, 7, 4, 'full'), [0, 0, 0, 0, 0, 0, 1, 1]);
    // An empty cell links in every layout
    assert.deepEqual(toggleLinkCell(groups, 2, 0, 'lower'), [1, 0, 1, 0, 2, 0, 2, 2]);
});

test('the focus of a cell names every channel a click would change', () => {
    const groups = [1, 0, 1, 0, 2, 0, 2, 2];
    // Linking In2 into In1 + In3 changes all three
    assert.deepEqual(linkFocus(groups, 1, 0, 'lower'), [0, 1, 2]);
    // Merging two groups changes all five
    assert.deepEqual(linkFocus(groups, 4, 0, 'upper'), [0, 2, 4, 6, 7]);
    // In8 leaving In5 + In7 + In8 changes all three, a pair dissolving both
    assert.deepEqual(linkFocus(groups, 7, 6, 'lower'), [4, 6, 7]);
    assert.deepEqual(linkFocus(groups, 2, 0, 'lower'), [0, 2]);
    // A header names its channel and its group
    assert.deepEqual(linkFocus(groups, 6), [4, 6, 7]);
    assert.deepEqual(linkFocus(groups, 3), [3]);
});
