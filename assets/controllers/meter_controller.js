import { t } from '../lib/i18n.js';
import { Controller } from '@hotwired/stimulus';
import { on, store } from '../lib/device.js';
import { limits, meterFraction, meterThreshold, showsThreshold } from '../lib/meters.js';
import { getMeterView, onMeterView } from '../lib/meterview.js';

const HOLD_MS = 1500;
const HOT_MS = 2000;

/**
 * Level bar of one channel: In1–8 = 0–7, Out1–8 = 8–15, Aux = 16. Range -60..+12 dB with ticks every
 * 12 dB, a peak mark that holds for 1.5 s and a red top while the level was above 0 dB. A dashed mark shows
 * the gate threshold of an input or the compressor threshold of an output and the Aux, as the Meters
 * settings of the Channels menu choose. An output meter has an orange Limit lamp above the red top, lit while the
 * device reports its limiter working (byte 51 of the meter reply, unconfirmed on the device).
 */
export default class extends Controller {
    static targets = ['threshold'];
    static values = { index: Number };

    connect() {
        this.peak = -90;
        this.peakAt = 0;
        this.hotAt = -Infinity;
        this.level = -90;
        this.threshold = null;
        this.limiting = false;
        this.offs = [
            on('meters', (levels) => this.show(levels[this.indexValue] ?? -90)),
            on('limiting', (mask) => this.showLimiting(limits(mask, this.indexValue))),
            store.subscribe(() => this.showThreshold()),
            onMeterView(() => this.showThreshold()),
        ];
        this.showThreshold();
        this.show(-90);
    }

    disconnect() {
        this.offs.forEach((off) => off());
    }

    show(db, now = performance.now()) {
        this.level = db;
        if (db >= this.peak || now - this.peakAt > HOLD_MS) {
            this.peak = db;
            this.peakAt = now;
        }
        if (db >= 0) {
            this.hotAt = now;
        }
        this.element.style.setProperty('--level', meterFraction(db).toFixed(3));
        this.element.style.setProperty('--peak', meterFraction(this.peak).toFixed(3));
        this.element.classList.toggle('is-hot', now - this.hotAt < HOT_MS);
        this.showTitle();
    }

    showThreshold() {
        const threshold = meterThreshold(store.state, this.indexValue);
        const shown = showsThreshold(threshold, getMeterView());
        this.threshold = shown ? threshold : null;
        if (this.hasThresholdTarget) {
            this.thresholdTarget.hidden = !shown;
        }
        this.element.classList.toggle('is-gate', shown && 'gate' === threshold.kind);
        this.element.classList.toggle('is-comp', shown && 'comp' === threshold.kind);
        if (shown) {
            this.element.style.setProperty('--threshold', meterFraction(threshold.db).toFixed(3));
        }
        this.showTitle();
    }

    showLimiting(limiting) {
        if (limiting !== this.limiting) {
            this.limiting = limiting;
            this.element.classList.toggle('is-limiting', limiting);
            this.showTitle();
        }
    }

    showTitle() {
        const parts = [];
        if (this.level > -90) {
            parts.push(t('%level% dB, peak %peak% dB', { level: this.level.toFixed(1), peak: this.peak.toFixed(1) }));
        }
        if (null !== this.threshold) {
            const db = this.threshold.db.toFixed(1);
            parts.push('gate' === this.threshold.kind ? t('Gate threshold %threshold% dB', { threshold: db }) : t('Compressor threshold %threshold% dB', { threshold: db }));
        }
        if (this.limiting) {
            parts.push(t('Limiter working'));
        }
        this.element.title = parts.join('\n');
    }
}
