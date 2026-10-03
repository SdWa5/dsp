import { t } from '../lib/i18n.js';
import { Controller } from '@hotwired/stimulus';
import { isChanged } from '../lib/defaults.js';
import { getDefaults, getLabels, send, store } from '../lib/device.js';
import { linkedMembers } from '../lib/link.js';
import { paintRange } from '../lib/range.js';
import { getPath } from '../lib/store.js';
import { bandBypassed, bandIdle, curveRuns, filterIdle, drawGrid, eqView, loadEqView, plotFor, prepare, response, saveEqView, singleFilter } from '../lib/eq.js';
import * as S from '../lib/scale.js';

const COLORS = ['#ff5252', '#ffd740', '#e040fb', '#40c4ff', '#69f0ae', '#448aff', '#b0bec5', '#ffab40'];
const CURVE = { mag: { color: '#ffee58', width: 2 }, phase: { color: '#4dd0e1', width: 1.5 } };
// The filter being edited, drawn alone behind the channel curve, and the points of the filters not pointed at
const SINGLE = { color: '#9e9e9e', fill: 'rgba(158, 158, 158, 0.25)' };
const DIMMED = '#6b6b6b';
const DEFAULT_SLOPE = 20; // LK -48, the editor's default
const DEFAULT_FREQS = { 5: [49, 101, 148, 200, 255], 7: [31, 71, 118, 161, 200, 240, 270] };

/**
 * PEQ editor of one channel: response curve with draggable band points and filter handles, band table,
 * parameter faders of the selected band, high and low pass. Values: path ("outputs.0"), channel (index).
 * Every field that differs from the factory default gets the class is-changed. A write to a linked input or
 * output goes to every channel of its link group, as the Gain page's widgets do (bound_controller.js).
 * Mag and Phase show the magnitude, the phase or both, saved per channel. The phase reads in degrees on the axis.
 * While a band, the HPF or the LPF is being edited, by dragging its point or with focus on one of its controls, its
 * own curve as it would look alone is drawn in grey behind the channel curve, with the area to the 0 line filled
 * for the magnitude, or for the phase while it shows alone. Pointing at a filter's point, its table row or its faders
 * does the same and greys out the points and controls of every other filter.
 * The knobs of the selected band's faders turn red while the band does nothing (bypassed, or a Peak or shelf at
 * 0 dB), a filter's frequency knob while its Bypass is on.
 */
export default class extends Controller {
    static values = { path: String, channel: Number };
    static targets = ['canvas', 'row', 'freq', 'q', 'gain', 'type', 'bypass', 'select', 'paramFreq', 'paramQ', 'paramGain',
        'paramFreqLabel', 'paramQLabel', 'paramGainLabel', 'filterFreq', 'filterFreqLabel', 'filterSlope', 'filterBypass',
        'eqBypass', 'mag', 'phase'];

    connect() {
        this.selected = 0;
        this.drag = null;
        this.focused = null;
        this.hovered = null;
        this.pressed = null;
        this.slopes = {};
        const view = loadEqView(this.pathValue);
        if (this.hasMagTarget && this.hasPhaseTarget) {
            this.magTarget.checked = view.mag;
            this.phaseTarget.checked = view.phase;
        }
        this.unsubscribe = store.subscribe(() => this.refresh());
        // A press anywhere else lets go of the filter a press here held, which a touch needs (see press())
        this.outside = (event) => {
            if (!this.element.contains(event.target) && null !== this.pressed) {
                this.pressed = null;
                this.draw();
            }
        };
        document.addEventListener('pointerdown', this.outside);
        this.resize = new ResizeObserver(() => this.draw());
        this.resize.observe(this.canvasTarget);
        this.refresh();
    }

    disconnect() {
        this.unsubscribe?.();
        document.removeEventListener('pointerdown', this.outside);
        this.resize?.disconnect();
    }

    get channel() {
        return store.get(this.pathValue);
    }

    // --- writes ---------------------------------------------------------------------------------

