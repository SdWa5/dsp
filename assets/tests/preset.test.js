import { test } from 'node:test';
import assert from 'node:assert/strict';
import { presetSections } from '../lib/preset.js';

const labels = { COMP_RATIOS: ['1:1.0', 'Limit'], PEQ_TYPES: ['Peak'], SLOPES: ['Bypass', 'BW -6'],
    DELAY_UNITS: ['ms', 'm', 'ft'] };
const band = () => ({ gain: 120, freq: 0, q: 0, type: 0, bypass: false });
const dynamics = () => ({ threshold: 220, attack: 0, release: 2999, ratio: 1 });
const channel = () => ({ name: '', gain: 280, mute: false, eq_bypass: null, phase: false, link: 0,
    hpf: { freq: 0, slope: 0 }, lpf: { freq: 300, slope: 1 }, gate: dynamics(), comp: dynamics(),
    delay: 32640, route: 256, peq: [band()], });
function state() {
    return { preset_name: 'Offline file', inputs: Array.from({ length: 8 }, channel),
        outputs: Array.from({ length: 8 }, channel), aux: { ...channel(), select: 1,
            automix: { ...dynamics(), select: 1, on: false }, camera: { ...dynamics(), select: 128, on: true },
            effect: { on: null, time: 139 }, }, delay_unit: null,
        sub: [{ gain: 0, mute: true, mask: 1 }, { gain: 100, mute: false, mask: 128 }], groups: [], };
}
const value = (section, key) => section.rows.find(([label]) => label === key)?.[1];

test('a preview covers shared channel boundaries and uses file values without mutation', () => {
    const device = state();
    device.inputs[0].gain = 0;
    device.inputs[0].link = 129;
    device.inputs[7].gain = 400;
    device.outputs[0].peq[0] = { ...band(), freq: 300, q: 100, gain: 240, bypass: true };
    device.outputs[7].comp.threshold = 0;
    const before = structuredClone(device);
    const sections = presetSections(device, labels);
    assert.equal(sections[1].label, 'In1');
    assert.equal(sections[8].label, 'In8');
    assert.equal(sections[9].label, 'Out1');
    assert.equal(sections[16].label, 'Out8');
    assert.equal(sections[17].label, 'Aux');
    assert.equal(value(sections[1], 'Gain'), 'Muted');
    assert.equal(value(sections[1], 'Link'), 'In1, In8');
    assert.equal(value(sections[8], 'Link'), 'In1, In8');
    assert.equal(value(sections[9], 'Link'), 'none');
    assert.equal(value(sections[8], 'Gain'), '+12.0dB');
    assert.equal(value(sections[9], 'Band 1 · Frequency'), '20.16KHz');
    assert.equal(value(sections[9], 'Band 1 · Q'), '128.00');
    assert.equal(value(sections[9], 'Band 1 · Gain'), '+12.0dB');
    assert.equal(value(sections[9], 'Band 1 · Bypass'), 'On');
    assert.equal(value(sections[9], 'Compressor ratio'), 'Limit');
    assert.equal(value(sections[16], 'Compressor threshold'), '-90.0dB');
    assert.equal(value(sections[1], 'Gate threshold'), '+20.0dB');
    assert.equal(value(sections[1], 'Gate attack'), '1ms');
    assert.equal(value(sections[1], 'Gate release'), '3000ms');
    assert.equal(value(sections[9], 'Delay'), '680.000 ms');
    assert.equal(value(sections[9], 'Sources'), 'Aux');
    assert.equal(value(sections[1], 'HPF type'), 'Bypass');
    assert.equal(value(sections[1], 'LPF type'), 'BW -6');
    assert.deepEqual(device, before);
});

test('missing vendor values remain unknown and JSON delay units use their stored unit', () => {
    const device = state();
    const sections = presetSections(device, labels);
    assert.equal(value(sections[0], 'Delay unit'), 'Not stored in this file');
    assert.equal(value(sections[0], 'Test tone'), 'Not stored in this file');
    assert.equal(value(sections[17], 'EQ Bypass'), 'Not stored in this file');
    assert.equal(value(sections[18], 'Effect'), 'Not stored in this file');
    assert.equal(value(sections[18], 'Reverb time'), '1.96s');
    assert.equal(value(sections[19], 'Input 1 · Gain'), '0%');
    assert.equal(value(sections[19], 'Output 1 · Channels'), 'Out8');
    assert.equal(value(sections[20], 'State'), 'Not stored in this file');
    device.delay_unit = 2;
    assert.equal(value(presetSections(device, labels)[9], 'Delay'), '766.329 ft');
});
