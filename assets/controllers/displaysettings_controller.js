import { Controller } from '@hotwired/stimulus';
import { store } from '../lib/device.js';
import { readPref, writePref, onPref } from '../lib/prefs.js';
import { getLinkView, setLinkView, onLinkView } from '../lib/linkview.js';
import { linkGroups, linkMark, linkName } from '../lib/link.js';
import { getChannelView, onChannelView } from '../lib/channelview.js';
import { channelId } from '../lib/channels.js';
import { renderLinkNames } from '../lib/linknames.js';
import { eqPreferencePaths, eqPreferenceSelection, setEqPreference, overviewHidden, selectOverview, setPanelCollapsed } from '../lib/displayprefs.js';
import { panelOptions, panelRows } from '../lib/displaypanels.js';
import { t } from '../lib/i18n.js';

/** The complete browser display overview. Local shortcuts share preferences; no action sends a device write. */
export default class extends Controller {
    static targets = ['theme', 'matrix', 'eq', 'overview', 'panels', 'names', 'namesEmpty'];

    connect() {
        this.buildPanels();
        this.offs = [onPref(['theme', 'matrix-swapped', 'eq-view', 'eq-filter-labels', 'eq-overview-hidden', 'collapsed'], () => this.render()),
            onLinkView(() => this.render()), onChannelView(() => this.render()), store.subscribe(() => this.render())];
        this.render();
    }
    disconnect() { this.offs.forEach(off => off()); }
    render() {
        const theme = readPref('theme', 'auto');
        this.themeTarget.value = ['auto', 'light', 'dark'].includes(theme) ? theme : 'auto';
        this.matrixTarget.checked = readPref('matrix-swapped', false);
        for (const box of this.eqTargets) {
            const paths = eqPreferencePaths(box.dataset.path, store.state, getChannelView().grouped);
            const selected = eqPreferenceSelection(paths, box.dataset.field);
            box.checked = selected.selected; box.indeterminate = selected.mixed;
            box.setAttribute('aria-checked', selected.mixed ? 'mixed' : String(selected.selected));
            box.dataset.tooltip ??= box.title;
            const groupTip = paths.length > 1 ? this.eqGroupTip(paths) : '';
            box.title = box.closest('label').title = box.dataset.tooltip + (groupTip ? '\n' + groupTip : '');
        }
        const hidden = overviewHidden();
        this.overviewTargets.forEach(box => { box.checked = !hidden.has(box.value); });
        this.panelsTarget.querySelectorAll('input').forEach(box => { box.checked = readPref('collapsed', {})[box.dataset.key] !== true; });
        this.renderNames();
        this.renderEqGroups();
        // Refresh mixed aggregate selectors without writing or changing individual choices.
        this.element.querySelectorAll('[data-controller~=selectors]').forEach(element => {
            this.application.getControllerForElementAndIdentifier(element, 'selectors')?.render();
        });
    }
    theme() { writePref('theme', this.themeTarget.value); }
    matrix() { writePref('matrix-swapped', this.matrixTarget.checked); }
    eq(event) {
        const { path, field } = event.target.dataset;
        setEqPreference(eqPreferencePaths(path, store.state, getChannelView().grouped), field, event.target.checked);
    }
    /** Group headings replace member legends without rebuilding or moving preference inputs. */
    renderEqGroups() {
        const view = getLinkView();
        for (const row of this.element.querySelectorAll('.display-settings__eq')) {
            const path = row.querySelector('[data-displaysettings-target=eq]').dataset.path;
            const paths = eqPreferencePaths(path, store.state, getChannelView().grouped);
            const grouped = paths.length > 1;
            row.classList.toggle('is-grouped', grouped);
            row.querySelector('.ch').hidden = grouped;
            const heading = row.querySelector('.display-settings__eq-group-label');
            heading.hidden = !grouped;
            if (grouped) {
                const [side, index] = path.split('.');
                const groups = linkGroups(store.state?.[side] ?? []), group = groups[Number(index)];
                heading.dataset.group = view.colour ? String(group) : '';
                heading.innerHTML = linkMark(view, group, linkName(view, side, groups, group)) || `L${group}`;
                heading.title = this.eqGroupTip(paths);
            }
        }
    }
    eqGroupTip(paths) {
        const indices = paths.map(path => { const [side, index] = path.split('.'); return Number(index) + (side === 'outputs' ? 8 : 0); });
        const channels = indices.map(channelId).join(', ');
        const hidden = indices.filter(index => getChannelView().hidden.includes(index)).map(channelId).join(', ');
        return t('Group preference for %channels%. Applies to every member, including hidden channels. Mixed means members differ.', { channels })
            + (hidden ? '\n' + t('Hidden members: %channels%', { channels: hidden }) : '');
    }

    overview(event) { selectOverview(eqPreferencePaths(event.target.value, store.state, getChannelView().grouped), event.target.checked); }
    panel(event) { setPanelCollapsed(event.target.dataset.key, !event.target.checked); }
    buildPanels() {
        const rows = panelRows(panelOptions(this.element.closest('.editor'))).map(({ context, label: heading, options }) => {
            const row = document.createElement('div'); row.className = 'display-settings__panel-row';
            row.dataset.panelPage = context; row.setAttribute('role', 'group'); row.setAttribute('aria-label', heading);
            const title = document.createElement('strong'); title.className = 'display-settings__panel-heading'; title.textContent = heading;
            row.append(title);
            for (const { key, label: text } of options) {
                const label = document.createElement('label'); label.className = 'check';
                const box = document.createElement('input'); box.type = 'checkbox'; box.dataset.key = key;
                box.dataset.action = 'change->displaysettings#panel';
                // Full context stays in the tooltip; the row heading supplies it visually.
                label.title = box.title = t('Show or hide %panel%. Shared channel sections use the same setting on every channel page.', { panel: text });
                label.append(box, text.slice(text.indexOf(' / ') + 3)); row.append(label);
            }
            return row;
        });
        this.panelsTarget.replaceChildren(...rows);
    }

    renderNames() {
        const groups = Object.fromEntries(['inputs', 'outputs'].map(side => [side, linkGroups(store.state?.[side] ?? [])]));
        renderLinkNames(this.namesTarget, this.namesEmptyTarget, groups, getLinkView(), 'input->displaysettings#renameGroup', true);
    }
    renameGroup(event) {
        const view = getLinkView(); const names = { ...view.names };
        const name = event.target.value.trim();
        if (name) names[event.target.dataset.key] = name; else delete names[event.target.dataset.key];
        setLinkView({ ...view, names });
    }
}
