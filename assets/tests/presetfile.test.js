import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { t } from '../lib/i18n.js';

// Exercise the actual controller's async workflow without a browser or Stimulus dependency in Node.
// Projection has separate tests; API calls, confirmation and live state are injected here.
const source = readFileSync(new URL('../controllers/presetfile_controller.js', import.meta.url), 'utf8')
    .replace(/^import .*;\n/gm, '').replace('export default class', 'return class');
const controllerClass = new Function('Controller', 'api', 'getLabels', 'on', 'status', 'store', 'presetSections', 't', source);
function preview(api, connected = true) {
    const status = { connected };
    const store = { state: connected ? {} : null, subscribe: () => () => {} };
    const Controller = controllerClass(class {}, api, () => ({}), () => () => {}, status, store,
        () => [], t);
    const controller = new Controller();
    const target = () => ({ textContent: '', value: '', children: [],
        replaceChildren() { this.children = []; }, append(child) { this.children.push(child); } });
    controller.nameTarget = target();
    controller.messageTarget = target();
    controller.sectionTarget = target();
    controller.rowsTarget = target();
    controller.applyTarget = { disabled: true };
    controller.element = { open: false, showModal() { this.open = true; }, close() { this.open = false; } };
    controller.connect();
    return { controller, status, store };
}
const file = { name: 'fixture.prs' };

test('opening offline only inspects, rejects stale responses, and clears failed previews', async () => {
    const requests = [];
    const { controller, status, store } = preview((path, body) => new Promise((resolve, reject) => requests.push({ path, body, resolve, reject })), false);
    const first = controller.open({ detail: { file } });
    const secondFile = { name: 'second.json' };
    const second = controller.open({ detail: { file: secondFile } });
    assert.deepEqual(requests.map(({ path }) => path), ['preset', 'preset']);
    assert.equal(controller.applyTarget.disabled, true);
    requests[1].resolve({ device: {}, riders: [] });
    await second;
    requests[0].resolve({ device: {}, riders: [] });
    await first;
    assert.equal(controller.file, secondFile);
    assert.equal(controller.applyTarget.disabled, true);
    assert.equal(store.state, null);
    status.connected = true;
    store.state = {};
    controller.connection();
    assert.equal(controller.applyTarget.disabled, false);
    const bad = controller.open({ detail: { file: { name: 'bad.json' } } });
    assert.equal(controller.file, null);
    assert.equal(controller.applyTarget.disabled, true);
    requests[2].reject(new Error('Not a valid preset file'));
    await bad;
    assert.equal(controller.file, null);
    assert.equal(controller.applyTarget.disabled, true);
    assert.equal(controller.messageTarget.textContent, 'Not a valid preset file');
    assert.equal(requests.some(({ path }) => path === 'upload'), false);
    controller.disconnect();
});

test('Apply needs connection and an explicit confirmation before uploading the original file', async () => {
    const calls = [];
    const { controller, status } = preview(async (path, body) => { calls.push([path, body]); return { device: {}, riders: [] }; });
    const oldWindow = globalThis.window;
    try {
        globalThis.window = { confirm: () => false };
        await controller.open({ detail: { file } });
        await controller.apply();
        assert.deepEqual(calls, [['preset', file]]);
        globalThis.window.confirm = () => true;
        status.connected = false;
        controller.connection();
        await controller.apply();
        assert.deepEqual(calls, [['preset', file]]);
        status.connected = true;
        controller.connection();
        await controller.apply();
        assert.deepEqual(calls, [['preset', file], ['upload', file], ['refresh', undefined]]);
        assert.equal(controller.element.open, false);
    } finally {
        if (undefined === oldWindow) delete globalThis.window;
        else globalThis.window = oldWindow;
        controller.disconnect();
    }
});
