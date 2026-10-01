import { t } from '../lib/i18n.js';
import { Controller } from '@hotwired/stimulus';
import { api, on, status, store, updateStatus } from '../lib/device.js';
import { channelId, channelLabel } from '../lib/channels.js';

const STATES = {
    waiting: 'Waiting for the device',
    paused: 'Paused after a manual change',
    silent: 'Silent, holding',
    steady: 'On target',
    riding: 'Riding',
    limit: 'At its limit',
};
// The bridge refuses a highest gain above 0 dB unless boosts are allowed (RiderSettings)
const MAX_GAIN = 12;
const FIELDS = ['target', 'min', 'max', 'slew', 'average', 'silence', 'deadband', 'hold'];

/**
 * The gain riders of the inputs, which run in the bridge: a Rider button and a status line per input,
 * and the settings dialog. Settings come with the bridge status, what they do right now with the meters.
 * A rider on a linked input rides its link group as one. The members show which rider rides them, and a second
 * rider in the same group stands by.
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
            button.textContent = running ? t("Rider on") : t('Rider');
            button.disabled = !online;
        }
        // Input => the rider that rides it along with its own input
        const ridden = new Map();
        for (const r of this.live.values()) {
            (r.members ?? []).filter((m) => m !== r.channel).forEach((m) => ridden.set(m, r.channel));
        }
        for (const info of this.infoTargets) {
            const channel = Number(info.dataset.channel);
            const rider = settings.get(channel);
            const now = this.live.get(channel);
            const lead = 'linked' === now?.status ? now.lead : ridden.get(channel);
            info.hidden = !rider && undefined === lead;
            info.dataset.state = now?.status ?? (undefined === lead ? '' : 'linked');
            if (undefined !== lead && null !== lead) {
                info.textContent = t('Ridden with %channel%', { channel: channelId(lead) });
                info.title = t('A rider on a linked input rides the whole link group with one gain.');
            } else if (rider) {
                const state = t(STATES[now?.status] ?? 'Starting');
                const gain = null === (now?.gain ?? null) ? '' : ` ${now.gain > 0 ? '+' : ''}${now.gain.toFixed(1)} dB`;
                const group = (now?.members ?? []).length > 1 ? ` · ${now.members.map((m) => channelId(m)).join('+')}` : '';
                info.textContent = `${state}${gain}${group}`;
                info.title = t("Target %target% dB, gain %min%..%max% dB, %slew% dB/s", { target: rider.target, min: rider.min, max: rider.max, slew: rider.slew })
                    + (null === (now?.average ?? null) ? '' : t(", level %level% dB", { level: now.average.toFixed(1) }));
            }
        }
    }

    renderTitle() {
        if (this.hasTitleTarget) {
            this.titleTarget.textContent = t("Gain rider · %channel%", { channel: channelLabel(this.channel, store.state) });
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
        this.boost();
        this.stopTarget.hidden = !rider;
        this.errorTarget.textContent = '';
        this.submitTarget.textContent = rider ? t("Update rider") : t("Start rider");
        this.renderTitle();
        this.dialogTarget.showModal();
    }

    close() {
        this.dialogTarget.close();
    }

    /** Highest gain follows "Allow a highest gain above 0 dB": up to +12 dB with it, 0 dB without. */
    boost() {
        const { boost, max } = this.formTarget.elements;
        const ceiling = boost.checked ? MAX_GAIN : 0;
        max.max = String(ceiling);
        if (Number(max.value) > ceiling) {
            max.value = String(ceiling);
        }
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
