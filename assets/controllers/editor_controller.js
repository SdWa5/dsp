import { Controller } from '@hotwired/stimulus';
import { api, getDefaults, listen, on, send, status, store } from '../lib/device.js';
import { changedPaths } from '../lib/defaults.js';
import { describeConnection } from '../lib/connection.js';
import { channelId, channelLabel, channelParts } from '../lib/channels.js';
import { groupsFromPartners, linkCommands, linkGroups, linkedMembers, linkLayout, linkView, partnersFromGroups } from '../lib/link.js';
import { GAIN_MUTED, presetLabel, presetSlotInfo } from '../lib/scale.js';
import { readPref, writePref } from '../lib/prefs.js';

const THEMES = ['auto', 'light', 'dark'];
/** How the channel tabs show link groups, each switched on its own in the Channels menu. */
const changedText = (count) => `${count} ${1 === count ? 'setting differs' : 'settings differ'} from the factory default`;

/**
 * The editor frame: tabs, connection state, menus and the dialogs that do more than set one value
 * (connect, presets, link, copy, preset files).
 */
export default class extends Controller {
    static targets = ['tab', 'page', 'online', 'banner', 'bannerText', 'number', 'error', 'dialog', 'status', 'target', 'slots',
        'storeName', 'linkGroup', 'file', 'scanButton', 'scanStatus', 'found', 'theme', 'matrix', 'linkView', 'linkPlacement', 'chtabs'];

    connect() {
        const theme = readPref('theme', 'auto');
        this.applyTheme(THEMES.includes(theme) ? theme : 'auto');
        this.showMatrix(readPref('matrix-swapped', false));
        this.linkViewSettings = linkView(readPref('link-view', {}));
        this.linkViewTargets.forEach((box) => {
            box.checked = Boolean(this.linkViewSettings[box.value]);
        });
        this.linkPlacementTargets.forEach((radio) => {
            radio.checked = radio.value === this.linkViewSettings.placement;
        });
        this.offs = [
            on('status', () => this.showStatus()),
            on('error', (message) => this.showError(message)),
            store.subscribe(() => this.showState()),
        ];
        const page = window.location.hash.slice(1);
        this.show(this.pageTargets.some((p) => p.dataset.page === page) ? page : 'gain');
        this.showStatus();
        this.showState();
        listen();
    }

    disconnect() {
        this.offs.forEach((off) => off());
    }

    // --- display --------------------------------------------------------------------------------

    tab(event) {
        this.closeMenu();
        this.show(event.params.page);
        window.history.replaceState(null, '', `#${event.params.page}`);
    }

    /** A menu stays open while it holds the focus. */
    closeMenu() {
        if (document.activeElement?.closest('.menu')) {
            document.activeElement.blur();
        }
    }

    show(page) {
        for (const section of this.pageTargets) {
            section.hidden = section.dataset.page !== page;
        }
        for (const tab of this.tabTargets) {
            const active = tab.dataset.page === page;
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
        }
        // A canvas measures its size only while visible
        window.dispatchEvent(new Event('resize'));
    }

    showStatus() {
        const { label, online, demo, banner } = describeConnection(status);
        this.onlineTarget.textContent = label;
        this.onlineTarget.classList.toggle('is-on', online);
        this.onlineTarget.classList.toggle('is-demo', demo);
        this.bannerTextTarget.textContent = banner;
        this.bannerTarget.hidden = '' === banner;
        if (this.hasStatusTarget) {
            const info = status.info ? ` (${status.info})` : '';
            this.statusTarget.textContent = online ? `Connected to ${status.target}${info}.` : banner;
        }
    }

    showState() {
        const state = store.state;
        for (const number of this.numberTargets) {
            number.textContent = presetLabel(state?.active_preset);
        }
        this.placeChannelTabs(state);
        for (const tab of this.tabTargets) {
            if (tab.dataset.side) {
                this.showChannelTab(tab, state);
            } else if ('aux' === tab.dataset.page) {
                const count = state ? changedPaths(state.aux, getDefaults().aux).length : 0;
                tab.classList.toggle('is-changed', count > 0);
                tab.title = count > 0 ? changedText(count) : '';
            }
        }
    }

