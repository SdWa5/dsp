import assert from 'node:assert/strict';
import { test } from 'node:test';
import { linkCommands, linkGroups, linkLayout, linkedMembers, linkOrder, linkView, toggleLink } from '../lib/link.js';

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

test('linking copies the master to the new slave first', () => {
    const commands = linkCommands(side(), [1, 1, 0, 0, 0, 0, 0, 0], 8);
    assert.deepEqual(commands, [
        { command: 'copyChannel', args: { source: 8, target: 9 } },
        { command: 'link', args: { channel: 8, flags: 3 } },
        { command: 'link', args: { channel: 9, flags: 0 } },
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
    assert.deepEqual(linkView(null), { code: true, colour: false, placement: 'normal' });
    assert.deepEqual(linkView({ code: false, colour: true, columns: true }), { code: false, colour: true, placement: 'side' });
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
    assert.equal(stacked.rows, 3);
    // Columns: In1/In5, In2, In3/In4/In8, In6, In7. The last of a shorter group and every single channel span the rest
    assert.deepEqual(at(stacked), [[1, 1, 1], [2, 1, 3], [3, 1, 1], [3, 2, 1], [1, 2, 2], [4, 1, 3], [5, 1, 3], [3, 3, 1]]);
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
