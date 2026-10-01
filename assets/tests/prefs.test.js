import assert from 'node:assert/strict';
import { afterEach, test } from 'node:test';
import { readPref, writePref } from '../lib/prefs.js';

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
});
