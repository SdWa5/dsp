import { Controller } from '@hotwired/stimulus';
import { isChanged } from '../lib/defaults.js';
import { getDefaults, getLabels, send, store } from '../lib/device.js';
import { getPath } from '../lib/store.js';
import { drawGrid, Plot, prepare, response } from '../lib/eq.js';
import * as S from '../lib/scale.js';

const COLORS = ['#ff5252', '#ffd740', '#e040fb', '#40c4ff', '#69f0ae', '#448aff', '#b0bec5', '#ffab40'];
const DEFAULT_SLOPE = 20; // LK -48, the editor's default
const DEFAULT_FREQS = { 5: [49, 101, 148, 200, 255], 7: [31, 71, 118, 161, 200, 240, 270] };

/**
 * PEQ editor of one channel: response curve with draggable band points and filter handles, band table,
 * parameter faders of the selected band, high and low pass. Values: path ("outputs.0"), channel (index).
 * Every field that differs from the factory default gets the class is-changed.
 */
export default class extends Controller {
    static values = { path: String, channel: Number };
    static targets = ['canvas', 'row', 'freq', 'q', 'gain', 'type', 'bypass', 'select', 'paramFreq', 'paramQ', 'paramGain',
        'paramFreqLabel', 'paramQLabel', 'paramGainLabel', 'filterFreq', 'filterFreqLabel', 'filterSlope', 'filterBypass',
        'eqBypass', 'phase'];

    connect() {
        this.selected = 0;
        this.drag = null;
        this.slopes = {};
        this.unsubscribe = store.subscribe(() => this.refresh());
        this.resize = new ResizeObserver(() => this.draw());
        this.resize.observe(this.canvasTarget);
        this.refresh();
    }

    disconnect() {
        this.unsubscribe?.();
        this.resize?.disconnect();
    }

    get channel() {
        return store.get(this.pathValue);
    }

    // --- writes ---------------------------------------------------------------------------------

    sendBand(band) {
        const b = this.channel.peq[band];
        send([{
            command: 'peq',
            args: { channel: this.channelValue, band, gainRaw: b.gain, freqIndex: b.freq, qIndex: b.q, type: b.type, bypass: b.bypass },
        }]);
        store.notify();
    }

    setBand(band, values) {
        for (const [key, value] of Object.entries(values)) {
            store.set(`${this.pathValue}.peq.${band}.${key}`, value);
        }
        this.sendBand(band);
    }

    sendFilter(kind) {
        const filter = this.channel[kind];
        send([{ command: 'hpf' === kind ? 'highPass' : 'lowPass', args: { channel: this.channelValue, freqIndex: filter.freq, slope: filter.slope } }]);
        store.notify();
    }

    // --- table and faders -----------------------------------------------------------------------

    select(event) {
        this.selected = Number(event.params.band);
        this.refresh();
    }

    edit(event) {
        const band = Number(event.params.band);
        const field = event.params.field;
        const text = event.target.value.trim().replace(',', '.');
        const number = parseFloat(text);
        if ('type' === field) {
            this.setBand(band, { type: Number(event.target.value) });
            return;
        }
        if (Number.isNaN(number)) {
            this.refresh();
            return;
        }
        const values = {
            freq: () => ({ freq: S.hzToIndex(/k/i.test(text) ? number * 1000 : number) }),
            q: () => ({ q: S.qToIndex(number) }),
            gain: () => ({ gain: S.dbToPeqGain(number) }),
        }[field]();
        this.selected = band;
        this.setBand(band, values);
    }

    /** Arrow keys in a table field step the raw value. */
    step(event) {
        if (!['ArrowUp', 'ArrowDown'].includes(event.key)) {
            return;
        }
        event.preventDefault();
        const band = Number(event.params.band);
        const field = event.params.field;
        const max = { freq: 300, q: 100, gain: 240 }[field];
        const value = this.channel.peq[band][field] + ('ArrowUp' === event.key ? 1 : -1);
        this.selected = band;
        this.setBand(band, { [field]: Math.max(0, Math.min(max, value)) });
    }

    toggleBypass(event) {
        const band = Number(event.params.band);
        this.setBand(band, { bypass: !this.channel.peq[band].bypass });
    }

    param(event) {
        const field = event.params.field;
        this.setBand(this.selected, { [field]: Number(event.target.value) });
    }

