import { Controller } from '@hotwired/stimulus';
import { on } from '../lib/device.js';

/** Level bar of one channel: In1–8 = 0–7, Out1–8 = 8–15, Aux = 16. Range -60..+12 dB. */
export default class extends Controller {
    static values = { index: Number };

    connect() {
        this.off = on('meters', (levels) => this.show(levels[this.indexValue] ?? -90));
        this.show(-90);
    }

    disconnect() {
        this.off?.();
    }

    show(db) {
        const fraction = Math.max(0, Math.min(1, (db + 60) / 72));
        this.element.style.setProperty('--level', fraction.toFixed(3));
        this.element.title = db > -90 ? `${db.toFixed(1)} dB (approximated)` : '';
    }
}
