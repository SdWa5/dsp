import { Controller } from '@hotwired/stimulus';
import { send, store, history } from '../lib/device.js';
import { t } from '../lib/i18n.js';
import { linkedMembers } from '../lib/link.js';
import { readPref, writePref } from '../lib/prefs.js';
import { EXACT_RATIO, EXACT_THRESHOLD, exaggeratesGain, format, GAIN_MAX, gainToDb, heardDb, heardGain } from '../lib/scale.js';

const KEEP_THRESHOLD = 'gain-fix-keep-threshold';
const CHANGED = 'sdwa5-dsp:gain-fix';

/**
 * Warns on an output strip of the Comp page while the output runs at compressor 1:1.0 with a gain other than
 * 0 dB, and offers 1:1.1 from +20 dB, which keeps the gain exact: either with the gain as set ("Exact gain"), or
 * with the gain moved to the level the output had at 1:1.0 ("Keep level"). Each button names the gain it sets.
 * "Keep threshold" leaves the threshold as set, so 1:1.1 compresses above it. That choice is kept in the browser
 * and shared by every output. Above about +6 dB the level at 1:1.0 lies beyond the highest gain, and "Keep level"
 * says by how much it falls short. On a linked output the fix goes to every output of the link group, which
 * share their gain and compressor. Values: path ("outputs.3"), channel (11).
 */
export default class extends Controller {
    static values = { path: String, channel: Number };
    static targets = ['exact', 'keep', 'keepThreshold', 'fixText', 'fixTextKeep'];

    connect() {
        this.showKeepThreshold = () => this.showChoice();
        window.addEventListener(CHANGED, this.showKeepThreshold);
        this.unsubscribe = store.subscribe(() => this.render());
        this.showChoice();
        this.render();
    }

    disconnect() {
        window.removeEventListener(CHANGED, this.showKeepThreshold);
        this.unsubscribe?.();
    }

    keepThresholdChange(event) {
        writePref(KEEP_THRESHOLD, event.target.checked);
        window.dispatchEvent(new CustomEvent(CHANGED));
    }

    showChoice() {
        const keep = readPref(KEEP_THRESHOLD, false);
        this.keepThresholdTarget.checked = keep;
        this.fixTextTarget.hidden = keep;
        this.fixTextKeepTarget.hidden = !keep;
    }

    render() {
        const output = store.get(this.pathValue);
        this.element.hidden = !exaggeratesGain(output);
        if (this.element.hidden) {
            return;
        }
        this.exactTarget.textContent = `(${format('gain', output.gain)})`;
        const keep = format('gain', heardGain(output.gain));
        const short = heardDb(output.gain) - gainToDb(GAIN_MAX);
        this.keepTarget.textContent = `(${short >= 0.05 ? t('%gain%, %short% dB short', { gain: keep, short: short.toFixed(1) }) : keep})`;
    }

    exact() {
        this.fix(false);
    }

    keepLevel() {
        this.fix(true);
    }

    fix(keepLevel) {
        const [side, index] = this.pathValue.split('.');
        const comp = store.get(`${this.pathValue}.comp`);
        const gain = store.get(`${this.pathValue}.gain`);
        if (!comp || undefined === gain) {
            return;
        }
        history.begin('Correct output gain');
        const members = linkedMembers(store.get(side) ?? [], Number(index));
        const base = this.channelValue - Number(index);
        const commands = [];
        if (keepLevel) {
            // The gains first, so the level never jumps up between the writes
            const raw = heardGain(gain);
            for (const i of members) {
                store.set(`${side}.${i}.gain`, raw);
                commands.push({ command: 'gain', args: { channel: base + i, raw } });
            }
        }
        const threshold = readPref(KEEP_THRESHOLD, false) ? comp.threshold : EXACT_THRESHOLD;
        for (const i of members) {
            store.set(`${side}.${i}.comp`, { ...comp, ratio: EXACT_RATIO, threshold });
            commands.push({ command: 'compressor', args: { ...comp, channel: base + i, ratio: EXACT_RATIO, threshold } });
        }
        store.notify();
        send(commands, false);
        history.end();
    }
}
