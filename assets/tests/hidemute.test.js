import assert from 'node:assert/strict';
import { test } from 'node:test';
import { hideMuteView, hidingMutes, mutePath, getHideMute, setHideMute, onHideMute } from '../lib/hidemute.js';

const states = (muted) => (i) => (i in muted ? muted[i] : false);

test('the option starts off and keeps only its switch', () => {
    assert.deepEqual(hideMuteView(null), { on: false });
    assert.deepEqual(hideMuteView({ on: 'yes', muted: [3] }), { on: false });
    assert.deepEqual(hideMuteView({ on: true, muted: [3] }), { on: true });
});

test('mute paths cover inputs, outputs and Aux', () => {
    assert.equal(mutePath(0), 'inputs.0.mute');
    assert.equal(mutePath(15), 'outputs.7.mute');
    assert.equal(mutePath(18), 'aux.mute');
});

test('hiding a playing channel mutes it, a channel already muted or of unknown state is left alone', () => {
    assert.deepEqual(hidingMutes({ hidden: [], on: true }, { hidden: [1, 2, 3, 18], on: true }, (i) => (2 === i ? true : 3 === i ? null : false)), [1, 18]);
});

test('showing a channel again or switching the option off never unmutes', () => {
    assert.deepEqual(hidingMutes({ hidden: [1, 18], on: true }, { hidden: [18], on: true }, states({ 1: true, 18: true })), []);
    assert.deepEqual(hidingMutes({ hidden: [4, 9], on: true }, { hidden: [4, 9], on: false }, states({ 4: true, 9: true })), []);
});

test('switching the option on mutes every hidden channel, and one unmuted by hand later stays unmuted', () => {
    assert.deepEqual(hidingMutes({ hidden: [4, 9], on: false }, { hidden: [4, 9], on: true }, states({})), [4, 9]);
    // Unmuted by hand while hidden: a later change of another channel does not mute it again
    assert.deepEqual(hidingMutes({ hidden: [4, 9], on: true }, { hidden: [4, 9, 12], on: true }, states({ 4: false })), [12]);
});

test('with the option off hiding writes nothing', () => {
    assert.deepEqual(hidingMutes({ hidden: [], on: false }, { hidden: [3], on: false }, states({})), []);
});

test('the option is kept per browser and reaches every listener', () => {
    const items = new Map();
    globalThis.localStorage = { getItem: (k) => items.get(k) ?? null, setItem: (k, v) => items.set(k, String(v)) };
    try {
        assert.deepEqual(getHideMute(), { on: false });
        const seen = [];
        const off = onHideMute((view) => seen.push(view));
        setHideMute({ on: true });
        off();
        assert.deepEqual(seen, [{ on: true }]);
        assert.equal(items.get('trackdsp-hidden-mute'), '{"on":true}');
    } finally {
        delete globalThis.localStorage;
    }
});
