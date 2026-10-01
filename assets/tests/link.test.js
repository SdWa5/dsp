import assert from 'node:assert/strict';
import { test } from 'node:test';
import { linkCommands, linkedMembers, linkGroups, linkOrder } from '../lib/link.js';

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
