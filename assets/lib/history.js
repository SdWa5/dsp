import { getPath, setPath } from './store.js';
import { linkedMembers } from './link.js';
import { t } from './i18n.js';

const clone = value => value === undefined ? null : structuredClone(value);
export const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);
export const maskPath = path => /\.(route|select|mask)$/.test(path);

/** History owns logical actions, never meter events or browser preferences. */
export class EditHistory {
    constructor(units = [], limit = 100) {
        this.paths = [...new Set(units.flatMap(unit => Object.values(unit.args).filter(v => typeof v === 'string' && v.startsWith('@')).map(v => v.slice(1))))];
        this.limit = limit;
        this.undo = [];
        this.redo = [];
        this.pending = [];
        this.current = null;
        this.depth = 0;
        this.listeners = new Set();
        this.busy = false;
        this.message = '';
    }
    subscribe(fn) { this.listeners.add(fn); return () => this.listeners.delete(fn); }
    notify() { this.listeners.forEach(fn => fn(this)); }
    begin(label, state, riders = []) {
        if (!this.current) this.current = { label, before: clone(state), ridersBefore: clone(riders), changes: new Map() };
        this.depth++;
    }
    change(path, before, after, state, riders = []) {
        if (!this.paths.some(p => p === path || p.startsWith(path + '.'))) return;
        if (!this.current) { this.begin('Change setting', state, riders); this.depth = 0; }
        for (const p of this.paths.filter(p => p === path || p.startsWith(path + '.'))) {
            const relative = p.slice(path.length + 1);
            const a = p === path ? before : getPath(before, relative);
            const b = p === path ? after : getPath(after, relative);
            const previous = this.current.changes.get(p);
            if (!previous) this.current.changes.set(p, { path: p, before: clone(a), after: clone(b) });
            else previous.after = clone(b);
        }
    }
    end(state, riders = [], bulk = false) {
        if (!this.current) return;
        if (this.depth > 0 && --this.depth > 0) return;
        const entry = this.current;
        this.current = null;
        if (bulk) for (const path of this.paths) {
            const before = clone(getPath(entry.before, path));
            const after = clone(getPath(state, path));
            if (!same(before, after)) entry.changes.set(path, { path, before, after });
        }
        entry.changes = [...entry.changes.values()].filter(c => !same(c.before, c.after));
        entry.ridersAfter = clone(riders);
        if (entry.changes.length || !same(entry.ridersBefore, entry.ridersAfter)) this.pending.push(entry);
        this.notify();
    }
    /** Final bridge values normalize only the last local write to a field, not earlier logical actions. */
    confirm(state) {
        const last = new Map();
        this.pending.forEach(entry => entry.changes.forEach(c => last.set(c.path, c)));
        last.forEach((c, path) => { c.after = clone(getPath(state, path)); });
        for (const entry of this.pending) {
            entry.changes = entry.changes.filter(c => !same(c.before, c.after));
            if (!entry.changes.length && same(entry.ridersBefore, entry.ridersAfter)) continue;
            if (entry.changes.some(c => c.before == null || c.after == null)) {
                this.clear('History stopped at an unknown previous value.', false);
                continue;
            }
            for (const c of entry.changes) if (maskPath(c.path)) c.mask = c.before ^ c.after;
            entry.guardsBefore = this.guards(entry.before, entry.changes);
            const after = clone(entry.before);
            entry.changes.forEach(c => setPath(after, c.path, c.after));
            entry.guardsAfter = this.guards(after, entry.changes);
            delete entry.before;
            this.undo.push(entry);
            this.undo = this.undo.slice(-this.limit);
            this.redo = [];
        }
        this.pending = [];
        this.notify();
    }
    guards(state, changes) {
        const channels = new Set(changes.map(c => c.path.split('.').slice(0, 2).join('.')).filter(p => /^(inputs|outputs)\.[0-7]$/.test(p)));
        return [...channels].map(prefix => {
            const [side, index] = prefix.split('.');
            const mask = linkedMembers(state?.[side] ?? [], Number(index)).reduce((mask, i) => mask | (1 << i), 0);
            return { path: `${prefix}.link`, value: mask, membership: true };
        });
    }
    patch(direction, state, riders = []) {
        const entry = (direction === 'undo' ? this.undo : this.redo).at(-1);
        if (!entry) return null;
        const undo = direction === 'undo';
        const changes = entry.changes.map(c => ({ ...c, before: undo ? c.after : c.before, after: undo ? c.before : c.after }));
        const guards = undo ? entry.guardsAfter : entry.guardsBefore;
        for (const c of changes) {
            const now = getPath(state, c.path);
            if (now == null || (c.mask ? (now & c.mask) !== (c.before & c.mask) : !same(now, c.before))) throw new Error(`History conflict at ${c.path}.`);
        }
        for (const g of guards) {
            const [side, index] = g.path.split('.');
            const mask = linkedMembers(state?.[side] ?? [], Number(index)).reduce((mask, i) => mask | (1 << i), 0);
            if (mask !== g.value) throw new Error(`History conflict at ${g.path}.`);
        }
        const patch = { changes, guards };
        if (!same(entry.ridersBefore, entry.ridersAfter)) {
            const channels = [...new Set([...entry.ridersBefore, ...entry.ridersAfter].map(r => r.channel))].filter(channel => !same(entry.ridersBefore.find(r => r.channel === channel), entry.ridersAfter.find(r => r.channel === channel))).sort((a,b) => a-b);
            patch.riders = { channels, before: (undo ? entry.ridersAfter : entry.ridersBefore).filter(r => channels.includes(r.channel)), after: (undo ? entry.ridersBefore : entry.ridersAfter).filter(r => channels.includes(r.channel)) };
            if (!same(riders.filter(r => channels.includes(r.channel)), patch.riders.before)) throw new Error('History conflict in gain riders.');
        }
        return patch;
    }
    moved(direction) {
        const from = direction === 'undo' ? this.undo : this.redo;
        const to = direction === 'undo' ? this.redo : this.undo;
        to.push(from.pop());
        this.notify();
    }
    clear(message = '', dropPending = true) {
        this.undo = []; this.redo = [];
        if (dropPending) { this.pending = []; this.current = null; this.depth = 0; }
        this.message = message; this.notify();
    }
}

