import { t } from './i18n.js';

const panelTitle = (panel) => panel.querySelector('[data-collapse-target=toggle]')?.textContent.trim();

/**
 * Enumerate each shared collapse key once, using the rendered, translated section title. A panel inside another
 * one carries its parent's title as well, as in "Channel pages / PEQ / Bands".
 */
export function panelOptions(root) {
    const contexts = { gain: 'Gain page', gate: 'Gate page', comp: 'Comp page', matrix: 'Matrix page',
        display: 'Display Settings', channel: 'Channel pages', aux: 'Aux page', autoeq: 'Auto EQ page' };
    const entries = new Map();
    for (const panel of root.querySelectorAll('[data-collapse-key-value]')) {
        const key = panel.dataset.collapseKeyValue;
        if (entries.has(key)) continue;
        const title = panelTitle(panel);
        const parent = panel.parentElement?.closest('[data-collapse-key-value]');
        const path = [t(contexts[key.split('.')[0]] ?? 'Panels'), parent ? panelTitle(parent) : null, title].filter(Boolean);
        if (title) entries.set(key, { key, label: path.join(' / ') });
    }
    return [...entries.values()];
}

/** Group choices by page context without duplicating shared channel keys or changing stored preferences. */
export function panelRows(options) {
    const rows = new Map();
    for (const option of options) {
        const context = option.key.split('.')[0];
        if (!rows.has(context)) rows.set(context, { context, label: option.label.split(' / ')[0], options: [] });
        rows.get(context).options.push(option);
    }
    return [...rows.values()];
}
