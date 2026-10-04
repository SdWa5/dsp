// Compares the device state with the factory defaults, so the editor can mark every changed setting.
import { getPath } from './store.js';
import { GAIN_UNITY } from './scale.js';

/** Link membership is how channels are grouped, not a setting of its own, so it never counts as changed. */
const isLink = (path) => /(?:^|\.)link$/.test(path);

/**
 * The defaults the editor compares against: the factory file with every input gain at 0 dB (raw 280). The factory
 * gain of an input is raw 0, which mutes it, so a factory reset would leave every input silent.
 */
export function referenceDefaults(defaults) {
    if (!Array.isArray(defaults?.inputs)) {
        return defaults;
    }
    return { ...defaults, inputs: defaults.inputs.map((channel) => ({ ...channel, gain: GAIN_UNITY })) };
}

/**
 * Whether the value at path differs from its factory default. With bit >= 0 only that bit of a mask counts.
 * A path without a default, or a value the device does not report, is never changed.
 */
export function isChanged(state, defaults, path, bit = -1) {
    const value = getPath(state, path);
    const factory = getPath(defaults, path);
    if (isLink(path) || undefined === factory || null === factory || undefined === value || null === value) {
        return false;
    }
    if (bit >= 0) {
        return ((value >> bit) & 1) !== ((factory >> bit) & 1);
    }
    return value !== factory;
}

/**
 * The value a reset writes at path: the factory value, or with bit >= 0 the current mask with only that bit taken
 * from the factory. Null where there is no default.
 */
export function factoryValue(defaults, path, current, bit = -1) {
    const factory = getPath(defaults, path);
    if (undefined === factory || null === factory) {
        return null;
    }
    if (bit >= 0) {
        return ((current ?? 0) & ~(1 << bit)) | (factory & (1 << bit));
    }
    return factory;
}

/**
 * Every leaf path below node that differs from defaultNode, prefixed with prefix. Link membership is left out.
 */
export function changedPaths(node, defaultNode, prefix = '') {
    if (null === defaultNode || 'object' !== typeof defaultNode) {
        return isChanged({ v: node }, { v: defaultNode }, 'v') ? [prefix] : [];
    }
    const paths = [];
    for (const key of Object.keys(defaultNode)) {
        if ('link' === key) {
            continue;
        }
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
    matrix: /^(?:outputs\.\d+\.route$|aux\.select$)/,
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
