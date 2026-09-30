import assert from 'node:assert/strict';
import { test } from 'node:test';
import { describeConnection } from '../lib/connection.js';

const connected = { bridge: true, connected: true, target: '192.168.68.10', error: '', demo: false };

test('a real device is online without a banner', () => {
    assert.deepEqual(describeConnection(connected), { label: 'Online', online: true, demo: false, banner: '' });
});

test('the simulated device is not shown as online and keeps a banner', () => {
    const connection = describeConnection({ ...connected, target: 'demo', demo: true });
    assert.equal(connection.label, 'Demo');
    assert.equal(connection.online, false);
    assert.equal(connection.demo, true);
    assert.match(connection.banner, /simulated device/);
});

test('offline and a missing bridge', () => {
    assert.deepEqual(describeConnection({ ...connected, connected: false, error: 'timeout' }),
        { label: 'Offline', online: false, demo: false, banner: 'Not connected to 192.168.68.10. timeout' });
    assert.equal(describeConnection({ ...connected, bridge: false, demo: true }).label, 'Offline');
    assert.match(describeConnection({ bridge: false, connected: false, error: '' }).banner, /bridge is not running/);
});