    filter(event) {
        const kind = event.params.kind;
        const filter = { ...this.channel[kind] };
        const field = event.params.field;
        if ('freq' === field) {
            filter.freq = Number(event.target.value);
        } else if ('slope' === field) {
            this.slopes[kind] = Number(event.target.value);
            filter.slope = filter.slope ? this.slopes[kind] : 0;
        } else {
            filter.slope = filter.slope ? 0 : (this.slopes[kind] ?? DEFAULT_SLOPE);
        }
        store.set(`${this.pathValue}.${kind}`, filter);
        this.sendFilter(kind);
    }

    eqBypass() {
        const on = !this.channel.eq_bypass;
        store.set(`${this.pathValue}.eq_bypass`, on);
        send([{ command: 'eqBypass', args: { channel: this.channelValue, on } }]);
        store.notify();
    }

    reset() {
        const defaults = DEFAULT_FREQS[this.channel.peq.length] ?? [];
        this.channel.peq.forEach((_band, i) => this.setBand(i, { gain: 120, q: 25, type: 0, bypass: false, freq: defaults[i] ?? 170 }));
    }

    togglePhase() {
        this.draw();
    }

    // --- curve ----------------------------------------------------------------------------------

    plot() {
        const canvas = this.canvasTarget;
        return new Plot(48, 14, canvas.clientWidth - 60, canvas.clientHeight - 36);
    }

    handles() {
        const plot = this.plot();
        const channel = this.channel;
        const handles = channel.peq.map((b, i) => ({ kind: 'band', i, x: plot.x(S.indexToHz(b.freq)), y: plot.y(S.peqGainToDb(b.gain)) }));
        for (const kind of ['hpf', 'lpf']) {
            if (channel[kind]) {
                handles.push({ kind, x: plot.x(S.indexToHz(channel[kind].freq)), y: plot.y(0) });
            }
        }
        return handles;
    }

    pointerdown(event) {
        if (!this.channel || this.element.classList.contains('is-offline')) {
            return;
        }
        const { offsetX: x, offsetY: y } = event;
        const hit = this.handles().find((h) => Math.abs(h.x - x) < 9 && Math.abs(h.y - y) < 9);
        if (!hit) {
            return;
        }
        this.drag = hit;
        if ('band' === hit.kind) {
            this.selected = hit.i;
        }
        this.canvasTarget.setPointerCapture(event.pointerId);
        this.refresh();
    }

    pointermove(event) {
        if (null === this.drag) {
            return;
        }
        const plot = this.plot();
        const freq = S.hzToIndex(Math.max(20, Math.min(20000, plot.f(event.offsetX))));
        if ('band' === this.drag.kind) {
            const gain = S.dbToPeqGain(Math.round(Math.max(-12, Math.min(12, plot.db(event.offsetY))) * 10) / 10);
            this.setBand(this.drag.i, { freq, gain });
        } else {
            store.set(`${this.pathValue}.${this.drag.kind}.freq`, freq);
            this.sendFilter(this.drag.kind);
        }
    }

    pointerup() {
        this.drag = null;
    }

    /** Mouse wheel over a band point changes its Q. */
    wheel(event) {
        const { offsetX: x, offsetY: y } = event;
        const hit = this.handles().find((h) => 'band' === h.kind && Math.abs(h.x - x) < 12 && Math.abs(h.y - y) < 12);
        if (!hit) {
            return;
        }
        event.preventDefault();
        const q = this.channel.peq[hit.i].q + (event.deltaY < 0 ? 1 : -1);
        this.selected = hit.i;
        this.setBand(hit.i, { q: Math.max(0, Math.min(100, q)) });
    }

    // --- display --------------------------------------------------------------------------------

