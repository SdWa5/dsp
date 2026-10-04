// Plans the resets of changed settings to their factory default: a whole channel or a whole function page.
// Pure, so the editor only applies what these functions return.
import { changedPaths, pageChanges } from './defaults.js';
import { linkedMembers } from './link.js';
import { getPath, setPath } from './store.js';

/** Names are what the user calls a channel and link membership how channels are grouped. A reset keeps both. */
export const isKept = (path) => /(?:^|\.)(?:name|link)$/.test(path);

const plan = (defaults, paths) => {
    const writes = paths.filter((path) => !isKept(path)).map((path) => ({ path, value: getPath(defaults, path) }));
    return { writes, count: writes.length, kept: paths.length - writes.length };
};

/**
 * Every changed setting of channel index on side (inputs, outputs) and of the channels linked with it, since a
 * linked channel follows every edit of its group. The routing is the one exception and is reset on the clicked
 * channel only, as each output of a group keeps its own sources. The Aux (side aux) has no link group.
 */
export function channelReset(state, defaults, side, index) {
    if ('aux' === side) {
        return { ...plan(defaults, changedPaths(state?.aux, defaults?.aux, 'aux')), members: [0] };
    }
    const members = linkedMembers(state?.[side] ?? [], index);
    const paths = members.flatMap((member) => changedPaths(state?.[side]?.[member], defaults?.[side]?.[member], `${side}.${member}`)
        .filter((path) => member === index || !path.endsWith('.route')));
    return { ...plan(defaults, paths), members };
}

/** Every changed setting a function page counts (pageChanges()), on every channel, hidden ones included. */
export function pageReset(state, defaults, page) {
    return plan(defaults, pageChanges(state, defaults)[page] ?? []);
}

/**
 * The device commands for writes, built from units, the editable-field registry: one command per unit that owns a
 * written path, with every argument read from the state after the writes. A write without an owner is left out and
 * reported in unowned, so the store never holds a value the device was not sent.
 */
export function resetCommands(state, writes, units) {
    const owners = new Map();
    units.forEach((unit, i) => {
        for (const arg of Object.values(unit.args)) {
            if ('string' === typeof arg && arg.startsWith('@')) {
                owners.set(arg.slice(1), i);
            }
        }
    });
    const next = structuredClone(state);
    const owned = [];
    const unowned = [];
    const used = new Set();
    for (const write of writes) {
        if (!owners.has(write.path)) {
            unowned.push(write.path);
            continue;
        }
        setPath(next, write.path, write.value);
        owned.push(write);
        used.add(owners.get(write.path));
    }
    const commands = [...used].sort((a, b) => a - b).map((i) => {
        const args = Object.fromEntries(Object.entries(units[i].args).map(([key, arg]) => [key,
            'string' === typeof arg && arg.startsWith('@') ? getPath(next, arg.slice(1)) ?? 0 : arg]));
        return { command: units[i].command, args };
    });
    return { commands, writes: owned, unowned };
}
