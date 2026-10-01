import { Controller } from '@hotwired/stimulus';
import { api, store } from '../lib/device.js';
import { channelLabel } from '../lib/channels.js';
import { drawGrid, Plot, prepare } from '../lib/eq.js';

const CURVES = [
    ['measured', '#9e9e9e', []],
    ['goal', '#ffffff', [6, 4]],
    ['eq', '#ffee58', []],
    ['after', '#69f0ae', []],
    ['verified', '#40c4ff', []],
];
const FIT_FIELDS = ['target', 'low', 'high', 'max_boost', 'max_cut', 'max_q', 'tolerance'];

const signed = (v) => `${v > 0 ? '+' : ''}${v.toFixed(1)}`;
const hz = (v) => (v >= 1000 ? `${(v / 1000).toFixed(2)} kHz` : `${v.toFixed(0)} Hz`);

/**
 * The auto EQ page. Every step is one API call to /api/autoeq and answers with the session, which this
 * controller only displays: setup, recordings, fit with its curves, apply, verify, revert, backups.
 */
export default class extends Controller {
    static targets = ['status', 'setup', 'devices', 'recorderNote', 'measure', 'summary', 'recordings', 'record', 'wav',
        'calibration', 'calibrationRemove', 'calibrationFile', 'fitCard', 'fitForm', 'fitButton', 'result', 'plot',
        'verifiedLegend', 'stats', 'bands', 'applyCard', 'applyNote', 'apply', 'verify', 'revert', 'backups', 'warnings'];

    connect() {
        this.session = null;
        this.recorder = true;
        this.busy = false;
        this.off = store.subscribe(() => this.renderLabels());
        this.resize = new ResizeObserver(() => this.draw());
        this.resize.observe(this.plotTarget);
        this.load();
    }

    disconnect() {
        this.off?.();
        this.resize?.disconnect();
        clearInterval(this.timer);
    }

    // --- steps ----------------------------------------------------------------------------------

    async load() {
        await this.run(null, async () => {
            const data = await api('autoeq', undefined, 'GET');
            this.recorder = data.recorder;
            this.devices = data.devices;
            this.defaultDevice = data.device;
            this.backupList = data.backups;
            this.show(data.session);
        });
    }

    async start(event) {
        event.preventDefault();
        const form = event.target;
        const outputs = [...form.elements.outputs].filter((c) => c.checked).map((c) => Number(c.value));
        const body = { outputs, device: form.elements.device.value };
        for (const name of ['bands', 'mic_channel', 'seconds', 'settle']) {
            body[name] = Number(form.elements[name].value);
        }
        if (await this.step('Taking the backup…', 'autoeq/start', body)) {
            this.load();
        }
    }

    async record() {
        await this.recording(false);
    }

    async verify() {
        await this.recording(true);
    }

    async recording(verify) {
        const session = this.session;
        let left = Math.ceil(session.settle + session.seconds);
        const what = verify ? 'Verify recording with the bands on' : `Recording position ${this.positions().length + 1}`;
        const tick = () => this.say(`${what}: pink noise is playing, ${left} s left…`);
        tick();
        this.timer = setInterval(() => { left = Math.max(0, left - 1); tick(); }, 1000);
        let done = false;
        try {
            done = await this.step(null, 'autoeq/record', verify ? { verify: true } : {});
        } finally {
            clearInterval(this.timer);
        }
        if (done) {
            this.say('');
            if (this.session?.fit) {
                this.fit();
            }
        }
    }

    chooseWav() {
        this.wavTarget.click();
    }

    async uploadWav() {
        const files = [...this.wavTarget.files];
        this.wavTarget.value = '';
        for (const file of files) {
            const verify = Boolean(this.session?.applied) && window.confirm(`Is ${file.name} a verify recording, made with the bands on?`);
            await this.step(`Analysing ${file.name}…`, `autoeq/upload?name=${encodeURIComponent(file.name.replace(/\.wav$/i, ''))}${verify ? '&verify=1' : ''}`, file);
        }
    }

    remove(event) {
        const index = Number(event.params.index);
        this.step('Removing…', 'autoeq/remove', { index });
    }

    chooseCalibration() {
        this.calibrationFileTarget.click();
    }

    async uploadCalibration() {
        const file = this.calibrationFileTarget.files[0];
        this.calibrationFileTarget.value = '';
        if (file) {
            await this.step(`Loading ${file.name}…`, 'autoeq/calibration', file);
        }
    }

    removeCalibration() {
        this.step('Removing the calibration…', 'autoeq/calibration', '');
    }