    /** A channel tab: ID, name, a dot while muted (hollow for a gain at raw 0), the link group and a mark when it differs from the factory. */
    showChannelTab(tab, state) {
        const side = tab.dataset.side;
        const index = Number(tab.dataset.index);
        const channel = ('outputs' === side ? 8 : 0) + index;
        const { name } = channelParts(channel, state);
        tab.querySelector('.chtab__name').textContent = name;
        const marks = [];
        const titles = [channelLabel(channel, state)];
        if (state?.[side]?.[index]?.mute) {
            marks.push('<span class="chtab__mute" aria-hidden="true"></span>');
            titles.push('muted');
        } else if (GAIN_MUTED === state?.[side]?.[index]?.gain) {
            marks.push('<span class="chtab__mute is-gain" aria-hidden="true"></span>');
            titles.push('muted by its gain');
        }
        if (state) {
            const group = linkGroups(state[side])[index];
            const members = linkedMembers(state[side], index);
            const { code, colour } = this.linkViewSettings;
            tab.dataset.group = colour && group > 0 ? String(group) : '';
            if (group > 0 && (code || colour)) {
                // Without the code the group gets a shape of its own, so it does not rest on colour alone
                marks.push(code ? `<span class="chtab__link">L${group}</span>` : `<span class="chtab__link chtab__link--shape" data-shape="${group}" aria-hidden="true"></span>`);
            }
            if (group > 0) {
                titles.push(`linked with ${members.filter((m) => m !== index).map((m) => channelId(('outputs' === side ? 8 : 0) + m)).join(', ')}`);
            }
            const count = changedPaths(state[side]?.[index], getDefaults()[side]?.[index]).length;
            if (count > 0) {
                marks.push('<span class="chtab__changed" aria-hidden="true"></span>');
                titles.push(changedText(count));
            }
        }
        const html = marks.join('');
        const box = tab.querySelector('.chtab__marks');
        if (box.innerHTML !== html) {
            box.innerHTML = html;
        }
        tab.title = titles.join(', ');
    }

    /** The channel tabs placed by the link placement (normal, side by side or stacked). The DOM order stays. */
    placeChannelTabs(state) {
        const placement = null === state ? 'normal' : this.linkViewSettings.placement;
        const [inputs, outputs] = ['inputs', 'outputs'].map((side) => linkLayout(linkGroups(state?.[side] ?? []), placement));
        const offset = { inputs: 0, outputs: inputs.columns };
        const rows = Math.max(inputs.rows, outputs.rows);
        this.chtabsTargets.forEach((box) => {
            box.style.setProperty('--chtab-columns', String(inputs.columns + outputs.columns));
            box.classList.toggle('is-stacked', rows > 1);
        });
        for (const tab of this.tabTargets.filter((t) => t.dataset.side)) {
            const side = tab.dataset.side;
            const layout = 'inputs' === side ? inputs : outputs;
            const cell = layout.cells[Number(tab.dataset.index)];
            // A side with fewer rows than the other lets its last cells reach the bottom
            const span = cell.row + cell.span - 1 === layout.rows ? rows - cell.row + 1 : cell.span;
            tab.style.gridColumn = String(offset[side] + cell.column);
            tab.style.gridRow = `${cell.row} / span ${span}`;
            // The gap between the inputs and the outputs goes before the first output column
            tab.classList.toggle('is-split', 'outputs' === side && 1 === cell.column);
        }
    }

    /** Link groups of one side as the Link dialog's dropdowns name them. */
    dialogGroups(side) {
        return groupsFromPartners(this.linkGroupTargets.filter((s) => s.dataset.side === side).map((s) => Number(s.value)));
    }

    /**
     * The Link dialog's cells: group code, colour or shape as on the channel tabs. place: also arrange them by the
     * placement, which happens on open only, so a cell does not jump away while its dropdown is in use.
     */
    showLinkDialog(place) {
        const { code, colour, placement } = this.linkViewSettings;
        for (const side of ['inputs', 'outputs']) {
            const selects = this.linkGroupTargets.filter((s) => s.dataset.side === side);
            const groups = this.dialogGroups(side);
            const layout = linkLayout(groups, placement);
            const stacked = 'stacked' === placement;
            if (place) {
                // Stacked needs a column per link group, the other placements wrap in channel order
                const grid = selects[0]?.closest('.link-grid');
                grid?.classList.toggle('is-stacked', stacked);
                grid?.style.setProperty('--link-columns', String(layout.columns));
            }
            for (const select of selects) {
                const index = Number(select.dataset.index);
                const group = groups[index];
                const label = select.closest('label');
                if (place) {
                    const cell = layout.cells[index];
                    label.style.order = stacked ? '' : String(cell.column);
                    label.style.gridColumn = stacked ? String(cell.column) : '';
                    label.style.gridRow = stacked ? `${cell.row} / span ${cell.span}` : '';
                }
                label.dataset.group = colour && group > 0 ? String(group) : '';
                const mark = label.querySelector('.link-mark');
                mark.hidden = 0 === group || !(code || colour);
                mark.textContent = code ? `L${group}` : '';
                mark.classList.toggle('link-mark--shape', !code);
                mark.dataset.shape = String(group);
            }
        }
    }

