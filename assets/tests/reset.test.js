import { test } from 'node:test';
import assert from 'node:assert/strict';
import { channelReset, isKept, pageReset, resetCommands } from '../lib/reset.js';

const channel = (extra = {}) => ({ name: 'In', gain: 280, mute: false, link: 0, gate: { threshold: 0, attack: 0, release: 0 }, ...extra });
const output = (extra = {}) => ({ name: 'Out', gain: 280, mute: false, link: 0, route: 1, delay: 0, comp: { ratio: 0, threshold: 0 }, ...extra });
const defaults = () => ({
    inputs: Array.from({ length: 8 }, () => channel()),
    outputs: Array.from({ length: 8 }, () => output()),
    aux: { name: 'Aux', gain: 280, select: 0 },
    sub: [{ gain: 100, mute: false, mask: 0 }, { gain: 100, mute: false, mask: 0 }],
    groups: [],
    delay_unit: 0,
});

test('names and link membership are kept', () => {
    assert.equal(isKept('inputs.0.name'), true);
    assert.equal(isKept('outputs.3.link'), true);
    assert.equal(isKept('inputs.0.gain'), false);
});

test('a channel reset covers its linked channels, the routing of the clicked one only, and keeps names', () => {
    const state = defaults();
    state.outputs[1] = output({ name: 'Sub', gain: 200, link: 0b110, route: 3, comp: { ratio: 4, threshold: 0 } });
    state.outputs[2] = output({ gain: 200, link: 0b110, route: 5 });
    const plan = channelReset(state, defaults(), 'outputs', 1);
    assert.deepEqual(plan.members, [1, 2]);
    assert.deepEqual(plan.writes, [
        { path: 'outputs.1.gain', value: 280 },
        { path: 'outputs.1.route', value: 1 },
        { path: 'outputs.1.comp.ratio', value: 0 },
        { path: 'outputs.2.gain', value: 280 },
    ]);
    assert.equal(plan.count, 4);
    assert.equal(plan.kept, 1);
    assert.equal(channelReset(defaults(), defaults(), 'inputs', 0).count, 0);
});

test('a page reset covers every channel of its page and nothing else', () => {
    const state = defaults();
    state.inputs[0].gate.threshold = 40;
    state.inputs[7].gate.attack = 3;
    state.inputs[2].gain = 100;
    assert.deepEqual(pageReset(state, defaults(), 'gate').writes, [
        { path: 'inputs.0.gate.threshold', value: 0 },
        { path: 'inputs.7.gate.attack', value: 0 },
    ]);
    state.outputs[4].delay = 96;
    state.delay_unit = 1;
    assert.deepEqual(pageReset(state, defaults(), 'delay').writes.map((write) => write.path), ['outputs.4.delay', 'delay_unit']);
    state.sub[1].mask = 4;
    assert.deepEqual(pageReset(state, defaults(), 'gain').writes.map((write) => write.path), ['inputs.2.gain', 'sub.1.mask']);
    state.aux.name = 'FX';
    state.aux.select = 2;
    const aux = pageReset(state, defaults(), 'aux');
    assert.deepEqual(aux.writes, [{ path: 'aux.select', value: 0 }]);
    assert.equal(aux.kept, 1);
    assert.equal(pageReset(state, defaults(), 'nope').count, 0);
});

test('reset commands: one per owning unit, its arguments read after the writes', () => {
    const units = [
        { command: 'gain', args: { channel: 8, raw: '@outputs.0.gain' } },
        { command: 'compressor', args: { channel: 8, ratio: '@outputs.0.comp.ratio', threshold: '@outputs.0.comp.threshold' } },
        { command: 'subControl', args: { io: 1, gainPercent: '@sub.1.gain', muted: '@sub.1.mute', mask: '@sub.1.mask', extra: '@sub_extra.1' } },
    ];
    const state = { outputs: [{ gain: 100, comp: { ratio: 4, threshold: 30 } }], sub: [{}, { gain: 50, mute: true, mask: 4 }], sub_extra: [0, 9] };
    const { commands, writes, unowned } = resetCommands(state, [
        { path: 'outputs.0.comp.ratio', value: 0 },
        { path: 'outputs.0.comp.threshold', value: 0 },
        { path: 'sub.1.mask', value: 0 },
        { path: 'outputs.0.gain', value: 280 },
        { path: 'outputs.0.nowhere', value: 1 },
    ], units);
    assert.deepEqual(commands, [
        { command: 'gain', args: { channel: 8, raw: 280 } },
        { command: 'compressor', args: { channel: 8, ratio: 0, threshold: 0 } },
        { command: 'subControl', args: { io: 1, gainPercent: 50, muted: true, mask: 0, extra: 9 } },
    ]);
    assert.equal(writes.length, 4);
    assert.deepEqual(unowned, ['outputs.0.nowhere']);
    // The state itself stays untouched until the caller writes it
    assert.equal(state.outputs[0].gain, 100);
});

test('an Aux reset covers the Aux alone and keeps its name', () => {
    const state = defaults();
    state.aux = { name: 'FX', gain: 200, select: 2 };
    const plan = channelReset(state, defaults(), 'aux', 0);
    assert.deepEqual(plan.writes, [{ path: 'aux.gain', value: 280 }, { path: 'aux.select', value: 0 }]);
    assert.equal(plan.kept, 1);
    assert.deepEqual(plan.members, [0]);
});
