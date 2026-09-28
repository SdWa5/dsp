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
