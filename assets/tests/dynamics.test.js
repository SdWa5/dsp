import assert from 'node:assert/strict';
import { test } from 'node:test';
import { compOut, dynamicsCurve, gateOut, ratioValue } from '../lib/dynamics.js';

const RATIOS = ['1:1.0', '1:1.1', '1:1.3', '1:1.5', '1:1.7', '1:2.0', '1:2.5', '1:3.0', '1:3.5', '1:4.0', '1:5', '1:6', '1:8', '1:10', '1:20', 'Limit'];

test('ratio labels become the factor', () => {
    assert.equal(ratioValue('1:1.0'), 1);
    assert.equal(ratioValue('1:4.0'), 4);
    assert.equal(ratioValue('1:20'), 20);
    assert.equal(ratioValue('Limit'), Infinity);
    assert.equal(ratioValue(undefined), 1);
});

test('a compressor divides the level above its threshold, a limiter holds it', () => {
    assert.equal(compOut(-30, -10, 4), -30);
    assert.equal(compOut(10, -10, 4), -5);
    assert.equal(compOut(10, 0, Infinity), 0);
    assert.equal(compOut(10, 0, 1), 10);
});

test('a gate passes from its threshold up and closes below it', () => {
    assert.equal(gateOut(-20, -40), -20);
    assert.equal(gateOut(-40, -40), -40);
    assert.equal(gateOut(-41, -40), -100);
});

test('curves run across the plot with the threshold as a corner', () => {
    // Limit from 0 dB, as Out1 of the vendor screenshot page_02_Comp.png
    assert.deepEqual(dynamicsCurve('comp', { ratio: 15, threshold: 180 }, RATIOS), { points: [[-100, -100], [0, 0], [20, 0]], threshold: [0, 0] });
    // The factory compressor, 1:1.0 from +20 dB, is the diagonal with the dot top right (dlg_CompSet.png)
    assert.deepEqual(dynamicsCurve('comp', { ratio: 0, threshold: 220 }, RATIOS), { points: [[-100, -100], [20, 20], [20, 20]], threshold: [20, 20] });
    assert.deepEqual(dynamicsCurve('comp', { ratio: 9, threshold: 160 }, RATIOS).points[2], [20, -2.5]);
    // The factory gate at -90 dB (page_01_Gate.png)
    assert.deepEqual(dynamicsCurve('gate', { threshold: 0 }), { points: [[-90, -100], [-90, -90], [20, 20]], threshold: [-90, -90] });
});
