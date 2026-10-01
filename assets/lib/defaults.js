// Compares the device state with the factory defaults, so the editor can mark every changed setting.
import { getPath } from './store.js';

/**
 * Whether the value at path differs from its factory default. With bit >= 0 only that bit of a mask counts.
 * A path without a default, or a value the device does not report, is never changed.
 */
export function isChanged(state, defaults, path, bit = -1) {
    const value = getPath(state, path);
    const factory = getPath(defaults, path);
    if (undefined === factory || null === factory || undefined === value || null === value) {
        return false;
    }
    if (bit >= 0) {
        return ((value >> bit) & 1) !== ((factory >> bit) & 1);
    }
    return value !== factory;
}

/**
 * Every leaf path below node that differs from defaultNode, prefixed with prefix.
 */
export function changedPaths(node, defaultNode, prefix = '') {
    if (null === defaultNode || 'object' !== typeof defaultNode) {
        return isChanged({ v: node }, { v: defaultNode }, 'v') ? [prefix] : [];
    }
    const paths = [];
    for (const key of Object.keys(defaultNode)) {
        paths.push(...changedPaths(node?.[key], defaultNode[key], prefix ? `${prefix}.${key}` : key));
    }
    return paths;
}

// Editable settings of each function page. Auto EQ owns output PEQ bands and their bypass state.
const PAGE_PATHS = {
    gain: /^(?:(?:inputs|outputs)\.\d+\.(?:gain|phase|mute)$|inputs\.\d+\.mode$|(?:sub|groups)\.)/,
    gate: /^inputs\.\d+\.gate\./,
    comp: /^outputs\.\d+\.comp\./,
    delay: /^(?:outputs\.\d+\.delay$|delay_unit$)/,
    matrix: /^outputs\.\d+\.route$/,
    aux: /^aux\./,
    autoeq: /^outputs\.\d+\.(?:peq\.|eq_bypass$)/,
};

/**
 * Changed factory-backed settings per function page. Shared controls use the same change paths.
 * View preferences and values without a reported default never mark a page. Scan the state once per update.
 */
export function pageChanges(state, defaults) {
    const paths = changedPaths(state, defaults);
    return Object.fromEntries(Object.entries(PAGE_PATHS).map(([page, pattern]) => [page, paths.filter((path) => pattern.test(path))]));
}
