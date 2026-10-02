import assert from 'node:assert/strict';
import { test } from 'node:test';
import { getMeterView, onMeterView, setMeterView } from '../lib/meterview.js';

test('the threshold display settings reach every listener and are kept', () => {
    const items = new Map();
    globalThis.localStorage = { getItem: (k) => items.get(k) ?? null, setItem: (k, v) => items.set(k, String(v)) };
    assert.deepEqual(getMeterView(), { show: true, active: true });
    const seen = [];
    const off = onMeterView((view) => seen.push(view));
    setMeterView({ show: true, active: false });
    off();
    setMeterView({ show: false });
    assert.deepEqual(seen, [{ show: true, active: false }]);
    assert.deepEqual(getMeterView(), { show: false, active: true });
    assert.equal(items.get('trackdsp-meter-threshold'), '{"show":false,"active":true}');
    delete globalThis.localStorage;
});
