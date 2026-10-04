import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { Store } from '../lib/store.js';
import { linkedMembers } from '../lib/link.js';
import { factoryValue, isChanged } from '../lib/defaults.js';

// Exercise the real shared toggle/write workflow with an isolated store and command sink.
const body = (path) => readFileSync(new URL(path, import.meta.url), 'utf8')
    .replace(/^import .*;\n/gm, '').replace('export default class', 'return class');
function editor(state) {
    const store = new Store();
    store.replace(state);
    const defaults = structuredClone(state);
    const writes = [];
    const Bound = new Function('Controller', 'store', 'send', 'getDefaults', 'getLabels', 'linkedMembers', 'isChanged', 'format', 'factoryValue',
        body('../lib/bound_controller.js'))(class {}, store, (commands) => writes.push(...commands), () => defaults,
        () => ({}), linkedMembers, isChanged, () => '', factoryValue);
    const Toggle = new Function('BoundController', 't', 'isChanged', 'getDefaults', 'store', 'GAIN_MUTED',
        body('../controllers/toggle_controller.js'))(Bound, (text) => text, isChanged, () => defaults, store, 0);
    const controls = [];
    const toggle = (bind, bit, channel) => {
        const widget = new Toggle();
        Object.assign(widget, { bindValue: bind, bitValue: bit, linkValue: '', enabledValue: '', mutedByValue: '',
            sendValue: [{ command: 'routing', args: { channel, mask: '@' + bind } }] });
        const classes = new Set();
        widget.element = { disabled: false, title: '', querySelectorAll: () => [], matches: () => true,
            classList: { toggle(name, on) { on ? classes.add(name) : classes.delete(name); }, contains: (name) => classes.has(name) },
            setAttribute() {} };
        widget.connect();
        controls.push(widget);
        return { widget, classes };
    };
    return { store, writes, toggle, close: () => controls.forEach((widget) => widget.disconnect()) };
}

test('all shared Aux views synchronize each boundary bit and preserve the remaining masks', () => {
    const state = { aux: { select: 1 << 3 }, outputs: [{ route: (1 << 0) | (1 << 7) | (1 << 12), link: 3 }, { route: 2, link: 0 }] };
    const { store, writes, toggle, close } = editor(state);
    try {
        for (const bit of [0, 7]) {
            const views = Array.from({ length: 4 }, () => toggle('aux.select', bit, 18));
            views[0].widget.click();
            assert.ok(views.every(({ classes }) => classes.has('is-on')));
            assert.equal(store.get('aux.select') & (1 << 3), 1 << 3);
            assert.deepEqual(writes.at(-1), { command: 'routing', args: { channel: 18, mask: store.get('aux.select') } });
            views[3].widget.click();
            assert.ok(views.every(({ classes }) => !classes.has('is-on')));
        }
        const views = Array.from({ length: 4 }, () => toggle('outputs.0.route', 8, 8));
        views[1].widget.click();
        assert.equal(store.get('outputs.0.route'), (1 << 0) | (1 << 7) | (1 << 8) | (1 << 12));
        assert.ok(views.every(({ classes }) => classes.has('is-on')));
        assert.equal(store.get('outputs.1.route'), 2);
        assert.equal(store.get('aux.select'), 1 << 3);
        assert.deepEqual(writes.at(-1), { command: 'routing', args: { channel: 8, mask: store.get('outputs.0.route') } });
        views[2].widget.click();
        assert.equal(store.get('outputs.0.route'), (1 << 0) | (1 << 7) | (1 << 12));
        assert.ok(views.every(({ classes }) => !classes.has('is-on')));
        // An independent incoming state update reaches every duplicated widget.
        store.replace({ aux: { select: 1 << 3 }, outputs: [{ route: (1 << 0) | (1 << 7) | (1 << 12) }, { route: 2 }] });
        assert.ok(views.every(({ classes }) => !classes.has('is-on')));
        store.replace(null);
        assert.ok(views.every(({ widget }) => widget.element.disabled));
        const count = writes.length;
        views[0].widget.click();
        assert.equal(writes.length, count);
    } finally {
        close();
    }
});

test('the changed mark resets one routing bit and keeps the rest of the mask', () => {
    const state = { aux: { select: 0 }, outputs: [{ route: 0b0101, link: 0 }] };
    const { store, writes, toggle, close } = editor(state);
    try {
        const { widget } = toggle('outputs.0.route', 1, 8);
        store.set('outputs.0.route', 0b1110);
        store.notify();
        assert.equal(widget.canReset(), true);
        widget.resetToDefault();
        assert.equal(store.get('outputs.0.route'), 0b1100);
        assert.deepEqual(writes.at(-1), { command: 'routing', args: { channel: 8, mask: 0b1100 } });
        const count = writes.length;
        widget.resetToDefault();
        assert.equal(writes.length, count);
    } finally {
        close();
    }
});