    async fit(event = null) {
        event?.preventDefault();
        const form = this.fitFormTarget;
        const body = {};
        for (const name of FIT_FIELDS) {
            body[name] = 'target' === name ? form.elements[name].value : Number(form.elements[name].value);
        }
        await this.step('Fitting…', 'autoeq/fit', body);
    }

    apply() {
        const bands = this.session?.fit?.result?.bands ?? [];
        const outputs = this.outputNames();
        if (window.confirm(`Write ${bands.length} band${1 === bands.length ? '' : 's'} to ${outputs}? The backup ${this.session.backup} can undo it.`)) {
            this.step('Writing the bands…', 'autoeq/apply');
        }
    }

    async revert(event) {
        const backup = event.params.backup ?? this.session?.backup;
        if (backup && window.confirm(`Restore the PEQ from ${backup}?`)) {
            if (await this.step('Restoring the backup…', 'autoeq/revert', event.params.backup ? { backup } : {})) {
                this.say(`Restored from ${backup}.`);
            }
        }
    }

    async discard() {
        if (window.confirm('End the session? Its recordings are deleted, the backups stay.')) {
            this.step('Ending the session…', 'autoeq/discard');
        }
    }

    /** One API call that answers with the session. True when it worked. */
    step(message, path, body = {}) {
        return this.run(message, async () => {
            const data = await api(path, body);
            if (undefined !== data.warnings) {
                this.warn(data.warnings);
            }
            this.show(data.session);
        });
    }

    async run(message, work) {
        if (this.busy) {
            return false;
        }
        this.busy = true;
        this.element.classList.add('is-busy');
        if (null !== message) {
            this.say(message);
        }
        try {
            await work();
            if (null !== message) {
                this.say('');
            }
            return true;
        } catch (error) {
            this.say(error.message, true);
            return false;
        } finally {
            this.busy = false;
            this.element.classList.remove('is-busy');
            this.render();
        }
    }

    /** Warnings about the chosen outputs: they stay until the next start or recording answers. */
    warn(warnings) {
        this.warningsTarget.replaceChildren(...warnings.map((text) => Object.assign(document.createElement('li'), { textContent: text })));
    }

    say(message, error = false) {
        this.statusTarget.textContent = message;
        this.statusTarget.classList.toggle('is-error', error);
        this.statusTarget.hidden = '' === message;
    }

    // --- display --------------------------------------------------------------------------------

    positions() {
        return (this.session?.recordings ?? []).filter((r) => !r.verify);
    }

    outputNames() {
        return (this.session?.outputs ?? []).map((o) => channelLabel(o, store.state)).join(', ');
    }

    show(session) {
        this.session = session;
        if (session?.fit?.settings) {
            for (const name of FIT_FIELDS) {
                this.fitFormTarget.elements[name].value = session.fit.settings[name];
            }
        }
        this.render();
    }

    render() {
        const session = this.session;
        const active = null !== session;
        this.setupTarget.hidden = active;
        this.measureTarget.hidden = !active;
        this.fitCardTarget.hidden = !active || 0 === this.positions().length;
        const fit = session?.fit?.result ?? null;
        this.applyCardTarget.hidden = !active || null === fit;
        this.renderDevices();
        this.renderBackups();
        if (!active) {
            return;
        }
        this.recordTarget.textContent = `Record position ${this.positions().length + 1}`;
        this.recordTarget.disabled = this.busy || !this.recorder;
        this.verifyTarget.hidden = !session.applied;
        this.verifyTarget.disabled = this.busy || !this.recorder;
        this.calibrationTarget.textContent = session.calibration ?? 'none';
        this.calibrationRemoveTarget.hidden = null === session.calibration;
        this.recordingsTarget.replaceChildren(...session.recordings.map((r, i) => this.recordingItem(r, i)));
        this.renderLabels();
        if (null === fit) {
            return;
        }
        this.resultTarget.hidden = false;
        this.verifiedLegendTarget.hidden = !fit.verified;
        this.statsTarget.textContent = `Deviation from the target ${fit.rms_before} dB RMS (peak ${fit.peak_before} dB) before, `
            + `${fit.rms_after} dB RMS (peak ${fit.peak_after} dB) predicted after.`;
        this.bandsTarget.replaceChildren(...(0 === fit.bands.length
            ? [this.row(['–', 'Already within the tolerance, no bands needed.', '', ''])]
            : fit.bands.map((b, i) => this.row([String(i + 1), hz(b.hz), `${signed(b.db)} dB`, b.q_value.toFixed(2)]))));
        this.applyTarget.disabled = this.busy || 0 === fit.bands.length;
        this.draw();
    }

