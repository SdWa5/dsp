import { Controller } from '@hotwired/stimulus';
import { api, getLabels, on, status, store, history } from '../lib/device.js';
import { presetSections } from '../lib/preset.js';
import { t } from '../lib/i18n.js';

/** A file preview owns its data independently of the live editor. Only Apply can upload it. */
export default class extends Controller {
    static targets = ['name', 'message', 'section', 'rows', 'apply'];

    connect() {
        this.request = 0;
        this.offs = [on('status', () => this.connection()), store.subscribe(() => this.connection())];
        this.connection();
    }

    disconnect() {
        ++this.request;
        this.offs.forEach((off) => off());
    }

    connection() {
        this.applyTarget.disabled = !this.file || this.busy || !status.connected || null === store.state;
    }

    async open(event) {
        const file = event.detail.file;
        const request = ++this.request;
        this.file = null;
        this.sections = [];
        this.sectionTarget.replaceChildren();
        this.rowsTarget.replaceChildren();
        this.nameTarget.textContent = file.name;
        this.messageTarget.textContent = t('Loading %file%…', { file: file.name });
        this.connection();
        if (!this.element.open) this.element.showModal();
        try {
            // Keep the original Blob. A vendor .prs must never undergo a text round trip.
            const parsed = await api('preset', file);
            if (request !== this.request) return;
            this.sections = presetSections(parsed.device, getLabels(), parsed.riders);
            this.file = file;
            for (const [index, section] of this.sections.entries()) {
                const option = document.createElement('option');
                option.value = index;
                option.textContent = section.label;
                this.sectionTarget.append(option);
            }
            this.messageTarget.textContent = '';
            this.select();
        } catch (error) {
            if (request === this.request) this.messageTarget.textContent = error.message;
        } finally {
            if (request === this.request) this.connection();
        }
    }

    select() {
        this.rowsTarget.replaceChildren();
        for (const [setting, value] of this.sections[Number(this.sectionTarget.value)]?.rows ?? []) {
            const row = document.createElement('tr');
            for (const text of [setting, value]) {
                const cell = document.createElement('td');
                cell.textContent = text;
                row.append(cell);
            }
            this.rowsTarget.append(row);
        }
    }

    async apply() {
        if (this.applyTarget.disabled || !window.confirm(t('Send every setting of %file% to the device?', { file: this.file.name }))) return;
        this.busy = true;
        this.connection();
        try {
            await history.run('Apply preset file', async () => { await api('upload', this.file); await api('refresh'); });
            this.element.close();
        } catch (error) {
            this.messageTarget.textContent = error.message;
        } finally {
            this.busy = false;
            this.connection();
        }
    }
}
