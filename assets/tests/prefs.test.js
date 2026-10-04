import assert from 'node:assert/strict';
import { afterEach, beforeEach, test } from 'node:test';
let readPref, writePref, onPref;
let instance = 0;
beforeEach(async () => ({ readPref, writePref, onPref } = await import(`../lib/prefs.js?test=${instance++}`)));

const memory = () => {
    const items = new Map();
    return { getItem: (k) => items.get(k) ?? null, setItem: (k, v) => items.set(k, String(v)), items };
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
    assert.equal(globalThis.localStorage.items.get('trackdsp-theme'), 'dark');
    assert.equal(readPref('theme', 'auto'), 'dark');
    writePref('collapsed', { 'gain:Input': true });
    assert.deepEqual(readPref('collapsed', {}), { 'gain:Input': true });
    writePref('swapped', true);
    assert.equal(readPref('swapped', false), true);
});

test('a value of the wrong type falls back', () => {
    globalThis.localStorage = memory();
    globalThis.localStorage.setItem('trackdsp-swapped', '"yes"');
    assert.equal(readPref('swapped', false), false);
    globalThis.localStorage.setItem('trackdsp-collapsed', '{broken');
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
    globalThis.localStorage.setItem('trackdsp-collapsed', '[]');
    globalThis.localStorage.setItem('trackdsp-eq-overview-hidden', '{}');
    assert.deepEqual(readPref('collapsed', {}), {});
    assert.deepEqual(readPref('eq-overview-hidden', []), []);
});
