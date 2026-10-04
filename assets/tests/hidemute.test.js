import assert from 'node:assert/strict';
import { test } from 'node:test';
import { hideMuteView, hidingMutes, mutePath, getHideMute, setHideMute, onHideMute } from '../lib/hidemute.js';

const states = (muted) => (i) => (i in muted ? muted[i] : false);

test('the option starts off and a malformed record keeps channels only', () => {
    assert.deepEqual(hideMuteView(null), { on: false, muted: [] });
    assert.deepEqual(hideMuteView({ on: 'yes', muted: [18, 3, 3, 16, -1, '2', 15] }), { on: false, muted: [3, 15, 18] });
});

test('mute paths cover inputs, outputs and Aux', () => {
    assert.equal(mutePath(0), 'inputs.0.mute');
    assert.equal(mutePath(15), 'outputs.7.mute');
    assert.equal(mutePath(18), 'aux.mute');
});

test('hiding a playing channel mutes it and records it, showing it again unmutes it', () => {
    const hide = hidingMutes({ hidden: [], on: true }, { hidden: [1, 18], on: true }, [], states({}));
    assert.deepEqual(hide, { mute: [1, 18], unmute: [], muted: [1, 18] });
    const show = hidingMutes({ hidden: [1, 18], on: true }, { hidden: [18], on: true }, hide.muted, states({ 1: true, 18: true }));
    assert.deepEqual(show, { mute: [], unmute: [1], muted: [18] });
});

test('a channel muted before it was hidden stays muted when it shows again', () => {
    const hide = hidingMutes({ hidden: [], on: true }, { hidden: [2], on: true }, [], states({ 2: true }));
    assert.deepEqual(hide, { mute: [], unmute: [], muted: [] });
    const show = hidingMutes({ hidden: [2], on: true }, { hidden: [], on: true }, hide.muted, states({ 2: true }));
    assert.deepEqual(show, { mute: [], unmute: [], muted: [] });
});

test('switching the option on mutes every hidden channel, switching it off releases the record', () => {
    const on = hidingMutes({ hidden: [4, 9], on: false }, { hidden: [4, 9], on: true }, [], states({}));
    assert.deepEqual(on, { mute: [4, 9], unmute: [], muted: [4, 9] });
    // Someone unmuted 9 in between: it is released from the record but not written
    const off = hidingMutes({ hidden: [4, 9], on: true }, { hidden: [4, 9], on: false }, on.muted, states({ 4: true, 9: false }));
    assert.deepEqual(off, { mute: [], unmute: [4], muted: [] });
});

test('with the option off hiding writes nothing, and an unknown mute state is left alone', () => {
    assert.deepEqual(hidingMutes({ hidden: [], on: false }, { hidden: [3], on: false }, [], states({})), { mute: [], unmute: [], muted: [] });
    assert.deepEqual(hidingMutes({ hidden: [], on: true }, { hidden: [3], on: true }, [], () => null), { mute: [], unmute: [], muted: [] });
});

test('the option and its record are kept per browser and reach every listener', () => {
    const items = new Map();
    globalThis.localStorage = { getItem: (k) => items.get(k) ?? null, setItem: (k, v) => items.set(k, String(v)) };
    try {
        assert.deepEqual(getHideMute(), { on: false, muted: [] });
        const seen = [];
        const off = onHideMute((view) => seen.push(view));
        setHideMute({ on: true, muted: [5] });
        off();
        assert.deepEqual(seen, [{ on: true, muted: [5] }]);
        assert.equal(items.get('trackdsp-hidden-mute'), '{"on":true,"muted":[5]}');
    } finally {
        delete globalThis.localStorage;
    }
});
