import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { Store } from '../lib/store.js';
import { linkedMembers } from '../lib/link.js';
import { isChanged } from '../lib/defaults.js';
import { setMaskBits } from '../lib/channelview.js';

// Exercise aggregate selectors through the real bound-control command resolver and store notifications.
const body = path => readFileSync(new URL(path, import.meta.url), 'utf8')
    .replace(/^import .*;\n/gm, '').replace('export default class', 'return class');
function editor(state) {
    const store = new Store(); store.replace(state);
    const writes = [];
    const Bound = new Function('Controller', 'store', 'send', 'getDefaults', 'getLabels', 'linkedMembers', 'isChanged', 'format',
        body('../lib/bound_controller.js'))(class {}, store, commands => writes.push(...commands), () => state, () => ({}), linkedMembers, isChanged, () => '');
    const Selector = new Function('Controller', 'setMaskBits', body('../controllers/selectors_controller.js'))(class {}, setMaskBits);
    const aggregate = new Selector(); aggregate.render = () => {};
    const widgets = new Map();
    aggregate.application = { getControllerForElementAndIdentifier: element => widgets.get(element) };
    const choice = (bind, bit, send) => {
        const element = { disabled: false, matches: () => false };
        const widget = new Bound();
        Object.assign(widget, { element, bindValue: bind, bitValue: bit, linkValue: '', sendValue: send });
        widgets.set(element, widget);
        return { element };
    };
    return { store, writes, aggregate, choice };
}

test('a shared mask sends one combined write and retains unrelated membership bits', () => {
    const { store, writes, aggregate, choice } = editor({ aux: { select: 129 } });
    const command = [{ command: 'routing', args: { channel: 18, mask: '@aux.select' } }];
    const members = [choice('aux.select', 0, command), choice('aux.select', 2, command)];
    let notices = 0; store.subscribe(() => notices++);
    aggregate.select(members, true);
    assert.deepEqual(writes, [{ command: 'routing', args: { channel: 18, mask: 133 } }]);
    assert.equal(notices, 1);
    aggregate.select(members, false);
    assert.equal(store.get('aux.select'), 128);
    assert.equal(writes.length, 2);
    store.replace(null);
    aggregate.select(members, true);
    assert.equal(writes.length, 2);
});

test('Aux output selection preserves each output mask independently', () => {
    const { store, writes, aggregate, choice } = editor({ outputs: [{ route: 4097 }, { route: 128 }] });
    const members = [0, 1].map(i => choice(`outputs.${i}.route`, 8, [{ command: 'routing', args: { channel: 8 + i, mask: `@outputs.${i}.route` } }]));
    aggregate.select(members, true);
    assert.deepEqual(writes, [
        { command: 'routing', args: { channel: 8, mask: 4353 } },
        { command: 'routing', args: { channel: 9, mask: 384 } },
    ]);
    aggregate.select(members, false);
    assert.equal(store.get('outputs.0.route'), 4097);
    assert.equal(store.get('outputs.1.route'), 128);
});

test('Sub, Group, AutoMix and Camera aggregates preserve all other command arguments', () => {
    for (const [path, command, args] of [
        ['sub.0.mask', 'subControl', { io: 0, mask: '@sub.0.mask', gainPercent: '@sub.0.gain', muted: '@sub.0.mute', extra: '@sub_extra.0' }],
        ['groups.0.mask', 'group', { index: 0, mask: '@groups.0.mask', gainPercent: '@groups.0.gain', muted: '@groups.0.mute' }],
        ['aux.automix.select', 'mixFunction', { channel: 18, select: '@aux.automix.select', on: '@aux.automix.on', threshold: '@aux.automix.threshold', attack: '@aux.automix.attack', release: '@aux.automix.release' }],
        ['aux.camera.select', 'mixFunction', { channel: 19, select: '@aux.camera.select', on: '@aux.camera.on', threshold: '@aux.camera.threshold', attack: '@aux.camera.attack', release: '@aux.camera.release' }],
    ]) {
        const state = { sub: [{ mask: 32, gain: 65, mute: true }], sub_extra: [70], groups: [{ mask: 32, gain: 48, mute: false }], aux: { automix: { select: 32, on: true, threshold: 180, attack: 49, release: 299 }, camera: { select: 32, on: false, threshold: 60, attack: 9, release: 499 } } };
        const { store, writes, aggregate, choice } = editor(state);
        aggregate.select([choice(path, 0, [{ command, args }]), choice(path, 7, [{ command, args }])], true);
        assert.equal(writes.length, 1);
        assert.equal(store.get(path), 161);
        const maskKey = path.endsWith('.mask') ? 'mask' : 'select';
        assert.equal(writes[0].args[maskKey], 161);
        for (const [key, value] of Object.entries(args)) if (key !== maskKey) assert.equal(writes[0].args[key], typeof value === 'string' && value.startsWith('@') ? store.get(value.slice(1)) : value);
    }
});

test('Auto EQ aggregates update form choices without writing or submitting', () => {
    const { writes, aggregate } = editor({});
    const elements = [true, false].map(checked => ({ checked, matches: selector => selector === 'input' }));
    aggregate.select(elements.map(element => ({ element })), true);
    assert.ok(elements.every(element => element.checked));
    aggregate.select(elements.map(element => ({ element })), false);
    assert.ok(elements.every(element => !element.checked));
    assert.deepEqual(writes, []);
});

test('disabled or missing controls never produce a partial aggregate write', () => {
    const { writes, aggregate, choice } = editor({ aux: { select: 0 } });
    const members = [choice('aux.select', 0, []), choice('aux.select', 7, [])];
    members[1].element.disabled = true;
    aggregate.select(members, true);
    aggregate.select([{ element: { matches: () => false } }], true);
    assert.deepEqual(writes, []);
});


test('visibility group controls dispatch the existing preference action without device writes', () => {
    const { writes, aggregate } = editor({});
    let changes = 0;
    const elements = [true, false].map(checked => ({
        checked,
        matches: selector => selector === 'input' || selector.includes('[data-channelview-target=channel]'),
        dispatchEvent(event) { assert.equal(event.type, 'change'); assert.equal(event.bubbles, true); changes++; },
    }));
    aggregate.select(elements.map(element => ({ element })), true);
    assert.ok(elements.every(element => element.checked));
    assert.equal(changes, 1);
    aggregate.select(elements.map(element => ({ element })), false);
    assert.ok(elements.every(element => !element.checked));
    assert.equal(changes, 2);
    assert.deepEqual(writes, []);
});
