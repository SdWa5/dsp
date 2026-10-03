import { gainToDb } from './scale.js';

// Serial packets are a separate protocol from Commands.php's USB/TCP frames.
// Layout and parameter order follow manual chapter 6.1 and captured vendor builder packets. No function sends data.
const choice = (label, options) => ({ label, options });
const number = (label, min, max, value = min) => ({ label, min, max, value });
const io = choice('Side', ['Input', 'Output']);
const channel = number('Channel', 1, 8);
const direction = choice('Direction', ['Increase', 'Decrease']);
const mute = choice('Mute', ['UnMute', 'Mute']);
const on = choice('State', ['Off', 'On']);
const fn = choice('Function', ['Effect', 'Camera', 'AutoMix']);
const gain = number('Gain raw', 0, 400, 280);
const group = choice('Group', ['Group Input1', 'Group Input2', 'Group Input3', 'Group Input4',
    'Group Output1', 'Group Output2', 'Group Output3', 'Group Output4']);
const percent = number('Percent', 0, 100, 100);
const ch = (v) => v - 1;
const volume = (v) => [v >> 8, v & 255];
const command = (code, label, params, data) => ({ code, label, params, data });
const preset = (code, label) => command(code, label,
    [choice('Bank', ['Factory', 'User']), number('Preset', 0, 20)], (v) => [v[0], v[0] ? v[1] - 1 : 0, 0]);

/** Named commands follow the manual or recorded vendor builder packets. Other commands use raw bytes. */
export const SERIAL_COMMANDS = [
    preset(0x40, 'Load preset matrix'),
    command(0x41, 'Gain step', [io, channel, direction], (v) => [v[0], ch(v[1]), v[2]]),
    command(0x42, 'Mute channel', [io, channel, mute], (v) => [v[0], ch(v[1]), v[2]]),
    preset(0x43, 'Recall preset'),
    command(0x44, 'Input volume', [channel, gain], (v) => [ch(v[0]), ...volume(v[1])]),
    command(0x45, 'Output volume', [channel, gain], (v) => [ch(v[0]), ...volume(v[1])]),
    command(0x46, 'Sub volume', [io, percent], (v) => [v[0], v[1], 0]),
    command(0x47, 'Sub gain step', [io, direction], (v) => [v[0], v[1], 0]),
    command(0x48, 'Read gain', [io, channel], (v) => [v[0], ch(v[1]), 0]),
    command(0x49, 'Read mute', [io, channel], (v) => [v[0], ch(v[1]), 0]),
    command(0x4A, 'Read preset', [], () => [0, 0, 0]),
    command(0x4B, 'Read sub', [io], (v) => [v[0], 0, 0]),
    command(0x4C, 'Sub mute', [io, mute], (v) => [v[0], v[1], 0]),
    command(0x4D, 'Read level', [choice('Side', ['Input', 'Output', 'Aux']), channel], (v) => [v[0], v[0] === 2 ? 0 : ch(v[1]), 0]),
    command(0x4E, 'Set matrix', [number('Output', 1, 8), number('Input', 1, 8), on], (v) => [ch(v[0]), ch(v[1]), v[2]]),
    command(0x4F, 'Read matrix', [number('Output', 1, 8), number('Input', 1, 8)], (v) => [ch(v[0]), ch(v[1]), 0]),
    command(0x51, 'Aux gain step', [direction], (v) => [2, 0, v[0]]),
    command(0x52, 'Aux mute', [mute], (v) => [2, 0, v[0]]),
    command(0x53, 'Aux volume', [gain], (v) => [2, ...volume(v[0])]),
    command(0x54, 'Volume step', [io, channel, direction], (v) => [v[0], ch(v[1]), v[2]]),
    command(0x55, 'Aux function on/off', [fn, on], (v) => [2, v[0], v[1]]),
    command(0x56, 'Aux source mask', [choice('Function', ['Aux', 'Camera', 'AutoMix']), number('Input mask', 0, 255)], (v) => [v[0], 0, v[1]]),
    command(0x57, 'Set FBQ', [channel, number('FBQ level', 0, 4)], (v) => [0, ch(v[0]), v[1]]),
    command(0x58, 'Read Aux gain', [], () => [2, 0, 0]),
    command(0x59, 'Read Aux mute', [], () => [2, 0, 0]),
    command(0x5B, 'Read Aux function', [fn], (v) => [2, v[0], 0]),
    command(0x5C, 'Read Aux sources', [choice('Function', ['Aux', 'Camera', 'AutoMix'])], (v) => [2, v[0], 0]),
    command(0x5E, 'Read FBQ', [channel], (v) => [0, ch(v[0]), 0]),
    command(0x61, 'Get Full Matrix', [], () => [0, 0, 0]),
    command(0x63, 'Get Group Parameter', [group], (v) => [v[0], 0, 0]),
    command(0x66, 'Group volume', [group, percent], (v) => [v[0], v[1], 0]),
    command(0x67, 'Group gain step', [group, direction], (v) => [v[0], v[1], 0]),
    command(0x68, 'Group mute', [group, mute], (v) => [v[0], v[1], 0]),
    // Vendor endpoint shots put In1 at bit 0 and In8 at bit 7, on both group sides.
    command(0x69, 'Group matrix', [group, { ...number('Mask byte', 0, 255), mask: true }], (v) => [v[0], 0, v[1]]),
];

export const rawCommand = command(null, 'Raw command', [number('CMD', 0, 255, 64),
    number('Data 1', 0, 255), number('Data 2', 0, 255), number('Data 3', 0, 255)], (v) => v.slice(1));

export const serialHex = (v) => v.toString(16).toUpperCase().padStart(2, '0');

/** Invalid edits return null rather than wrapping, truncating or producing a stale packet. */
export function serialPacket(id, command, values) {
    if (!Number.isInteger(id) || id < 1 || id > 254 || values.length !== command.params.length) return null;
    if (values.some((v, i) => !Number.isInteger(v) || v < (command.params[i].min ?? 0)
        || v > (command.params[i].max ?? command.params[i].options.length - 1))) return null;
    // F00 is factory-only; user presets are numbered U01..U20 in the measured USB implementation.
    if ([0x40, 0x43].includes(command.code) && (values[0] === 0 ? values[1] !== 0 : values[1] < 1)) return null;
    const code = command.code ?? values[0];
    return [0x7B, 0x7D, id, code, ...command.data(values), 0x7D, 0x7B].map(serialHex).join('');
}

export function serialValueHint(spec, value) {
    return spec.label === 'Gain raw' ? `${gainToDb(value).toFixed(1)} dB` : '';
}

/** Toggle one channel in an eight-bit serial mask without wrapping invalid numeric edits. */
export function toggleSerialMask(mask, bit, checked) {
    if (!Number.isInteger(mask) || mask < 0 || mask > 255
        || !Number.isInteger(bit) || bit < 0 || bit > 7) return NaN;
    return checked ? mask | (1 << bit) : mask & ~(1 << bit);
}