    renderLabels() {
        const session = this.session;
        if (!session || !this.hasSummaryTarget) {
            return;
        }
        this.summaryTarget.textContent = `${this.outputNames()} · bands 1 to ${session.bands} · mic on input ${session.mic_channel} of ${session.device}`;
        this.applyNoteTarget.textContent = session.applied
            ? `Applied to ${this.outputNames()}. Record a verify position with the bands on to compare the result with the prediction.`
            : `Writes the fitted bands to ${this.outputNames()}. The other bands stay as they are. The backup ${session.backup} can undo it.`;
    }

    renderDevices() {
        this.recorderNoteTarget.hidden = this.recorder;
        const devices = this.devices ?? [];
        if (devices.length > 0 && this.devicesTarget.options.length !== devices.length) {
            // The first fill selects the configured device (TRACKDSP_CAPTURE_DEVICE), later ones keep the choice
            const selected = this.devicesFilled ? this.devicesTarget.value : this.defaultDevice;
            this.devicesFilled = true;
            this.devicesTarget.replaceChildren(...devices.map((d) => new Option(`${d.name} (${d.id})`, d.id)));
            if (devices.some((d) => d.id === selected)) {
                this.devicesTarget.value = selected;
            }
        }
    }

    renderBackups() {
        const backups = this.backupList ?? [];
        this.backupsTarget.replaceChildren(...(0 === backups.length
            ? [Object.assign(document.createElement('li'), { textContent: 'No backups yet.' })]
            : backups.map((b) => this.backupItem(b))));
    }

    recordingItem(recording, index) {
        const item = document.createElement('li');
        const name = document.createElement('span');
        name.textContent = recording.name;
        item.append(name);
        if (recording.verify) {
            const badge = document.createElement('span');
            badge.className = 'badge';
            badge.textContent = 'verify';
            item.append(' ', badge);
        }
        item.append(' ', this.button('Remove', 'autoeq#remove', { index }));
        return item;
    }

    backupItem(backup) {
        const item = document.createElement('li');
        const time = new Date(backup.time * 1000).toLocaleString();
        const outputs = backup.outputs.map((o) => channelLabel(o, store.state)).join(', ') || 'all outputs';
        item.append(`${time} · ${outputs} `, this.button('Revert', 'autoeq#revert', { backup: backup.file }));
        item.title = backup.file;
        return item;
    }

    button(text, action, params) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'toggle';
        button.textContent = text;
        button.dataset.action = action;
        for (const [key, value] of Object.entries(params)) {
            button.dataset[`autoeq${key[0].toUpperCase()}${key.slice(1)}Param`] = String(value);
        }
        button.disabled = this.busy;
        return button;
    }

    row(cells) {
        const row = document.createElement('tr');
        row.append(...cells.map((text) => Object.assign(document.createElement('td'), { textContent: text })));
        return row;
    }

    draw() {
        const fit = this.session?.fit?.result;
        const canvas = prepare(this.plotTarget);
        if (!fit || null === canvas) {
            return;
        }
        const { g, width, height } = canvas;
        const plot = new Plot(48, 14, width - 60, height - 36);
        drawGrid(g, plot, width, height);
        const { low, high } = this.session.fit.settings;
        g.fillStyle = '#0008';
        g.fillRect(plot.left, plot.top, plot.x(low) - plot.left, plot.height);
        g.fillRect(plot.x(high), plot.top, plot.left + plot.width - plot.x(high), plot.height);
        for (const [key, color, dash] of CURVES) {
            const values = fit[key];
            if (!values) {
                continue;
            }
            g.strokeStyle = color;
            g.lineWidth = 'measured' === key ? 1.5 : 2;
            g.setLineDash(dash);
            g.beginPath();
            fit.freqs.forEach((f, i) => {
                const [x, y] = [plot.x(f), plot.y(Math.max(-18.5, Math.min(18.5, values[i])))];
                if (0 === i) {
                    g.moveTo(x, y);
                } else {
                    g.lineTo(x, y);
                }
            });
            g.stroke();
        }
        g.setLineDash([]);
        g.fillStyle = '#ffee58';
        g.textAlign = 'center';
        g.textBaseline = 'bottom';
        fit.bands.forEach((b, i) => {
            const x = plot.x(b.hz);
            const y = plot.y(Math.max(-18, Math.min(18, b.db)));
            g.beginPath();
            g.arc(x, y, 5, 0, 2 * Math.PI);
            g.fill();
            g.fillText(String(i + 1), x, y - 7);
        });
    }
}
