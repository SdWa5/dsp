import assert from 'node:assert/strict';
import { test } from 'node:test';
import { channelId, channelLabel, channelParts } from '../lib/channels.js';

const state = {
    inputs: [{ name: 'DJ L' }, { name: 'In2' }, { name: ' ' }, { name: 'in 4' }],
    outputs: [{ name: 'Main L' }, { name: 'Out2' }],
    aux: { name: 'FX' },
};

test('IDs of every channel index', () => {
    assert.equal(channelId(0), 'In1');
    assert.equal(channelId(7), 'In8');
    assert.equal(channelId(8), 'Out1');
    assert.equal(channelId(15), 'Out8');
    assert.equal(channelId(16), 'Aux');
    assert.equal(channelId(18), 'Aux');
});

test('the name goes next to the ID unless it is the default', () => {
    assert.equal(channelLabel(0, state), 'In1 (DJ L)');
    assert.equal(channelLabel(1, state), 'In2');
    assert.equal(channelLabel(2, state), 'In3');
    assert.equal(channelLabel(3, state), 'In4');
    assert.equal(channelLabel(8, state), 'Out1 (Main L)');
    assert.equal(channelLabel(9, state), 'Out2');
    assert.equal(channelLabel(18, state), 'Aux (FX)');
    assert.deepEqual(channelParts(0, null), { id: 'In1', name: '' });
    assert.deepEqual(channelParts(5, state), { id: 'In6', name: '' });
});