    /** A dropdown of the Link dialog changed: recolour, but keep the cells where they are. */
    linkGroupChanged() {
        this.showLinkDialog(false);
    }

    linkView(event) {
        this.linkViewSettings[event.target.value] = event.target.checked;
        writePref('link-view', this.linkViewSettings);
        this.showState();
    }

    linkPlacement(event) {
        this.linkViewSettings.placement = event.target.value;
        writePref('link-view', this.linkViewSettings);
        this.showState();
    }

    theme() {
        const next = THEMES[(THEMES.indexOf(this.currentTheme) + 1) % THEMES.length];
        this.applyTheme(next);
        writePref('theme', next);
    }

    /** Inputs as rows instead of outputs, kept per browser. */
    swapMatrix() {
        const swapped = !this.matrixSwapped;
        this.showMatrix(swapped);
        writePref('matrix-swapped', swapped);
        this.matrixTargets.find((table) => !table.hidden)?.querySelector('.matrix__corner')?.focus();
    }

    showMatrix(swapped) {
        this.matrixSwapped = swapped;
        this.matrixTargets.forEach((table) => {
            table.hidden = swapped !== ('1' === table.dataset.swapped);
        });
    }

    applyTheme(theme) {
        this.currentTheme = theme;
        if ('auto' === theme) {
            delete document.documentElement.dataset.theme;
        } else {
            document.documentElement.dataset.theme = theme;
        }
        if (this.hasThemeTarget) {
            this.themeTarget.textContent = { auto: 'Auto', light: 'Light', dark: 'Dark' }[theme];
            this.themeTarget.title = `Colour theme: ${theme}. Click to change.`;
        }
    }

    showError(message) {
        this.errorTarget.textContent = message;
        clearTimeout(this.errorTimer);
        this.errorTimer = setTimeout(() => { this.errorTarget.textContent = ''; }, 8000);
    }

    // --- dialogs --------------------------------------------------------------------------------

    open(event) {
        this.closeMenu();
        const dialog = this.dialogTargets.find((d) => d.id === `dialog-${event.params.dialog}`);
        const state = store.state;
        if ('connect' === event.params.dialog && status.target) {
            this.targetTarget.value = status.target;
        }
        if (['store', 'recall'].includes(event.params.dialog)) {
            this.fillSlots(dialog.querySelector('[data-editor-target=slots]'), state);
            if ('store' === event.params.dialog) {
                this.storeNameTarget.value = (state?.preset_name ?? '').trim();
            }
        }
        if ('link' === event.params.dialog && state) {
            const groups = { inputs: linkGroups(state.inputs), outputs: linkGroups(state.outputs) };
            const partners = { inputs: partnersFromGroups(groups.inputs), outputs: partnersFromGroups(groups.outputs) };
            for (const select of this.linkGroupTargets) {
                select.value = String(partners[select.dataset.side][Number(select.dataset.index)]);
            }
            this.showLinkDialog(true);
        }
        dialog.showModal();
    }

    close(event) {
        event.target.closest('dialog').close();
    }

    fillSlots(select, state) {
        const first = Number(select.dataset.first);
        // Only the recall dialog starts at F00, and an empty slot cannot be recalled
        const recall = 0 === first;
        const selected = select.value || String(Math.max(first, state?.active_preset ?? first));
        select.replaceChildren(...Array.from({ length: 21 - first }, (_v, k) => {
            const number = first + k;
            const info = presetSlotInfo(state, number);
            const option = new Option(info.text, String(number));
            option.disabled = recall && false === info.stored;
            return option;
        }));
        select.value = selected;
        if (select.selectedOptions[0]?.disabled) {
            select.value = String(first);
        }
    }

    // --- actions --------------------------------------------------------------------------------

    async switchTarget(target) {
        try {
            Object.assign(status, await api('connect', target ? { target } : {}));
            this.showStatus();
        } catch (error) {
            this.showError(error.message);
        }
    }

    connectTo(event) {
        event.preventDefault();
        this.switchTarget(this.targetTarget.value.trim());
    }

    /** Searches USB and the local networks and lists what it finds, one click connects. */
    async scan() {
        this.scanButtonTarget.disabled = true;
        this.scanStatusTarget.hidden = false;
        this.scanStatusTarget.textContent = 'Searching USB and the network…';
        this.foundTarget.replaceChildren();
        try {
            const result = await api('discover');
            const networks = result.networks.join(', ') || 'no network';
            this.scanStatusTarget.textContent = 0 === result.devices.length
                ? `No DSP found on USB or on ${networks}.`
                : `Found on USB and ${networks}:`;
            this.foundTarget.replaceChildren(...result.devices.map((device) => this.foundItem(device)));
        } catch (error) {
            this.scanStatusTarget.textContent = error.message;
        } finally {
            this.scanButtonTarget.disabled = false;
        }
    }

