// Browser-only channel visibility. Original indices and link groups always remain device identities.
import { readPref, writePref } from './prefs.js';
import { lcm, linkedMembers, linkRuns } from './link.js';

const KEY = 'channel-display';
const listeners = new Set();
let current = null;
export function displayView(value) {
    const hidden = value?.hidden;
    return { hidden: Array.isArray(hidden) ? [...new Set(hidden.filter(i => Number.isInteger(i) && i >= 0 && (i < 16 || i === 18)))].sort((a, b) => a - b) : [], grouped: true === value?.grouped };
}
export function getChannelView() { return current ??= displayView(readPref(KEY, {})); }
export function setChannelView(value) {
    current = displayView(value);
    writePref(KEY, current);
    for (const listener of listeners) listener(current);
}
export function onChannelView(listener) { listeners.add(listener); return () => listeners.delete(listener); }
export function channelVisible(index, view = getChannelView()) { return !view.hidden.includes(index); }
export function channelMembers(index, state) {
    if (index >= 16) return [index];
    const base = index < 8 ? 0 : 8;
    return linkedMembers(state?.[base ? 'outputs' : 'inputs'] ?? [], index - base).map(i => base + i);
}
export function representative(index, state, view = getChannelView()) {
    return channelMembers(index, state).find(i => channelVisible(i, view)) ?? null;
}
export function displayedChannel(index, state, view = getChannelView()) {
    return channelVisible(index, view) && (!view.grouped || representative(index, state, view) === index);
}
/** Filter placed runs without renumbering groups or channels. Routing never collapses a link group. */
export function visibleRuns(groups, placement, base, view = getChannelView(), collapse = false) {
    const seen = new Set();
    return linkRuns(groups, placement).map(run => ({ ...run, channels: run.channels.filter(i => {
        if (!channelVisible(base + i, view)) return false;
        if (collapse && view.grouped && groups[i] > 0) {
            if (seen.has(groups[i])) return false;
            // Normal order and grouped placement both encounter the lowest visible member first.
            seen.add(groups[i]);
        }
        return true;
    }) })).filter(run => run.channels.length);
}
/** Compact tab cells while retaining the original group colour and channel index. */
export function channelLayout(groups, placement, base, view = getChannelView()) {
    const runs = visibleRuns(groups, placement, base, view, true);
    const units = runs.map(run => run.channels);
    const cells = [];
    if (placement !== 'stacked') {
        runs.flatMap(run => run.channels).forEach((i, k) => { cells[i] = { column: k + 1, row: 1, span: 1 }; });
        return { columns: cells.filter(Boolean).length, rows: 1, cells, units };
    }
    const rows = units.reduce((r, unit) => lcm(r, unit.length), 1);
    units.forEach((unit, k) => unit.forEach((i, n) => { cells[i] = { column: k + 1, row: n * rows / unit.length + 1, span: rows / unit.length }; }));
    return { columns: units.length, rows, cells, units };
}
export function visibleEdge(edge, view = getChannelView()) {
    return channelVisible(edge.source < 8 ? edge.source : 18, view) && channelVisible(edge.output < 8 ? 8 + edge.output : 18, view);
}
export function selectionState(values) {
    const selected = values.length > 0 && values.every(Boolean);
    return { selected, mixed: values.some(Boolean) && !selected, next: !selected };
}
export function setMaskBits(mask, bits, on) {
    const group = bits.reduce((value, bit) => value | (1 << bit), 0);
    return on ? mask | group : mask & ~group;
}