    refresh() {
        const channel = this.channel;
        this.element.classList.toggle('is-offline', !channel);
        for (const node of this.element.querySelectorAll('input, select, button')) {
            node.disabled = !channel;
        }
        if (!channel) {
            this.draw();
            return;
        }
        this.selected = Math.min(this.selected, channel.peq.length - 1);
        const factory = getPath(getDefaults(), this.pathValue);
        const mark = (node, path) => node?.classList.toggle('is-changed', isChanged(channel, factory, path));
        const active = document.activeElement;
        channel.peq.forEach((b, i) => {
            const set = (targets, value) => {
                if (targets[i] && targets[i] !== active) {
                    targets[i].value = value;
                }
            };
            set(this.freqTargets, S.formatHz(S.indexToHz(b.freq)));
            set(this.qTargets, S.format('q', b.q));
            set(this.gainTargets, S.format('peq', b.gain));
            set(this.typeTargets, String(b.type));
            this.bypassTargets[i]?.classList.toggle('is-on', b.bypass);
            for (const field of ['freq', 'q', 'gain', 'type', 'bypass']) {
                mark(this[`${field}Targets`][i], `peq.${i}.${field}`);
            }
            this.selectTargets[i]?.classList.toggle('is-on', i === this.selected);
        });
        const band = channel.peq[this.selected];
        const params = [['Freq', band.freq, 'hz'], ['Q', band.q, 'q'], ['Gain', band.gain, 'peq']];
        for (const [name, value, fmt] of params) {
            if (this[`hasParam${name}Target`]) {
                this[`param${name}Target`].value = value;
                this[`param${name}LabelTarget`].textContent = S.format(fmt, value);
            }
        }
        this.filterFreqTargets.forEach((input, k) => {
            const kind = input.dataset.kind;
            const filter = channel[kind];
            input.value = filter.freq;
            this.filterFreqLabelTargets[k].textContent = S.format('hz', filter.freq);
            if (filter.slope) {
                this.slopes[kind] = filter.slope;
            }
            this.filterSlopeTargets[k].value = String(filter.slope || this.slopes[kind] || DEFAULT_SLOPE);
            this.filterBypassTargets[k].classList.toggle('is-on', 0 === filter.slope);
            mark(this.filterFreqLabelTargets[k], `${kind}.freq`);
            mark(this.filterSlopeTargets[k], `${kind}.slope`);
        });
        if (this.hasEqBypassTarget) {
            this.eqBypassTarget.classList.toggle('is-on', Boolean(channel.eq_bypass));
            mark(this.eqBypassTarget, 'eq_bypass');
        }
        this.draw();
    }

    draw() {
        const canvas = prepare(this.canvasTarget);
        if (null === canvas) {
            return;
        }
        const { g } = canvas;
        const plot = this.plot();
        drawGrid(g, plot, canvas.width, canvas.height);
        const channel = this.channel;
        if (!channel) {
            return;
        }
        const phase = this.hasPhaseTarget && this.phaseTarget.checked;
        const slopes = getLabels().SLOPES ?? [];
        g.strokeStyle = '#ffee58';
        g.lineWidth = 2;
        g.beginPath();
        const n = Math.max(200, Math.round(plot.width));
        for (let i = 0; i <= n; i += 1) {
            const f = 20 * 1000 ** (i / n);
            const r = response(channel, f, slopes);
            const value = Math.max(-18.5, Math.min(18.5, phase ? r.phase / 10 : r.db));
            const [x, y] = [plot.x(f), plot.y(value)];
            if (0 === i) {
                g.moveTo(x, y);
            } else {
                g.lineTo(x, y);
            }
        }
        g.stroke();
        g.lineWidth = 1;
        g.textBaseline = 'bottom';
        for (const h of this.handles()) {
            if ('band' === h.kind) {
                g.fillStyle = COLORS[h.i % COLORS.length];
                g.beginPath();
                g.arc(h.x, h.y, 6, 0, 2 * Math.PI);
                g.fill();
                if (h.i === this.selected) {
                    g.strokeStyle = '#fff';
                    g.lineWidth = 2;
                    g.stroke();
                    g.lineWidth = 1;
                }
                g.fillStyle = '#fff';
                g.fillText(String(h.i + 1), h.x, h.y - 9);
            } else {
                g.fillStyle = 'hpf' === h.kind ? '#ffffff' : '#c0ca33';
                g.fillRect(h.x - 5, h.y - 5, 10, 10);
                g.fillText(h.kind.toUpperCase(), h.x, h.y - 8);
            }
        }
        const b = channel.peq[this.selected];
        g.textAlign = 'left';
        g.textBaseline = 'top';
        g.fillStyle = '#ffee58';
        g.fillText(`Band ${this.selected + 1}: ${S.format('hz', b.freq)}  Q ${S.format('q', b.q)}  ${S.format('peq', b.gain)}`, plot.left + 6, plot.top + 4);
    }
}
