import { languageCookie, t } from '../lib/i18n.js';
import { Controller } from '@hotwired/stimulus';
import { api, getDefaults, listen, on, send, status, store } from '../lib/device.js';
import { changedPaths, pageChanges } from '../lib/defaults.js';
import { describeConnection } from '../lib/connection.js';
import { channelId, channelLabel } from '../lib/channels.js';
import { lcm, linkCommands, linkGroups, linkedMembers, linkMark, linkName, linkUnits, toggleLink } from '../lib/link.js';
import { GAIN_MUTED, presetLabel, presetSlotInfo } from '../lib/scale.js';
import { readPref, writePref, onPref } from '../lib/prefs.js';
import { getLinkView, setLinkView, onLinkView } from '../lib/linkview.js';
import { getChannelView, onChannelView, setChannelView, channelVisible, representative, displayChannelParts, channelLayout } from '../lib/channelview.js';

import { renderLinkNames } from '../lib/linknames.js';

const THEMES = ['auto', 'light', 'dark'];
/** The changed-setting tooltip remains independent of browser display preferences. */
const changedText = (count) => t(1 === count
    ? "%count% setting differs from the factory default"
    : "%count% settings differ from the factory default", { count });

/**
 * The editor frame: tabs, connection state, menus and the dialogs that do more than set one value
 * (connect, presets, link, copy, preset files).
 */
export default class extends Controller {
    static targets = ['tab', 'page', 'online', 'banner', 'bannerText', 'number', 'error', 'dialog', 'status', 'target', 'slots',
        'storeName', 'linkMatrix', 'file', 'scanButton', 'scanStatus', 'found', 'theme', 'matrix', 'linkView', 'linkPlacement', 'chtabs', 'linkNames', 'linkNamesEmpty', 'channelWarning', 'channelWarningText'];

    connect() {
        const theme = readPref('theme', 'auto');
        this.applyTheme(THEMES.includes(theme) ? theme : 'auto');
        this.showMatrix(readPref('matrix-swapped', false));
        this.linkViewSettings = { ...getLinkView() };
        this.showLinkViewSettings();
        this.offs = [
            on('status', () => this.showStatus()),
            on('error', (message) => this.showError(message)),
            store.subscribe(() => this.showState()),
            onChannelView(() => { this.showState(); this.show(this.currentPage); }),
            onPref('theme', () => { const theme = readPref('theme', 'auto'); this.applyTheme(THEMES.includes(theme) ? theme : 'auto'); }),
            onPref('matrix-swapped', () => this.showMatrix(readPref('matrix-swapped', false))),
            onLinkView(() => { this.linkViewSettings = { ...getLinkView() }; this.showLinkViewSettings(); this.showState(); if (this.linkDraft) this.showLinkDialog(); }),
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
        this.currentPage = page;
        this.showChannelPage();
        for (const section of this.pageTargets) {
            section.hidden = section.dataset.page !== page || this.hiddenPageChannel !== null;
        }
        for (const tab of this.tabTargets) {
            const match = /^(inputs|outputs)([0-7])$/.exec(page);
            const selected = match ? Number(match[2]) + (match[1] === 'outputs' ? 8 : 0) : null;
            const active = selected === null ? !tab.hidden && tab.dataset.page === page : !tab.hidden && tab.dataset.side === match[1]
                && Number(tab.dataset.index) + (match[1] === 'outputs' ? 8 : 0) === (getChannelView().grouped ? representative(selected, store.state) : selected);
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
        }
        // A canvas measures its size only while visible
        window.dispatchEvent(new Event('resize'));
    }

    /** A hidden detail page remains reachable only through its recovery warning. */
    showChannelPage() {
        const match = /^(inputs|outputs)([0-7])$/.exec(this.currentPage ?? '');
        const index = this.currentPage === 'aux' ? 18 : match ? Number(match[2]) + (match[1] === 'outputs' ? 8 : 0) : null;
        this.hiddenPageChannel = index !== null && !channelVisible(index) ? index : null;
        this.channelWarningTarget.hidden = this.hiddenPageChannel === null;
        if (this.hiddenPageChannel !== null) this.channelWarningTextTarget.textContent = t('%channel% is hidden by your display settings.', { channel: channelLabel(index, store.state) });
    }

    unhideChannel() {
        if (this.hiddenPageChannel !== null) setChannelView({ ...getChannelView(), hidden: getChannelView().hidden.filter(i => i !== this.hiddenPageChannel) });
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
            this.statusTarget.textContent = online ? t("Connected to %target%%info%.", { target: status.target, info }) : banner;
        }
    }

