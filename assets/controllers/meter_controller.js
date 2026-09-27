import { Controller } from '@hotwired/stimulus';
import { on } from '../lib/device.js';

const HOLD_MS = 1500;
const HOT_MS = 2000;
const fraction = (db) => Math.max(0, Math.min(1, (db + 60) / 72));

/**
 * Level bar of one channel: In1–8 = 0–7, Out1–8 = 8–15, Aux = 16. Range -60..+12 dB with ticks every
 * 12 dB, a peak mark that holds for 1.5 s and a red top while the level was above 0 dB.
 */
export default class extends Controller {
    static values = { index: Number };

    connect() {
        this.peak = -90;
        this.peakAt = 0;
        this.hotAt = -Infinity;
        this.off = on('meters', (levels) => this.show(levels[this.indexValue] ?? -90));
        this.show(-90);
    }

    disconnect() {
        this.off?.();
    }

    show(db, now = performance.now()) {
        if (db >= this.peak || now - this.peakAt > HOLD_MS) {
            this.peak = db;
            this.peakAt = now;
        }
        if (db >= 0) {
            this.hotAt = now;
        }
        this.element.style.setProperty('--level', fraction(db).toFixed(3));
        this.element.style.setProperty('--peak', fraction(this.peak).toFixed(3));
        this.element.classList.toggle('is-hot', now - this.hotAt < HOT_MS);
        this.element.title = db > -90 ? `${db.toFixed(1)} dB, peak ${this.peak.toFixed(1)} dB (approximated)` : '';
    }
}