    /** The channels a write goes to: {path, channel} of this one and of the channels linked with it. */
    members() {
        const [side, index] = this.pathValue.split('.');
        if (!['inputs', 'outputs'].includes(side)) {
            return [{ path: this.pathValue, channel: this.channelValue }];
        }
        const base = 'outputs' === side ? 8 : 0;
        return linkedMembers(store.get(side) ?? [], Number(index)).map((i) => ({ path: `${side}.${i}`, channel: base + i }));
    }

    /** Sends band `band` of this channel to every member, with its values copied to the linked channels. */
    sendBand(band) {
        const b = this.channel.peq[band];
        const commands = this.members().map(({ path, channel }) => {
            store.set(`${path}.peq.${band}`, { ...b });
            return { command: 'peq', args: { channel, band, gainRaw: b.gain, freqIndex: b.freq, qIndex: b.q, type: b.type, bypass: b.bypass } };
        });
        send(commands);
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
        const commands = this.members().map(({ path, channel }) => {
            store.set(`${path}.${kind}`, { ...filter });
            return { command: 'hpf' === kind ? 'highPass' : 'lowPass', args: { channel, freqIndex: filter.freq, slope: filter.slope } };
        });
        send(commands);
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
        paintRange(event.target);
        this.setBand(this.selected, { [field]: Number(event.target.value) });
    }

