import { Controller } from '@hotwired/stimulus';
import { t } from '../lib/i18n.js';
import { SERIAL_COMMANDS, rawCommand, serialHex, serialPacket, serialValueHint, toggleSerialMask } from '../lib/rs232.js';

/** A local code generator. It never subscribes to the bridge or sends a command. */
export default class extends Controller {
    static targets = ['command', 'id', 'params', 'packet', 'error'];

    connect() {
        this.commandTarget.replaceChildren(...[...SERIAL_COMMANDS, rawCommand].map((c, index) => {
            const option = document.createElement('option');
            option.value = String(index);
            option.textContent = `${c.code === null ? '' : `${serialHex(c.code)} · `}${t(c.label)}`;
            return option;
        }));
        this.select();
    }

    select() {
        this.spec = [...SERIAL_COMMANDS, rawCommand][Number(this.commandTarget.value)];
        this.paramsTarget.replaceChildren(...this.spec.params.map((spec) => {
            const label = document.createElement('label');
            label.append(t(spec.label), ' ');
            const input = document.createElement(spec.options ? 'select' : 'input');
            if (spec.options) {
                spec.options.forEach((text, value) => input.add(new Option(t(text), String(value))));
            } else {
                input.type = 'number';
                input.min = String(spec.min);
                input.max = String(spec.max);
                input.step = '1';
                input.value = String(spec.value);
            }
            label.append(input, document.createElement('output'));
            if (!spec.mask) return label;
            const mask = document.createElement('div');
            mask.className = 'serial-mask';
            mask.append(label);
            const channels = document.createElement('div');
            channels.className = 'serial-mask__channels';
            for (let bit = 0; bit < 8; bit++) {
                const toggle = document.createElement('label');
                const box = document.createElement('input');
                box.type = 'checkbox';
                box.dataset.serialBit = String(bit);
                // Preserve the vendor's captions even when an output group is selected.
                toggle.append(box, ` In${bit + 1}`);
                channels.append(toggle);
            }
            mask.append(channels);
            return mask;
        }));
        this.render();
    }

    render(event) {
        const bit = event?.target?.dataset.serialBit;
        if (bit !== undefined) {
            const input = event.target.closest('.serial-mask').querySelector('input[type=number]');
            const value = toggleSerialMask(input.value.trim() === '' ? NaN : Number(input.value), Number(bit), event.target.checked);
            input.value = Number.isFinite(value) ? String(value) : '';
        }
        const inputs = [...this.paramsTarget.children].map((holder) => holder.querySelector('input, select'));
        const values = inputs.map((input) => input.value.trim() === '' ? NaN : Number(input.value));
        const id = this.idTarget.value.trim() === '' ? NaN : Number(this.idTarget.value);
        const packet = serialPacket(id, this.spec, values);
        this.packetTarget.value = packet ?? '';
        this.errorTarget.hidden = packet !== null;
        inputs.forEach((input, i) => {
            if (this.spec.params[i].mask) {
                for (const box of input.closest('.serial-mask').querySelectorAll('input[type=checkbox]')) {
                    box.checked = Number.isInteger(values[i]) && !!(values[i] & (1 << Number(box.dataset.serialBit)));
                }
            }
            input.nextElementSibling.textContent = Number.isFinite(values[i]) ? serialValueHint(this.spec.params[i], values[i]) : '';
        });
    }
}