/** Text controls keep their browser-native undo stack. Range, checkbox and select controls use editor history. */
export function nativeUndo(target) {
    return Boolean(target?.closest('textarea, [contenteditable]:not([contenteditable="false"]), input:not([type=range]):not([type=checkbox]):not([type=radio]):not([type=button]):not([type=submit])'));
}

/** User-facing descriptions use channel IDs and setting names rather than internal state paths. */
export function describeSetting(path) {
    const parts = path.split('.');
    let channel = parts.shift();
    if (channel === 'inputs' || channel === 'outputs') channel = `${channel === 'inputs' ? 'In' : 'Out'}${Number(parts.shift()) + 1}`;
    else if (channel === 'aux') channel = 'Aux';
    else if (channel === 'groups' || channel === 'sub') channel = t(channel === 'groups' ? 'Group' : 'Sub Control') + ` ${Number(parts.shift()) + 1}`;
    const labels = {gain:'Gain',mute:'Mute',phase:'Phase',threshold:'Threshold',ratio:'Ratio',attack:'Attack',release:'Release',freq:'Frequency',type:'Type',bypass:'Bypass',name:'Name',delay:'Delay',route:'Routing',select:'Selection',mask:'Selection',link:'Link',eq_bypass:'EQ Bypass',gate:'Gate',comp:'Compressor',hpf:'HPF',lpf:'LPF'};
    return [channel, ...parts.map((part,i) => /^\d+$/.test(part) && parts[i-1] === 'peq' ? t('Band %number%',{number:Number(part)+1}) : t(labels[part] ?? part.replaceAll('_',' ')))].join(' ');
}
export function historyMessage(message) {
    const match = /^History conflict at (.+)\.$/.exec(message);
    return match ? t('History conflict at %setting%.',{setting:describeSetting(match[1])}) : t(message);
}
