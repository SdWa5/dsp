import assert from 'node:assert/strict';
import { test } from 'node:test';
import { getPath, setPath, Store } from '../lib/store.js';

test('paths', () => {
    const o = { inputs: [{ gain: 1, peq: [{ freq: 5 }] }] };
    assert.equal(getPath(o, 'inputs.0.peq.0.freq'), 5);
    assert.equal(getPath(o, 'inputs.3.gain'), undefined);
    setPath(o, 'inputs.0.gain', 9);
    assert.equal(o.inputs[0].gain, 9);
});

test('recent local edits win over incoming state, old ones do not', () => {
    let now = 0;
    const store = new Store(1000, () => now);
    const seen = [];
    store.subscribe((s) => seen.push(s.outputs[0].gain));
    store.replace({ outputs: [{ gain: 280 }] });
    store.set('outputs.0.gain', 300);
    now = 500;
    store.replace({ outputs: [{ gain: 280 }] });
    assert.equal(store.get('outputs.0.gain'), 300);
    now = 2000;
    store.replace({ outputs: [{ gain: 280 }] });
    assert.equal(store.get('outputs.0.gain'), 280);
    assert.deepEqual(seen, [280, 300, 280]);
});

test('no state, no edits', () => {
    const store = new Store();
    store.set('a', 1);
    assert.equal(store.get('a'), undefined);
});
