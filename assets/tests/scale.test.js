import assert from 'node:assert/strict';
import { test } from 'node:test';
import * as S from '../lib/scale.js';

test('gain matches the editor display', () => {
    assert.equal(S.gainToDb(280), 0);
    assert.equal(S.gainToDb(400), 12);
    assert.equal(S.gainToDb(20), -50);
    assert.equal(S.gainToDb(80), -20);
    assert.equal(S.dbToGain(-6), 220);
    assert.equal(S.dbToGain(-50), 20);
    assert.equal(S.dbToGain(99), 400);
    assert.equal(S.format('gain', 280), '0.0dB');
    assert.equal(S.format('gain', 400), '+12.0dB');
    assert.equal(S.format('gain', 1), '-59.5dB');
    assert.equal(S.format('gain', 0), 'Muted');
});

test('frequency, Q and PEQ gain', () => {
    assert.equal(S.formatHz(S.indexToHz(31)), '40.3Hz');
    assert.equal(S.formatHz(S.indexToHz(200)), '2.00KHz');
    assert.equal(S.formatHz(S.indexToHz(300)), '20.16KHz');
    assert.equal(S.hzToIndex(1000), 170);
    assert.equal(S.hzToIndex(1), 0);
    assert.equal(S.format('q', 25), '1.70');
    assert.equal(S.format('q', 32), '2.50');
    assert.equal(S.format('peq', 150), '+3.0dB');
    assert.equal(S.dbToPeqGain(-12), 0);
    assert.equal(S.qToIndex(1.7), 25);
    assert.equal(S.qToIndex(0.1), 0);
    assert.equal(S.qToIndex(1000), 100);
});

test('times, thresholds, delay and presets', () => {
    assert.equal(S.format('threshold', 180), '0.0dB');
    assert.equal(S.format('threshold', 220), '+20.0dB');
    assert.equal(S.format('ms', 2999), '3000ms');
    assert.equal(S.delayLabel(480), '10.000 ms');
    assert.equal(S.delayLabel(480, 1), '3.430 m');
    assert.equal(S.format('delay', 480, { delay_unit: 2 }), '11.253 ft');
    assert.equal(S.format('reverbTime', 139), '1.96s');
    assert.equal(S.format('ratio', 15, null, { COMP_RATIOS: { 15: 'Limit' } }), 'Limit');
    assert.equal(S.format('gain', null), '–');
    assert.equal(S.presetLabel(0), 'F00');
    assert.equal(S.presetLabel(7), 'U07');
    assert.deepEqual([S.presetSlot(1), S.presetSlot(20), S.presetSlot(0)], [0, 19, 20]);
    const state = { preset_names: ['', 'Bulgaria26', ...Array(18).fill(''), 'Default Preset'], preset_stored: [false, true, ...Array(18).fill(false)] };
    assert.deepEqual(S.presetSlotInfo(state, 2), { name: 'Bulgaria26', stored: true, text: 'U02  Bulgaria26' });
    assert.equal(S.presetSlotInfo(state, 1).text, 'U01  (empty)');
    assert.equal(S.presetSlotInfo(state, 0).text, 'F00  Default Preset');
    assert.equal(S.presetSlotInfo({ preset_names: ['Old'] }, 1).stored, null);
    assert.equal(S.presetSlotInfo(null, 3).text, 'U03  (no name)');
});

test('typed values parse back to raw values', () => {
    const labels = { COMP_RATIOS: { 0: '1:1.0', 5: '1:2.0', 9: '1:4.0', 15: 'Limit' } };
    assert.equal(S.parse('gain', '0'), 280);
    assert.equal(S.parse('gain', '-6 dB'), 220);
    assert.equal(S.parse('gain', '-6,0dB'), 220);
    assert.equal(S.parse('gain', 'loud'), null);
    assert.equal(S.parse('gain', 'mute'), 0);
    assert.equal(S.parse('gain', '-60'), 0);
    assert.equal(S.parse('threshold', '-6'), 168);
    assert.equal(S.parse('ms', '200ms'), 199);
    assert.equal(S.parse('ratio', '1:4', null, labels), 9);
    assert.equal(S.parse('ratio', '4', null, labels), 9);
    assert.equal(S.parse('ratio', 'limit', null, labels), 15);
    assert.equal(S.parse('ratio', '7', null, labels), null);
    assert.equal(S.parse('hz', '2k'), S.hzToIndex(2000));
    assert.equal(S.parse('peq', '+3'), 150);
    assert.equal(S.parse('delay', '10'), 480);
    assert.equal(S.parse('delay', '3.430', { delay_unit: 1 }), 480);
    assert.equal(S.parse('delay', '10ms', { delay_unit: 1 }), 480);
    assert.equal(S.parse('delay', '11.253', { delay_unit: 2 }), 480);
    assert.equal(S.parse('percent', '50%'), 50);
    assert.equal(S.parse('reverbTime', '1.96s'), 139);
    for (const [fmt, raw] of [['gain', 190], ['gain', 0], ['threshold', 150], ['ms', 500], ['delay', 960], ['knee', 6]]) {
        assert.equal(S.parse(fmt, S.format(fmt, raw)), raw, fmt);
    }
});

test('an output at 1:1.0 with a gain other than 0 dB exaggerates it', () => {
    const output = (ratio, gain) => ({ gain, comp: { ratio, threshold: 180 } });
    assert.equal(S.exaggeratesGain(output(0, 300)), true);
    assert.equal(S.exaggeratesGain(output(0, S.GAIN_MUTED)), true);
    assert.equal(S.exaggeratesGain(output(0, S.GAIN_UNITY)), false);
    assert.equal(S.exaggeratesGain(output(S.EXACT_RATIO, 300)), false);
    assert.equal(S.exaggeratesGain(output(15, 300)), false);
    assert.equal(S.exaggeratesGain(null), false);
});
