import assert from 'node:assert/strict';
import { test } from 'node:test';
import { knobMuted, rangeFill } from '../lib/range.js';

test('the fill runs from 0 at min to 1 at max', () => {
    assert.equal(rangeFill(0, 0, 400), 0);
    assert.equal(rangeFill(100, 0, 400), 0.25);
    assert.equal(rangeFill(400, 0, 400), 1);
    assert.equal(rangeFill(9, 9, 2999), 0);
    // Out of range and an empty range stay inside the track
    assert.equal(rangeFill(500, 0, 400), 1);
    assert.equal(rangeFill(-1, 0, 400), 0);
    assert.equal(rangeFill(5, 5, 5), 0);
});

test('a gain knob turns red at the bottom or with its Mute', () => {
    assert.equal(knobMuted('gain', 0, false), true);
    assert.equal(knobMuted('gain', 280, true), true);
    assert.equal(knobMuted('gain', 280, false), false);
    assert.equal(knobMuted('gain', 1, undefined), false);
    // A percent gain (Sub, Group) at 0 is not known to mute, only its Mute button counts
    assert.equal(knobMuted('percent', 0, false), false);
    assert.equal(knobMuted('percent', 0, true), true);
});
