// Shared EQ display preferences. Channel processing and device writes are independent.
import { readPref, writePref } from './prefs.js';
import { loadEqView } from './eq.js';
import { linkedPaths } from './overview.js';
import { selectionState } from './channelview.js';

export const EQ_PATHS = [...Array.from({ length: 8 }, (_, i) => `inputs.${i}`), ...Array.from({ length: 8 }, (_, i) => `outputs.${i}`), 'aux'];
export function filterLabels(path) { return readPref('eq-filter-labels', {})[path] === true; }
export function setFilterLabels(path, show) {
    if (EQ_PATHS.includes(path)) writePref('eq-filter-labels', { ...readPref('eq-filter-labels', {}), [path]: Boolean(show) });
}
export function overviewHidden() { return new Set(readPref('eq-overview-hidden', []).filter(path => EQ_PATHS.includes(path))); }
/** Layout is independent of curve selection and the shared magnitude/phase view. */
export function overviewSeparated() { return readPref('eq-overview-separated', false); }
export function setOverviewSeparated(separated) { writePref('eq-overview-separated', Boolean(separated)); }
export function selectOverview(paths, show) {
    const hidden = overviewHidden();
    paths.filter(path => EQ_PATHS.includes(path)).forEach(path => show ? hidden.delete(path) : hidden.add(path));
    writePref('eq-overview-hidden', [...hidden]);
}
export function setPanelCollapsed(key, collapsed) {
    const all = { ...readPref('collapsed', {}) };
    if (collapsed) all[key] = true; else delete all[key];
    writePref('collapsed', all);
}

/** Browser EQ groups retain every member's choices, even when all members are hidden globally. */
export function eqPreferencePaths(path, state, grouped) { return grouped ? linkedPaths(path, state) : [path]; }
export function eqPreferenceSelection(paths, field) {
    return selectionState(paths.map(path => field === 'labels' ? filterLabels(path) : loadEqView(path)[field]));
}
/** Write a group preference once, preserving unrelated fields, channels and existing storage formats. */
export function setEqPreference(paths, field, selected) {
    if (!['mag', 'phase', 'labels'].includes(field)) return;
    const valid = paths.filter(path => EQ_PATHS.includes(path) || (path === 'overview' && field !== 'labels'));
    if (!valid.length) return;
    const key = field === 'labels' ? 'eq-filter-labels' : 'eq-view';
    const all = { ...readPref(key, {}) };
    for (const path of valid) {
        const { mag, phase } = loadEqView(path);
        all[path] = field === 'labels' ? Boolean(selected) : { mag, phase, [field]: Boolean(selected) };
    }
    writePref(key, all);
}
