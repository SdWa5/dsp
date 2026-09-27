import assert from 'node:assert/strict';
import { test } from 'node:test';
import { commandKey, SendQueue } from '../lib/queue.js';

test('one request in flight, merged by key, never across a barrier', async () => {
    const batches = [];
    let release;
    const queue = new SendQueue((batch) => {
        batches.push(batch);
        return 1 === batches.length ? new Promise((r) => { release = r; }) : Promise.resolve();
    });
    const gain = (raw) => ({ command: 'gain', args: { channel: 8, raw } });
    queue.push(gain(1), 'g8');
    queue.push(gain(2), 'g8');
    queue.push(gain(3), 'g8');
    queue.push({ command: 'copyChannel', args: { source: 8, target: 9 } });
    queue.push(gain(4), 'g8');
    assert.equal(batches.length, 1);
    release();
    await queue.flush();
    assert.deepEqual(batches, [[gain(1)], [gain(3), { command: 'copyChannel', args: { source: 8, target: 9 } }, gain(4)]]);
});

test('errors are reported and the queue goes on', async () => {
    const errors = [];
    let calls = 0;
    const queue = new SendQueue(() => (++calls === 1 ? Promise.reject(new Error('down')) : Promise.resolve()), (e) => errors.push(e.message));
    await queue.push({ command: 'mute' });
    await queue.push({ command: 'mute' });
    assert.deepEqual(errors, ['down']);
    assert.equal(calls, 2);
});

test('command keys', () => {
    assert.equal(commandKey({ command: 'peq', args: { channel: 8, band: 2, gainRaw: 1 } }), 'peq:channel=8,band=2');
    assert.equal(commandKey({ command: 'echo', args: { predelay: 1 } }), 'echo:');
});
