import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readPref, writePref } from '../lib/prefs.js';
import { applyChangeView, changedTitle, changeView, getChangeView, onChangeView, setChangeView } from '../lib/changeview.js';

test('marks and counts show by default and ignore malformed values', () => {
    assert.deepEqual(changeView(undefined), { marks: true, counts: true });
    assert.deepEqual(changeView('garbage'), { marks: true, counts: true });
    assert.deepEqual(changeView({ marks: 'no', counts: 0 }), { marks: true, counts: true });
    assert.deepEqual(changeView({ marks: false, counts: true }), { marks: false, counts: true });
});

test('a change is stored per browser and reaches every listener', () => {
    const seen = [];
    const off = onChangeView((view) => seen.push(view));
    setChangeView({ marks: true, counts: false });
    off();
    assert.deepEqual(readPref('changed-view', {}), { marks: true, counts: false });
    assert.deepEqual(getChangeView(), { marks: true, counts: false });
    assert.deepEqual(seen, [{ marks: true, counts: false }]);
    setChangeView({});
    writePref('changed-view', {});
});

test('the tooltip follows the counts, then the marks', () => {
    assert.equal(changedTitle(3, { marks: true, counts: true }), '3 settings differ from the factory default');
    assert.equal(changedTitle(1, { marks: false, counts: true }), '1 setting differs from the factory default');
    assert.equal(changedTitle(3, { marks: true, counts: false }), 'Settings differ from the factory default');
    assert.equal(changedTitle(3, { marks: false, counts: false }), '');
    assert.equal(changedTitle(0, { marks: true, counts: true }), '');
});

test('switching off sets a data attribute on the root', () => {
    const attributes = new Set();
    const root = { toggleAttribute: (name, on) => (on ? attributes.add(name) : attributes.delete(name)) };
    applyChangeView({ marks: false, counts: true }, root);
    assert.deepEqual([...attributes], ['data-changed-marks']);
    applyChangeView({ marks: true, counts: false }, root);
    assert.deepEqual([...attributes], ['data-changed-counts']);
    applyChangeView({ marks: true, counts: true }, root);
    assert.deepEqual([...attributes], []);
});