    showState() {
        const state = store.state;
        for (const number of this.numberTargets) {
            number.textContent = presetLabel(state?.active_preset);
        }
        this.placeChannelTabs(state);
        this.showChannelPage();
        const pages = pageChanges(state, getDefaults());
        for (const tab of this.tabTargets) {
            if (tab.dataset.side) {
                this.showChannelTab(tab, state);
            } else {
                if (tab.dataset.page === 'aux') tab.hidden = !channelVisible(18);
                const count = pages[tab.dataset.page]?.length ?? 0;
                const badge = tab.querySelector('.tab__count');
                tab.classList.toggle('is-changed', count > 0);
                tab.title = count > 0 ? changedText(count) : '';
                badge.hidden = 0 === count;
                badge.textContent = count > 0 ? String(count) : '';
            }
        }
    }

    /** A channel tab includes all representative member names, a dot while muted (hollow for a gain at raw 0), the link group and a mark when it differs from the factory. */
    showChannelTab(tab, state) {
        const side = tab.dataset.side;
        const index = Number(tab.dataset.index);
        const channel = ('outputs' === side ? 8 : 0) + index;
        const caption = displayChannelParts(channel, state);
        tab.querySelector('.chtab__name').textContent = caption.name;
        tab.querySelector('.chtab__id').textContent = caption.id;
        tab.classList.toggle('is-grouped', caption.members.length > 1);
        const marks = [];
        const titles = [caption.label];
        if (state?.[side]?.[index]?.mute) {
            marks.push('<span class="chtab__mute" aria-hidden="true"></span>');
            titles.push(t("muted"));
        } else if (GAIN_MUTED === state?.[side]?.[index]?.gain) {
            marks.push('<span class="chtab__mute is-gain" aria-hidden="true"></span>');
            titles.push(t("muted by its gain"));
        }
        if (state) {
            const groups = linkGroups(state[side]);
            const group = groups[index];
            const members = linkedMembers(state[side], index);
            const { code, colour } = this.linkViewSettings;
            tab.dataset.group = colour && group > 0 ? String(group) : '';
            // Stacked, the group's header above the column carries the mark
            const mark = 'stacked' === this.linkViewSettings.placement && members.length > 1 ? '' : linkMark(this.linkViewSettings, group, linkName(this.linkViewSettings, side, groups, group));
            if ('' !== mark) {
                marks.push(`<span class="chtab__link${code ? '' : ' chtab__link--bare'}">${mark}</span>`);
            }
            if (group > 0) {
                titles.push(t("linked with %channels%", { channels: members.filter((m) => m !== index).map((m) => channelId(('outputs' === side ? 8 : 0) + m)).join(', ') }));
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

    /**
     * The channel tabs placed by the link placement (normal, side by side or stacked). The DOM order stays.
     * Stacked: every link group gets a header with its code, icon and colour above its column, and its members
     * share the column height equally, both sides on one common row count.
     */
    placeChannelTabs(state) {
        const placement = null === state ? 'normal' : this.linkViewSettings.placement;
        const groups = { inputs: linkGroups(state?.inputs ?? []), outputs: linkGroups(state?.outputs ?? []) };
        const layouts = { inputs: channelLayout(groups.inputs, placement, 0), outputs: channelLayout(groups.outputs, placement, 8) };
        const offset = { inputs: 0, outputs: layouts.inputs.columns };
        const rows = lcm(layouts.inputs.rows, layouts.outputs.rows);
        const units = { inputs: layouts.inputs.units, outputs: layouts.outputs.units };
        const header = 'stacked' === placement && Object.entries(units).some(([side, units]) => units.some(unit => groups[side][unit[0]] > 0)) ? 1 : 0;
        this.chtabsTargets.forEach((box) => {
            box.style.setProperty('--chtab-columns', String(Math.max(1, layouts.inputs.columns + layouts.outputs.columns)));
            box.classList.toggle('is-stacked', rows > 1 || header > 0);
            box.style.gridTemplateRows = rows > 1 || header > 0 ? `${header ? 'auto ' : ''}repeat(${rows}, 1fr)` : '';
        });
        for (const tab of this.tabTargets.filter((t) => t.dataset.side)) {
            const side = tab.dataset.side;
            const cell = layouts[side].cells[Number(tab.dataset.index)];
            tab.hidden = !cell;
            if (!cell) continue;
            const scale = rows / layouts[side].rows;
            // A channel alone reaches up into the header row, a group member starts below it
            const alone = groups[side][Number(tab.dataset.index)] === 0;
            tab.style.gridColumn = String(offset[side] + cell.column);
            tab.style.gridRow = alone ? `1 / span ${header + rows}` : `${header + (cell.row - 1) * scale + 1} / span ${cell.span * scale}`;
            // The gap between the inputs and the outputs goes before the first output column
            tab.classList.toggle('is-split', 'outputs' === side && 1 === cell.column);
        }
        for (const tab of this.tabTargets.filter(t => t.dataset.side)) {
            const match = /^(inputs|outputs)([0-7])$/.exec(this.currentPage ?? '');
            if (!match) continue;
            const base = match[1] === 'outputs' ? 8 : 0;
            const active = !tab.hidden && tab.dataset.side === match[1] && Number(tab.dataset.index) + base === (getChannelView().grouped ? representative(Number(match[2]) + base, state) : Number(match[2]) + base);
            tab.classList.toggle('is-active', active); tab.setAttribute('aria-selected', String(active));
        }
        // The headers change only with the groups and the display settings, not with every state update
        const key = JSON.stringify([header, units, this.linkViewSettings, getChannelView()]);
        if (key === this.groupHeadKey) {
            return;
        }
        this.groupHeadKey = key;
        this.chtabsTargets.forEach((box) => box.querySelectorAll('.chtab-group').forEach((old) => old.remove()));
        if (header) {
            const { colour } = this.linkViewSettings;
            for (const side of ['inputs', 'outputs']) {
                units[side].forEach((unit, column) => {
                    if (groups[side][unit[0]] === 0) {
                        return;
                    }
                    const group = groups[side][unit[0]];
                    const head = document.createElement('span');
                    head.className = 'chtab-group' + ('outputs' === side && 0 === column ? ' is-split' : '');
                    head.dataset.group = colour ? String(group) : '';
                    head.style.gridColumn = String(offset[side] + column + 1);
                    head.style.gridRow = '1';
                    head.title = t('Linked: %channels%', { channels: unit.map((i) => channelId(('outputs' === side ? 8 : 0) + i)).join(', ') });
                    // Without code and icon the header is a bare bracket over the group
                    head.innerHTML = linkMark(this.linkViewSettings, group, linkName(this.linkViewSettings, side, groups[side], group));
                    this.chtabsTargets.forEach((box) => box.append(head.cloneNode(true)));
                });
            }
        }
    }

    /**
     * The Link matrix of each side from the groups being edited: a cell is lit while its row and column channel
     * share a group, with the group code, shape or colour as on the channel tabs. The rows and columns follow the
     * placement, and "stacked" turns each link group into a block with one header that names its members.
     */
    showLinkDialog() {
        const { colour, placement } = this.linkViewSettings;
        this.showLinkNames();
        for (const table of this.linkMatrixTargets) {
            const groups = this.linkDraft[table.dataset.side];
            this.placeLinkMatrix(table, groups, placement);
            for (const cell of table.querySelectorAll('.link-cell')) {
                const [row, column] = [Number(cell.dataset.row), Number(cell.dataset.column)];
                const group = row !== column && groups[row] === groups[column] ? groups[row] : 0;
                cell.classList.toggle('is-on', group > 0);
                cell.setAttribute('aria-pressed', String(group > 0));
                cell.dataset.group = colour && group > 0 ? String(group) : '';
                // Without code and shape a lit cell shows the dot of the routing matrix
                cell.innerHTML = linkMark(this.linkViewSettings, group, linkName(this.linkViewSettings, table.dataset.side, groups, group));
            }
        }
    }

    /** Orders the rows and columns of one Link matrix by the placement, and merges the headers of a group when stacked. */
    placeLinkMatrix(table, groups, placement) {
        const units = 'normal' === placement ? groups.map((_g, i) => [i]) : linkUnits(groups);
        const order = units.flat();
        const stacked = 'stacked' === placement;
        const base = 'inputs' === table.dataset.side ? 0 : 8;
        const head = table.tHead.rows[0];
        const body = table.tBodies[0];
        const byIndex = (cells) => order.map((i) => cells.find((c) => Number(c.dataset.index) === i));
        const columnHeads = [...head.cells].slice(1);
        const rowHeads = [...body.rows].map((row) => row.cells[0]);
        for (const heads of [columnHeads, rowHeads]) {
            // Every channel label back to its own header first, then a stacked group gathers them in its first one
            const labels = heads.flatMap((th) => [...th.querySelectorAll(':scope > .ch')]);
            for (const label of labels) {
                heads.find((th) => Number(th.dataset.index) === Number(label.dataset.channelIndexValue) - base).append(label);
            }
            for (const th of heads) {
                th.hidden = false;
                th.colSpan = 1;
                th.rowSpan = 1;
                th.classList.remove('is-merged');
            }
            for (const unit of stacked ? units.filter((u) => u.length > 1) : []) {
                const [first, ...rest] = unit.map((i) => heads.find((th) => Number(th.dataset.index) === i));
                first.classList.add('is-merged');
                first[columnHeads === heads ? 'colSpan' : 'rowSpan'] = unit.length;
                for (const th of rest) {
                    first.append(...th.querySelectorAll(':scope > .ch'));
                    th.hidden = true;
                }
            }
        }
        head.append(...byIndex(columnHeads));
        body.append(...byIndex([...body.rows]));
        // A gap before every group but the first, when stacked
        const starts = new Set(stacked ? units.slice(1).map((u) => u[0]) : []);
        for (const row of body.rows) {
            row.classList.toggle('is-unit-start', starts.has(Number(row.dataset.index)));
            const cells = byIndex([...row.cells].slice(1));
            row.append(...cells);
            cells.forEach((cell) => cell.classList.toggle('is-unit-start', starts.has(Number(cell.dataset.index))));
        }
        columnHeads.forEach((th) => th.classList.toggle('is-unit-start', starts.has(Number(th.dataset.index))));
    }

    /**
     * Input and output rows contain one name field per edited group, keyed by side and lowest channel. Fields are
     * only rebuilt when the groups change, so typing keeps its focus.
     */
    showLinkNames() {
        if (this.hasLinkNamesTarget && this.linkDraft) renderLinkNames(this.linkNamesTarget, this.linkNamesEmptyTarget,
            this.linkDraft, this.linkViewSettings, 'input->editor#renameLinkGroup', true);
    }

    renameLinkGroup(event) {
        const names = { ...this.linkViewSettings.names };
        const name = event.target.value.trim();
        if ('' === name) {
            delete names[event.target.dataset.key];
        } else {
            names[event.target.dataset.key] = name;
        }
        this.linkViewSettings.names = names;
        this.changeLinkView();
    }

    linkCell(event) {
        const { side, row, column } = event.currentTarget.dataset;
        this.linkDraft[side] = toggleLink(this.linkDraft[side], Number(row), Number(column));
        this.showLinkDialog();
    }

    /** The link display settings on every copy of their controls, on the Display Settings page and in the Link dialog. */
    showLinkViewSettings() {
        this.linkViewTargets.forEach((box) => {
            box.checked = Boolean(this.linkViewSettings[box.value]);
        });
        this.linkPlacementTargets.forEach((radio) => {
            radio.checked = radio.value === this.linkViewSettings.placement;
        });
    }

    linkView(event) {
        this.linkViewSettings[event.target.value] = event.target.checked;
        this.changeLinkView();
    }

    linkPlacement(event) {
        this.linkViewSettings.placement = event.target.value;
        this.changeLinkView();
    }

    changeLinkView() {
        setLinkView(this.linkViewSettings);
        // Back from the shared settings, which trim and drop what does not belong
        this.linkViewSettings = { ...getLinkView() };
        this.showLinkViewSettings();
        this.showState();
        if (this.linkDraft) {
            this.showLinkDialog();
        }
    }

    language(event) {
        document.cookie = languageCookie(event.target.value, 'https:' === window.location.protocol);
        window.location.reload();
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
            this.themeTarget.textContent = { auto: t('Auto'), light: t("Light"), dark: t("Dark") }[theme];
            this.themeTarget.title = t("Colour theme %theme%. Click to change.", { theme: this.themeTarget.textContent });
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
        // The simulated device has a button of its own, so its target never shows in the field
        if ('connect' === event.params.dialog && status.target && !status.demo) {
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
            this.linkDraft = groups;
            this.showLinkDialog();
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

    /** Switches the bridge to the simulated device, without a target typed into the field. */
    connectDemo() {
        this.targetTarget.value = '';
        this.switchTarget('demo');
    }

    /** Searches USB and the local networks and lists what it finds, one click connects. */
    async scan() {
        this.scanButtonTarget.disabled = true;
        this.scanStatusTarget.hidden = false;
        this.scanStatusTarget.textContent = t("Searching USB and the network…");
        this.foundTarget.replaceChildren();
        try {
            const result = await api('discover');
            const networks = result.networks.join(', ') || t("no network");
            this.scanStatusTarget.textContent = 0 === result.devices.length
                ? t("No DSP found on USB or on %networks%.", { networks })
                : t("Found on USB and %networks%.", { networks });
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
        button.textContent = device.connected ? t("%target% (connected)", { target: device.target }) : t("Connect %target%", { target: device.target });
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
            this.showError(t("Not connected to the device."));
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
        const warning = false !== info.stored && info.name ? ' '+t("It replaces \"%name%\".", { name: info.name }) : '';
        if (!this.requireState() || !window.confirm(t("Store the live settings as %preset% \"%name%\"?%warning%", { preset: presetLabel(number), name, warning }))) {
            return;
        }
        send([{ command: 'presetName', args: { name } }, { command: 'storePreset', args: { number } }], false);
        form.closest('dialog').close();
    }

    recall(event) {
        event.preventDefault();
        const number = Number(event.target.elements.number.value);
        if (this.requireState() && window.confirm(t("Recall %preset%? It replaces all live settings.", { preset: presetLabel(number) }))) {
            send([{ command: 'loadPreset', args: { number } }], false);
            event.target.closest('dialog').close();
        }
    }

    factory() {
        if (this.requireState() && window.confirm(t("Recall the factory default F00? It replaces all live settings."))) {
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
            commands.push(...linkCommands(state[side], this.linkDraft[side], base));
        }
        if (0 === commands.length) {
            event.target.closest('dialog').close();
            return;
        }
        const copies = commands.filter((c) => 'copyChannel' === c.command).length;
        if (copies > 0 && !window.confirm(t("Linking copies the settings of the first channel of each group to %count% channel(s). Their names stay. Go on?", { count: copies }))) {
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
            this.showError(t("Copy an input to another input, or an output to another output."));
            return;
        }
        if (!this.requireState() || !window.confirm(t("Copy every setting of %source% to %target%?", { source: name(source), target: name(target) }))) {
            return;
        }
        await send([{ command: 'copyChannel', args: { source, target } }], false);
        event.target.closest('dialog').close();
        this.refresh();
    }

    openFile() {
        this.fileTarget.click();
    }

    inspectFile() {
        const file = this.fileTarget.files[0];
        this.fileTarget.value = '';
        if (file) {
            this.element.querySelector('#dialog-preset').dispatchEvent(new CustomEvent('presetfile:open', { detail: { file } }));
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
                throw new Error(body.error ?? t("Download failed (%status%).", { status: response.status }));
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
