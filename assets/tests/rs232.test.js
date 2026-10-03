import test from 'node:test';
import assert from 'node:assert/strict';
import { SERIAL_COMMANDS, rawCommand, serialPacket, toggleSerialMask } from '../lib/rs232.js';

const packet = (cmd, values, id = 1) => serialPacket(id, SERIAL_COMMANDS.find((c) => c.code === cmd), values);

test('vendor screenshot default and documented examples keep serial parameter order', () => {
    assert.equal(packet(0x40, [0, 0]), '7B7D01400000007D7B');
    assert.equal(packet(0x40, [1, 2]), '7B7D01400101007D7B');
    assert.equal(packet(0x45, [2, 250]), '7B7D01450100FA7D7B');
    assert.equal(packet(0x53, [280]), '7B7D01530201187D7B');
    assert.equal(packet(0x4E, [4, 2, 1]), '7B7D014E0301017D7B');
    assert.equal(packet(0x56, [2, 48]), '7B7D01560200307D7B');
    assert.equal(packet(0x57, [3, 3]), '7B7D01570002037D7B');
    assert.equal(packet(0x5E, [5]), '7B7D015E0004007D7B');
    assert.equal(packet(0x52, [1]), '7B7D01520200017D7B');
    assert.equal(packet(0x61, []), '7B7D01610000007D7B');
});

test('gain byte boundaries and highest channel do not overflow', () => {
    assert.equal(packet(0x44, [8, 0], 254), '7B7DFE440700007D7B');
    assert.equal(packet(0x44, [8, 400]), '7B7D01440701907D7B');
    assert.equal(packet(0x43, [1, 20]), '7B7D01430113007D7B');
    assert.equal(serialPacket(1, rawCommand, [255, 0, 127, 255]), '7B7D01FF007FFF7D7B');
});

test('invalid and incomplete edits produce no packet', () => {
    for (const id of [0, 255, NaN, 1.5]) assert.equal(packet(0x4A, [], id), null);
    for (const values of [[0, 280], [9, 280], [1, -1], [1, 401], [1, NaN], [1, 280.5], [1]]) {
        assert.equal(packet(0x44, values), null);
    }
    assert.equal(packet(0x40, [1, 0]), null);
    assert.equal(packet(0x40, [0, 2]), null);
    assert.equal(serialPacket(1, rawCommand, [256, 0, 0, 0]), null);
});


test('recorded Group packets retain group and parameter byte order', () => {
    assert.equal(packet(0x63, [0]), '7B7D01630000007D7B');
    assert.equal(packet(0x63, [7]), '7B7D01630700007D7B');
    assert.equal(packet(0x66, [7, 100]), '7B7D01660764007D7B');
    assert.equal(packet(0x67, [7, 1]), '7B7D01670701007D7B');
    assert.equal(packet(0x66, [0, 0]), '7B7D01660000007D7B');
    assert.equal(packet(0x67, [0, 0]), '7B7D01670000007D7B');
    assert.equal(packet(0x63, [-1]), null);
    assert.equal(packet(0x63, [8]), null);
    assert.equal(packet(0x66, [7, 101]), null);
    assert.equal(packet(0x67, [7, 2]), null);
});

test('captured Group mute and matrix packets keep the mask in data byte three', () => {
    assert.equal(packet(0x68, [7, 1]), '7B7D01680701007D7B');
    assert.equal(packet(0x68, [0, 0]), '7B7D01680000007D7B');
    assert.equal(packet(0x69, [0, 0]), '7B7D01690000007D7B');
    assert.equal(packet(0x69, [7, 0]), '7B7D01690700007D7B');
    assert.equal(packet(0x69, [7, 255]), '7B7D01690700FF7D7B');
    assert.equal(packet(0x69, [0, 255]), '7B7D01690000FF7D7B');
    assert.equal(packet(0x68, [7, 2]), null);
    assert.equal(packet(0x69, [7, -1]), null);
    assert.equal(packet(0x69, [7, 256]), null);
    assert.equal(packet(0x69, [8, 255]), null);
});

test('single-channel vendor Group Matrix shots match both group sides', () => {
    assert.equal(packet(0x69, [0, 1]), '7B7D01690000017D7B');
    assert.equal(packet(0x69, [0, 128]), '7B7D01690000807D7B');
    assert.equal(packet(0x69, [7, 1]), '7B7D01690700017D7B');
    assert.equal(packet(0x69, [7, 128]), '7B7D01690700807D7B');
});

test('mask checkboxes combine independently and preserve other selected channels', () => {
    assert.equal(toggleSerialMask(0, 0, true), 1);
    assert.equal(toggleSerialMask(1, 7, true), 129);
    assert.equal(toggleSerialMask(129, 0, false), 128);
    assert.equal(toggleSerialMask(255, 7, false), 127);
    let mask = 0;
    for (let bit = 0; bit < 8; bit++) mask = toggleSerialMask(mask, bit, true);
    assert.equal(mask, 255);
    for (let bit = 0; bit < 8; bit++) mask = toggleSerialMask(mask, bit, false);
    assert.equal(mask, 0);
    for (const value of [-1, 256, NaN, 0.5]) assert.ok(Number.isNaN(toggleSerialMask(value, 0, true)));
    for (const bit of [-1, 8, NaN, 0.5]) assert.ok(Number.isNaN(toggleSerialMask(0, bit, true)));
});
