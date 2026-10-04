import test from 'node:test';
import assert from 'node:assert/strict';
import { editableRoute, routingEdit, routingEndpoint, routingRemoval } from '../lib/routingedit.js';

const input = index => ({ kind: 'input', index });
const output = index => ({ kind: 'output', index });
const aux = { kind: 'aux', index: 8 };

test('every supported connection normalizes both endpoint directions', () => {
    for (let i = 0; i < 8; i++) {
        for (const [first, second, expected] of [
            [input(i), output(7 - i), { source: i, output: 7 - i }],
            [input(i), aux, { source: i, output: 8 }],
            [aux, output(i), { source: 8, output: i }],
        ]) {
            assert.deepEqual(editableRoute(first, second), expected);
            assert.deepEqual(editableRoute(second, first), expected);
        }
    }
});

test('same-side, self, malformed and out-of-range endpoints have no route', () => {
    for (const [first, second] of [[input(0), input(7)], [output(0), output(7)], [aux, aux],
        [null, aux], [input(-1), aux], [input(8), aux], [input(0.5), aux],
        [input(0), output(8)], [input(0), { kind: 'aux', index: 0 }],
        [input(0), { kind: 'other', index: 0 }], [routingEndpoint('input:'), aux]]) {
        assert.equal(editableRoute(first, second), null);
    }
});

test('writes toggle boundary bits only and target existing destination commands', () => {
    const state = { aux: { select: 0x1000 }, outputs: Array.from({ length: 8 }, () => ({ route: 0x1000 })) };
    for (const [first, second, path, channel, bit] of [
        [input(0), output(0), 'outputs.0.route', 8, 0],
        [input(7), output(7), 'outputs.7.route', 15, 7],
        [input(0), aux, 'aux.select', 18, 0],
        [aux, input(7), 'aux.select', 18, 7],
        [output(7), aux, 'outputs.7.route', 15, 8],
    ]) {
        const edit = routingEdit(state, first, second);
        assert.equal(edit.path, path);
        assert.equal(edit.mask, 0x1000 | (1 << bit));
        assert.deepEqual(edit.command, { command: 'routing', args: { channel, mask: edit.mask } });
        assert.equal(edit.enabled, true);
        const holder = path === 'aux.select' ? state.aux : state.outputs[channel - 8];
        const field = path === 'aux.select' ? 'select' : 'route';
        holder[field] = edit.mask;
        assert.equal(routingEdit(state, first, second).mask, 0x1000);
        assert.equal(routingEdit(state, first, second).enabled, false);
        holder[field] = 0x1000;
    }
});

test('missing reported masks disable writes and state stays unmodified', () => {
    for (const state of [null, {}, { outputs: [] }, { aux: {} },
        { outputs: [{ route: '1' }], aux: { select: NaN } }]) {
        assert.equal(routingEdit(state, input(0), output(0)), null);
        assert.equal(routingEdit(state, input(0), aux), null);
    }
    const state = { outputs: [{ route: 2, link: 1 }, { route: 128, link: 1 }] };
    const before = structuredClone(state);
    const edit = routingEdit(state, input(7), output(0));
    assert.equal(edit.mask, 130);
    assert.deepEqual(state, before);
    state.outputs[0].route |= 0x2000;
    assert.equal(routingEdit(state, input(7), output(0)).mask, 0x2000 | 130);
});

test('edge removal clears only the existing route and never sets missing or invalid routes', () => {
    const state = { aux: { select: 0x1081 }, outputs: [{ route: 0x1101 }] };
    assert.equal(routingRemoval(state, 0, 8).mask, 0x1080);
    assert.equal(routingRemoval(state, 7, 8).mask, 0x1001);
    assert.equal(routingRemoval(state, 8, 0).mask, 0x1001);
    assert.equal(routingRemoval(state, 0, 0).mask, 0x1100);
    for (const [source, output] of [[1, 0], [8, 8], [9, 0], [0, -1], [0, 8.5]]) {
        assert.equal(routingRemoval(state, source, output), null);
    }
    assert.equal(routingRemoval(null, 0, 0), null);
});
