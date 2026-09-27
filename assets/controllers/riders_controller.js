import { Controller } from '@hotwired/stimulus';
import { api, on, status, store, updateStatus } from '../lib/device.js';
import { channelLabel } from '../lib/channels.js';

const STATES = {
    waiting: 'Waiting for the device',
    paused: 'Paused after a manual change',
    silent: 'Silent, holding',
    steady: 'On target',
    riding: 'Riding',
    limit: 'At its limit',
};
const FIELDS = ['target', 'min', 'max', 'slew', 'average', 'silence', 'deadband', 'hold'];

/**
 * The gain riders of the inputs, which run in the bridge: a Rider button and a status line per input,
 * and the settings dialog. Settings come with the bridge status, what they do right now with the meters.
 */
export default class extends Controller {
    static targets = ['button', 'info', 'dialog', 'form', 'title', 'stop', 'submit', 'error'];
    static values = { defaults: Object };

    connect() {
        this.live = new Map();
        this.channel = 0;
        this.offs = [
            on('status', () => this.render()),
            on('riders', (riders) => {
                this.live = new Map(riders.map((r) => [r.channel, r]));
                this.render();
            }),
            store.subscribe(() => this.renderTitle()),
        ];
        this.render();
    }

    disconnect() {
        this.offs.forEach((off) => off());
    }

    get settings() {
        return new Map((status.riders ?? []).map((r) => [r.channel, r]));
    }

    render() {
        const settings = this.settings;
        const online = status.bridge && status.connected;
        for (const button of this.buttonTargets) {
            const channel = Number(button.dataset.channel);
            const running = settings.has(channel);
            button.classList.toggle('is-on', running);
            button.setAttribute('aria-pressed', running ? 'true' : 'false');
            button.textContent = running ? 'Rider on' : 'Rider';
            button.disabled = !online;
        }
        for (const info of this.infoTargets) {
            const channel = Number(info.dataset.channel);
            const rider = settings.get(channel);
            const now = this.live.get(channel);
            info.hidden = !rider;
            info.dataset.state = now?.status ?? '';
            if (rider) {
                const state = STATES[now?.status] ?? 'Starting';
                const gain = null === (now?.gain ?? null) ? '' : ` ${now.gain > 0 ? '+' : ''}${now.gain.toFixed(1)} dB`;
                info.textContent = `${state}${gain}`;
                info.title = `Target ${rider.target} dB, gain ${rider.min}..${rider.max} dB, ${rider.slew} dB/s`
                    + (null === (now?.average ?? null) ? '' : `, level ${now.average.toFixed(1)} dB`);
            }
        }
    }

    renderTitle() {
        if (this.hasTitleTarget) {
            this.titleTarget.textContent = `Gain rider · ${channelLabel(this.channel, store.state)}`;
        }
    }

    open(event) {
        this.channel = Number(event.params.channel);
        const rider = this.settings.get(this.channel);
        const values = rider ?? this.defaultsValue;
        const form = this.formTarget;
        for (const field of FIELDS) {
            form.elements[field].value = values[field];
        }
        form.elements.boost.checked = Boolean(values.boost);
        this.stopTarget.hidden = !rider;
        this.errorTarget.textContent = '';
        this.submitTarget.textContent = rider ? 'Update rider' : 'Start rider';
        this.renderTitle();
        this.dialogTarget.showModal();
    }

    close() {
        this.dialogTarget.close();
    }

    async save(event) {
        event.preventDefault();
        const form = this.formTarget;
        const settings = { boost: form.elements.boost.checked };
        for (const field of FIELDS) {
            settings[field] = Number(form.elements[field].value);
        }
        await this.call('ride', { channel: this.channel, settings });
    }

    async stop() {
        await this.call('unride', { channel: this.channel });
    }

    async call(path, body) {
        try {
            updateStatus(await api(path, body));
            this.close();
        } catch (error) {
            this.errorTarget.textContent = error.message;
        }
    }
}
