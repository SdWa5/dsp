import assert from 'node:assert/strict';
import { test } from 'node:test';
import { changedPaths, factoryValue, isChanged, pageChanges, referenceDefaults } from '../lib/defaults.js';

const defaults = {
    inputs: [{ name: 'In1', gain: 0, link: 1, peq: [{ gain: 120, bypass: false }] }],
    outputs: [{ route: 0b1, mute: false }],
};

test('scalars and missing defaults', () => {
    const state = { inputs: [{ name: 'In1', gain: 220, link: 1, phantom: true, peq: [{ gain: 120, bypass: false }] }] };
    assert.equal(isChanged(state, defaults, 'inputs.0.gain'), true);
    assert.equal(isChanged(state, defaults, 'inputs.0.name'), false);
    assert.equal(isChanged(state, defaults, 'inputs.0.phantom'), false);
    assert.equal(isChanged(null, defaults, 'inputs.0.gain'), false);
    assert.equal(isChanged({ inputs: [{ gain: null }] }, defaults, 'inputs.0.gain'), false);
});

test('one bit of a mask', () => {
    const state = { outputs: [{ route: 0b101, mute: false }] };
    assert.equal(isChanged(state, defaults, 'outputs.0.route', 0), false);
    assert.equal(isChanged(state, defaults, 'outputs.0.route', 1), false);
    assert.equal(isChanged(state, defaults, 'outputs.0.route', 2), true);
    assert.equal(isChanged(state, defaults, 'outputs.0.route'), true);
});

test('changed leaves of a channel', () => {
    const state = { name: 'Kick', gain: 0, link: 1, peq: [{ gain: 132, bypass: false }], extra: 5 };
    assert.deepEqual(changedPaths(state, defaults.inputs[0], 'inputs.0'), ['inputs.0.name', 'inputs.0.peq.0.gain']);
    assert.deepEqual(changedPaths({ ...defaults.inputs[0] }, defaults.inputs[0]), []);
    assert.deepEqual(changedPaths(undefined, defaults.inputs[0]), []);
});

const factory = {
    inputs: [{ gain: 280, phase: false, mute: false, mode: 0, gate: { threshold: 0, attack: 1 }, peq: [{ gain: 120 }] }],
    outputs: [{ gain: 280, phase: false, mute: false, comp: { ratio: 0, threshold: 220 }, delay: 0, route: 1,
        peq: [{ gain: 120, bypass: false }], eq_bypass: false, hpf: { freq: 100 } }],
    aux: { gain: 280, name: 'Aux', select: 0 },
    sub: [{ gain: 100, mask: 0 }],
    groups: [{ gain: 100, mute: false }],
    delay_unit: 0,
};

const changedPages = (state) => Object.entries(pageChanges(state, factory)).filter(([, paths]) => paths.length > 0).map(([page]) => page);

test('every function page marks its own changed controls', () => {
    const changes = [
        ['inputs.0.gain', 220, ['gain']],
        ['inputs.0.mode', 1, ['gain']],
        ['inputs.0.gate.threshold', 120, ['gate']],
        ['outputs.0.comp.ratio', 1, ['comp']],
        ['outputs.0.delay', 480, ['delay']],
        ['delay_unit', 1, ['delay']],
        ['outputs.0.route', 3, ['matrix']],
        ['aux.select', 3, ['matrix', 'aux']],
        ['outputs.0.peq.0.gain', 150, ['autoeq']],
        ['outputs.0.peq.0.bypass', true, ['autoeq']],
        ['outputs.0.eq_bypass', true, ['autoeq']],
        ['sub.0.mask', 3, ['gain']],
        ['groups.0.mute', true, ['gain']],
    ];
    for (const [path, value, pages] of changes) {
        const state = structuredClone(factory);
        const keys = path.split('.');
        const leaf = keys.pop();
        keys.reduce((node, key) => node[key], state)[leaf] = value;
        assert.deepEqual(changedPages(state), pages, path);
    }
});

test('page counts include every changed leaf and clear after restoring defaults', () => {
    const state = structuredClone(factory);
    state.inputs[0].gain = 220;
    state.outputs[0].mute = true;
    state.groups[0].mute = true;
    state.outputs[0].comp.ratio = 1;
    const pages = pageChanges(state, factory);
    assert.deepEqual(pages.gain, ['inputs.0.gain', 'outputs.0.mute', 'groups.0.mute']);
    assert.deepEqual(pages.comp, ['outputs.0.comp.ratio']);
    assert.equal(pages.matrix.length, 0);
    assert.deepEqual(changedPages(structuredClone(factory)), []);
    assert.deepEqual(changedPages(null), []);
});

test('display preferences, unrelated fields and unreported values do not mark pages', () => {
    const state = structuredClone(factory);
    state.inputs[0].gain = null;
    state.inputs[0].phantom = true;
    state.inputs[0].peq[0].gain = 150;
    state.outputs[0].hpf.freq = 200;
    state.link_view = { colour: true };
    assert.deepEqual(changedPages(state), []);
    assert.deepEqual(pageChanges(state, {}), { gain: [], gate: [], comp: [], delay: [], matrix: [], aux: [], autoeq: [] });
});

test('link membership never counts as changed', () => {
    const state = { inputs: [{ name: 'In1', gain: 0, link: 3, peq: [{ gain: 120, bypass: false }] }] };
    assert.equal(isChanged(state, defaults, 'inputs.0.link'), false);
    assert.deepEqual(changedPaths(state.inputs[0], defaults.inputs[0], 'inputs.0'), []);
});

test('the reference defaults put every input gain at 0 dB and leave the rest', () => {
    const factory = { inputs: [{ gain: 0, mute: false }, { gain: 0, mute: false }], outputs: [{ gain: 280 }], aux: { gain: 280 } };
    const reference = referenceDefaults(factory);
    assert.deepEqual(reference.inputs.map((channel) => channel.gain), [280, 280]);
    assert.equal(reference.inputs[0].mute, false);
    assert.equal(reference.outputs, factory.outputs);
    assert.equal(factory.inputs[0].gain, 0);
    assert.equal(isChanged({ inputs: [{ gain: 280 }] }, reference, 'inputs.0.gain'), false);
    assert.equal(isChanged({ inputs: [{ gain: 0 }] }, reference, 'inputs.0.gain'), true);
    assert.deepEqual(referenceDefaults({}), {});
});

test('the value a reset writes, whole or one bit of a mask', () => {
    assert.equal(factoryValue(defaults, 'inputs.0.gain', 220), 0);
    assert.equal(factoryValue(defaults, 'outputs.0.route', 0b110, 0), 0b111);
    assert.equal(factoryValue(defaults, 'outputs.0.route', 0b111, 2), 0b011);
    assert.equal(factoryValue(defaults, 'inputs.0.phantom', true), null);
});
