import { Controller } from '@hotwired/stimulus';
import { api, listen, on, send, status, store } from '../lib/device.js';
import { linkCommands, linkGroups, linkedMembers } from '../lib/link.js';
import { presetLabel } from '../lib/scale.js';

/**
 * The editor frame: tabs, connection state, menus and the dialogs that do more than set one value
 * (connect, presets, link, copy, preset files).
 */
export default class extends Controller {
    static targets = ['tab', 'page', 'online', 'banner', 'number', 'error', 'dialog', 'status', 'target', 'slots',
        'storeName', 'linkGroup', 'file', 'scanButton', 'scanStatus', 'found'];

    connect() {
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
        this.show(event.params.page);
        window.history.replaceState(null, '', `#${event.params.page}`);
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
        const online = status.bridge && status.connected;
        this.onlineTarget.textContent = online ? 'Online' : 'Offline';
        this.onlineTarget.classList.toggle('is-on', online);
        let banner = '';
        if (!status.bridge) {
            banner = status.error || 'The bridge is not running. Start it with bin/console trackdsp:bridge.';
        } else if (!status.connected) {
            banner = `Not connected${status.target ? ` to ${status.target}` : ''}.${status.error ? ` ${status.error}` : ''}`;
        }
        this.bannerTarget.textContent = banner;
        this.bannerTarget.hidden = '' === banner;
        if (this.hasStatusTarget) {
            const info = status.info ? ` (${status.info})` : '';
            this.statusTarget.textContent = online ? `Connected to ${status.target}${info}.` : banner;
        }
    }

    showState() {
        const state = store.state;
        this.numberTarget.textContent = presetLabel(state?.active_preset);
        for (const tab of this.tabTargets) {
            if (!tab.dataset.side) {
                continue;
            }
            const index = Number(tab.dataset.index);
            const members = state ? linkedMembers(state[tab.dataset.side], index) : [index];
            // A linked channel shows its group's first channel, as in the vendor editor: "2=1"
            tab.textContent = members[0] !== index ? `${index + 1}=${members[0] + 1}` : tab.dataset.label;
        }
    }

    showError(message) {
        this.errorTarget.textContent = message;
        clearTimeout(this.errorTimer);
        this.errorTimer = setTimeout(() => { this.errorTarget.textContent = ''; }, 8000);
    }

    // --- dialogs --------------------------------------------------------------------------------

    open(event) {
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
            for (const select of this.linkGroupTargets) {
                select.value = String(groups[select.dataset.side][Number(select.dataset.index)]);
            }
        }
        dialog.showModal();
    }

    close(event) {
        event.target.closest('dialog').close();
    }

    fillSlots(select, state) {
        const first = Number(select.dataset.first);
        const names = state?.preset_names ?? [];
        const selected = select.value || String(Math.max(first, state?.active_preset ?? first));
        select.replaceChildren(...Array.from({ length: 21 - first }, (_v, k) => {
            const number = first + k;
            const name = (names[number] ?? '').trim();
            return new Option(`${presetLabel(number)}  ${name || '(empty)'}`, String(number));
        }));
        select.value = selected;
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
        const current = (store.state?.preset_names?.[number] ?? '').trim();
        const warning = current ? ` It replaces "${current}".` : '';
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
            const groups = this.linkGroupTargets.filter((s) => s.dataset.side === side).map((s) => Number(s.value));
            commands.push(...linkCommands(state[side], groups, base));
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
        const name = (c) => (c < 8 ? `In${c + 1}` : `Out${c - 7}`);
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
            await api('upload', await file.text());
            this.refresh();
        } catch (error) {
            this.showError(error.message);
        }
    }

    saveFile() {
        window.location.assign('/api/download');
    }
}