    foundItem(device) {
        const item = document.createElement('li');
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'toggle';
        button.textContent = device.connected ? `${device.target} (connected)` : `Connect ${device.target}`;
        button.disabled = Boolean(device.connected);
        button.addEventListener('click', () => {
            this.targetTarget.value = device.target;
            this.switchTarget(device.target);
        });
        const info = document.createElement('span');
        info.textContent = ` ${device.kind.toUpperCase()} · ${device.info}`;
        item.classList.toggle('is-busy', Boolean(device.busy));
        item.append(button, info);
        return item;
    }

    async disconnectDevice() {
        try {
            Object.assign(status, await api('disconnect'));
            this.showStatus();
        } catch (error) {
            this.showError(error.message);
        }
    }

    async refresh() {
        try {
            await api('refresh');
        } catch (error) {
            this.showError(error.message);
        }
    }

    requireState() {
        if (null === store.state) {
            this.showError('Not connected to the device.');
            return false;
        }
        return true;
    }

    store(event) {
        event.preventDefault();
        const form = event.target;
        const number = Number(form.elements.number.value);
        const name = form.elements.name.value.trim();
        const info = presetSlotInfo(store.state, number);
        const warning = false !== info.stored && info.name ? ` It replaces "${info.name}".` : '';
        if (!this.requireState() || !window.confirm(`Store the live settings as ${presetLabel(number)} "${name}"?${warning}`)) {
            return;
        }
        send([{ command: 'presetName', args: { name } }, { command: 'storePreset', args: { number } }], false);
        form.closest('dialog').close();
    }

    recall(event) {
        event.preventDefault();
        const number = Number(event.target.elements.number.value);
        if (this.requireState() && window.confirm(`Recall ${presetLabel(number)}? It replaces all live settings.`)) {
            send([{ command: 'loadPreset', args: { number } }], false);
            event.target.closest('dialog').close();
        }
    }

    factory() {
        if (this.requireState() && window.confirm('Recall the factory default F00? It replaces all live settings.')) {
            send([{ command: 'loadPreset', args: { number: 0 } }], false);
        }
    }

    async link(event) {
        event.preventDefault();
        if (!this.requireState()) {
            return;
        }
        const state = store.state;
        const commands = [];
        for (const [side, base] of [['inputs', 0], ['outputs', 8]]) {
            commands.push(...linkCommands(state[side], this.dialogGroups(side), base));
        }
        if (0 === commands.length) {
            event.target.closest('dialog').close();
            return;
        }
        const copies = commands.filter((c) => 'copyChannel' === c.command).length;
        if (copies > 0 && !window.confirm(`Linking copies the first channel of each group to ${copies} channel(s). Go on?`)) {
            return;
        }
        await send([...commands, { command: 'activate', args: {} }], false);
        event.target.closest('dialog').close();
        this.refresh();
    }

    async copy(event) {
        event.preventDefault();
        const source = Number(event.target.elements.source.value);
        const target = Number(event.target.elements.target.value);
        const name = (c) => channelLabel(c, store.state);
        if (source === target || (source < 8) !== (target < 8)) {
            this.showError('Copy an input to another input, or an output to another output.');
            return;
        }
        if (!this.requireState() || !window.confirm(`Copy every setting of ${name(source)} to ${name(target)}?`)) {
            return;
        }
        await send([{ command: 'copyChannel', args: { source, target } }], false);
        event.target.closest('dialog').close();
        this.refresh();
    }

    openFile() {
        this.fileTarget.click();
    }

    async upload() {
        const file = this.fileTarget.files[0];
        this.fileTarget.value = '';
        if (!file || !this.requireState() || !window.confirm(`Send every setting of ${file.name} to the device?`)) {
            return;
        }
        try {
            // As a Blob: a vendor .prs file is binary and would not survive a text round trip
            await api('upload', file);
            this.refresh();
        } catch (error) {
            this.showError(error.message);
        }
    }

    /** Downloads the live state as a preset file. Errors stay in the error line instead of replacing the page. */
    async saveFile() {
        if (!this.requireState()) {
            return;
        }
        try {
            const response = await fetch('/api/download');
            if (!response.ok) {
                const body = await response.json().catch(() => ({}));
                throw new Error(body.error ?? `Download failed (${response.status}).`);
            }
            const disposition = response.headers.get('Content-Disposition') ?? '';
            const link = document.createElement('a');
            link.href = URL.createObjectURL(await response.blob());
            link.download = /filename="([^"]+)"/.exec(disposition)?.[1] ?? 'preset.json';
            link.click();
            setTimeout(() => URL.revokeObjectURL(link.href), 1000);
        } catch (error) {
            this.showError(error.message);
        }
    }
}