    filter(event) {
        const kind = event.params.kind;
        const filter = { ...this.channel[kind] };
        const field = event.params.field;
        if ('freq' === field) {
            paintRange(event.target);
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
        const commands = this.members().map(({ path, channel }) => {
            store.set(`${path}.eq_bypass`, on);
            return { command: 'eqBypass', args: { channel, on } };
        });
        send(commands);
        store.notify();
    }

    reset() {
        const defaults = DEFAULT_FREQS[this.channel.peq.length] ?? [];
        this.channel.peq.forEach((_band, i) => this.setBand(i, { gain: 120, q: 25, type: 0, bypass: false, freq: defaults[i] ?? 170 }));
    }

    toggleView() {
        saveEqView(this.pathValue, this.view.mag, this.view.phase);
        this.draw();
    }

    /** The curves the Mag and Phase switches show, the magnitude alone without them. */
    get view() {
        return this.hasMagTarget && this.hasPhaseTarget ? eqView(this.magTarget.checked, this.phaseTarget.checked) : eqView();
    }

    // --- curve ----------------------------------------------------------------------------------

    plot() {
        const canvas = this.canvasTarget;
        return plotFor(this.view, canvas.clientWidth, canvas.clientHeight);
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
            const { offsetX: x, offsetY: y } = event;
            const hit = this.channel ? this.handles().find((h) => Math.abs(h.x - x) < 9 && Math.abs(h.y - y) < 9) : undefined;
            this.hover(hit ? ('band' === hit.kind ? { kind: 'band', i: hit.i } : { kind: hit.kind }) : null);
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
        const dragged = null !== this.drag;
        this.drag = null;
        if (dragged) {
            this.draw();
        }
    }

    /**
     * A control of a band, of the selected band or of a filter takes focus: that filter is being edited. A field of
     * a band's row also selects that band, as its number button does.
     */
    focus(event) {
        const node = event.target;
        const params = node.dataset;
        if (undefined !== params.peqBandParam) {
            this.focused = { kind: 'band', i: Number(params.peqBandParam) };
            if (this.channel && this.focused.i !== this.selected) {
                this.selected = this.focused.i;
                this.refresh();
                return;
            }
        } else if (undefined !== params.peqKindParam) {
            this.focused = { kind: params.peqKindParam };
        } else if (/^param(Freq|Q|Gain)$/.test(params.peqTarget ?? '')) {
            this.focused = { kind: 'band', selected: true };
        } else {
            this.focused = null;
        }
        this.draw();
    }

    blur() {
        this.focused = null;
        this.draw();
    }

    /** The filter a node belongs to: a band's row, the selected band's faders or a filter's group, else null. */
    filterAt(node) {
        const row = node.closest('tr[data-peq-target=row]');
        const filter = node.closest('.group--filter');
        if (row) {
            return { kind: 'band', i: this.rowTargets.indexOf(row) };
        }
        if (filter) {
            return { kind: filter.querySelector('[data-kind]')?.dataset.kind };
        }

        return node.closest('.group--params') ? { kind: 'band', selected: true } : null;
    }

    /** The pointer over a band's row, the selected band's faders or a filter's group points at that filter. */
    hoverControl(event) {
        if (event.target !== this.canvasTarget) {
            this.hover(this.filterAt(event.target));
        }
    }

    /**
     * A press on a filter's control or point holds that filter as edited until a press lands elsewhere. Focus does
     * that for a mouse, but a touch neither focuses a range input nor stays over it once lifted, so without this the
     * grey curve would go the moment the finger does.
     */
    press(event) {
        const which = event.target === this.canvasTarget ? this.resolve(this.drag) : this.filterAt(event.target);
        if (JSON.stringify(which) !== JSON.stringify(this.pressed)) {
            this.pressed = which;
            this.draw();
        }
    }

    unhover() {
        this.hover(null);
    }

    hover(which) {
        if (JSON.stringify(which) === JSON.stringify(this.hovered)) {
            return;
        }
        this.hovered = which;
        this.draw();
    }

    /** {kind, i} with a selected band resolved to its index. */
    resolve(which) {
        if (null === which) {
            return null;
        }

        return 'band' === which.kind ? { kind: 'band', i: which.selected ? this.selected : which.i } : { kind: which.kind };
    }

    /** Greys out the rows, faders and filter groups of every filter but the pointed one, null clears it. */
    paintPointed(pointed) {
        const other = (which) => null !== pointed && (which.kind !== pointed.kind || which.i !== pointed.i);
        this.rowTargets.forEach((row, i) => row.classList.toggle('is-dimmed', other({ kind: 'band', i })));
        this.element.querySelector('.group--params')?.classList.toggle('is-dimmed', other({ kind: 'band', i: this.selected }));
        for (const group of this.element.querySelectorAll('.group--filter')) {
            group.classList.toggle('is-dimmed', other({ kind: group.querySelector('[data-kind]')?.dataset.kind }));
        }
    }

    /**
     * The filter being edited: the one dragged, else the one pointed at, else the one whose control has focus, else
     * the one pressed last.
     */
    get editing() {
        return this.resolve(this.drag ?? this.hovered ?? this.focused ?? this.pressed);
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
            const bypass = this.bypassTargets[i];
            if (bypass) {
                const { on, byEq } = bandBypassed(channel, i);
                bypass.classList.toggle('is-on', on);
                bypass.setAttribute('aria-pressed', on ? 'true' : 'false');
                bypass.title = byEq ? t('Bypassed by EQ Bypass') : '';
            }
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
                paintRange(this[`param${name}Target`]);
                this[`param${name}LabelTarget`].textContent = S.format(fmt, value);
            }
        }
        const idle = bandIdle(channel, this.selected);
        for (const name of ['Freq', 'Q', 'Gain']) {
            if (this[`hasParam${name}Target`]) {
                this.paintIdle(this[`param${name}Target`], null === idle ? '' : 'bypass' === idle ? t('Band bypassed') : t('Band at 0 dB: a Peak or shelf does nothing'));
            }
        }
        this.filterFreqTargets.forEach((input, k) => {
            const kind = input.dataset.kind;
            const filter = channel[kind];
            input.value = filter.freq;
            paintRange(input);
            this.filterFreqLabelTargets[k].textContent = S.format('hz', filter.freq);
            if (filter.slope) {
                this.slopes[kind] = filter.slope;
            }
            this.filterSlopeTargets[k].value = String(filter.slope || this.slopes[kind] || DEFAULT_SLOPE);
            this.filterBypassTargets[k].classList.toggle('is-on', 0 === filter.slope);
            this.paintIdle(input, filterIdle(filter) ? t('Filter bypassed') : '');
            mark(this.filterFreqLabelTargets[k], `${kind}.freq`);
            mark(this.filterSlopeTargets[k], `${kind}.slope`);
        });
        if (this.hasEqBypassTarget) {
            this.eqBypassTarget.classList.toggle('is-on', Boolean(channel.eq_bypass));
            mark(this.eqBypassTarget, 'eq_bypass');
        }
        this.draw();
    }

    /**
     * Turns a fader's knob red while its band or filter does nothing, as a muted gain's knob, and says why on a line
     * of its own above the fader's tooltip from the page. The track keeps its colour.
     */
    paintIdle(input, reason) {
        const fader = input.closest('.fader');
        if (!fader) {
            return;
        }
        fader.classList.toggle('is-muted', '' !== reason);
        fader.dataset.tip ??= fader.title;
        fader.title = [reason, fader.dataset.tip].filter(Boolean).join('\n');
    }

    draw() {
        const canvas = prepare(this.canvasTarget);
        if (null === canvas) {
            return;
        }
        const { g, width, height } = canvas;
        const view = this.view;
        const plot = this.plot();
        drawGrid(g, plot, width, height, view);
        const channel = this.channel;
        if (!channel) {
            return;
        }
        const slopes = getLabels().SLOPES ?? [];
        const n = Math.max(200, Math.round(plot.width));
        const points = Array.from({ length: n + 1 }, (_, i) => {
            const f = 20 * 1000 ** (i / n);
            return [plot.x(f), response(channel, f, slopes)];
        });
        const editing = this.editing;
        const pointed = this.resolve(this.drag ?? this.hovered);
        this.paintPointed(pointed);
        const dimmed = (h) => null !== pointed && (h.kind !== pointed.kind || ('band' === h.kind && h.i !== pointed.i));
        if (null !== editing) {
            const alone = singleFilter(channel, editing);
            const own = Array.from({ length: n + 1 }, (_, i) => [points[i][0], response(alone, 20 * 1000 ** (i / n), slopes)]);
            g.strokeStyle = SINGLE.color;
            g.fillStyle = SINGLE.fill;
            g.lineWidth = 1.5;
            for (const kind of ['phase', 'mag'].filter((k) => view[k])) {
                for (const run of curveRuns(own, kind)) {
                    const path = new Path2D();
                    run.forEach(([x, v], i) => (0 === i ? path.moveTo(x, plot.y(v)) : path.lineTo(x, plot.y(v))));
                    g.stroke(path);
                    // Only the curve on the left axis gets its area, two filled areas would hide each other
                    if (kind !== (view.mag ? 'mag' : 'phase')) {
                        continue;
                    }
                    path.lineTo(run[run.length - 1][0], plot.y(0));
                    path.lineTo(run[0][0], plot.y(0));
                    path.closePath();
                    g.fill(path);
                }
            }
        }
        // The phase first, so the magnitude with its handles stays on top
        for (const kind of ['phase', 'mag'].filter((k) => view[k])) {
            g.strokeStyle = CURVE[kind].color;
            g.lineWidth = CURVE[kind].width;
            g.beginPath();
            for (const run of curveRuns(points, kind)) {
                run.forEach(([x, v], i) => (0 === i ? g.moveTo(x, plot.y(v)) : g.lineTo(x, plot.y(v))));
            }
            g.stroke();
        }
        g.lineWidth = 1;
        g.textBaseline = 'bottom';
        for (const h of this.handles()) {
            if ('band' === h.kind) {
                g.fillStyle = dimmed(h) ? DIMMED : COLORS[h.i % COLORS.length];
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
                g.fillStyle = dimmed(h) ? DIMMED : 'hpf' === h.kind ? '#ffffff' : '#c0ca33';
                g.fillRect(h.x - 5, h.y - 5, 10, 10);
                g.fillText(h.kind.toUpperCase(), h.x, h.y - 8);
            }
        }
        const b = channel.peq[this.selected];
        g.textAlign = 'left';
        g.textBaseline = 'top';
        g.fillStyle = '#ffee58';
        g.fillText(t("Band %band%: %frequency%  Q %q%  %gain%", { band: this.selected + 1, frequency: S.format('hz', b.freq), q: S.format('q', b.q), gain: S.format('peq', b.gain) }), plot.left + 6, plot.top + 4);
    }
}
