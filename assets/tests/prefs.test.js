import assert from 'node:assert/strict';
import { afterEach, beforeEach, test } from 'node:test';
let readPref, writePref, onPref;
let instance = 0;
beforeEach(async () => ({ readPref, writePref, onPref } = await import(`../lib/prefs.js?test=${instance++}`)));

const memory = () => {
    const items = new Map();
    return { getItem: (k) => items.get(k) ?? null, setItem: (k, v) => items.set(k, String(v)), removeItem: (k) => items.delete(k), items };
};

afterEach(() => {
    delete globalThis.localStorage;
});

test('falls back without storage', () => {
    assert.equal(readPref('theme', 'auto'), 'auto');
    writePref('theme', 'dark');
    assert.equal(readPref('theme', 'auto'), 'dark');
});

test('strings stay raw, other values round-trip as JSON', () => {
    globalThis.localStorage = memory();
    writePref('theme', 'dark');
    assert.equal(globalThis.localStorage.items.get('sdwa5-dsp-theme'), 'dark');
    assert.equal(readPref('theme', 'auto'), 'dark');
    writePref('collapsed', { 'gain:Input': true });
    assert.deepEqual(readPref('collapsed', {}), { 'gain:Input': true });
    writePref('swapped', true);
    assert.equal(readPref('swapped', false), true);
});

test('a value of the wrong type falls back', () => {
    globalThis.localStorage = memory();
    globalThis.localStorage.setItem('sdwa5-dsp-swapped', '"yes"');
    assert.equal(readPref('swapped', false), false);
    globalThis.localStorage.setItem('sdwa5-dsp-collapsed', '{broken');
    assert.deepEqual(readPref('collapsed', {}), {});
});

test('blocked storage falls back and does not throw', () => {
    globalThis.localStorage = { getItem() { throw new Error('blocked'); }, setItem() { throw new Error('blocked'); } };
    assert.equal(readPref('theme', 'auto'), 'auto');
    writePref('theme', 'dark');
    assert.equal(readPref('theme', 'auto'), 'dark');
});

test('notifications synchronize subscribed keys and stop after cleanup', () => {
    const seen = [];
    const off = onPref(['theme', 'matrix-swapped'], key => seen.push([key, readPref(key, key === 'theme' ? 'auto' : false)]));
    writePref('theme', 'light'); writePref('unrelated', true); writePref('matrix-swapped', true);
    assert.deepEqual(seen, [['theme', 'light'], ['matrix-swapped', true]]);
    off(); writePref('theme', 'dark'); assert.equal(seen.length, 2);
});

test('a failed write supersedes a stale stored value for this visit', () => {
    globalThis.localStorage = { getItem: () => 'light', setItem() { throw new Error('quota'); } };
    writePref('theme', 'dark'); assert.equal(readPref('theme', 'auto'), 'dark');
});

test('arrays and records do not masquerade as one another', () => {
    globalThis.localStorage = memory();
    globalThis.localStorage.setItem('sdwa5-dsp-collapsed', '[]');
    globalThis.localStorage.setItem('sdwa5-dsp-eq-overview-hidden', '{}');
    assert.deepEqual(readPref('collapsed', {}), {});
    assert.deepEqual(readPref('eq-overview-hidden', []), []);
});

test('a value stored under the trackdsp prefix is read once and moved to the new key', () => {
    globalThis.localStorage = memory();
    globalThis.localStorage.setItem('trackdsp-channel-display', '{"display":"groups"}');
    assert.deepEqual(readPref('channel-display', {}), { display: 'groups' });
    assert.equal(globalThis.localStorage.items.get('sdwa5-dsp-channel-display'), '{"display":"groups"}');
    assert.equal(globalThis.localStorage.items.has('trackdsp-channel-display'), false);
});

test('the new key wins over a leftover trackdsp key', () => {
    globalThis.localStorage = memory();
    globalThis.localStorage.setItem('trackdsp-theme', 'light');
    globalThis.localStorage.setItem('sdwa5-dsp-theme', 'dark');
    assert.equal(readPref('theme', 'auto'), 'dark');
});
