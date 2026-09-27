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
});
