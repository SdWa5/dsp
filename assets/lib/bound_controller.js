import { Controller } from '@hotwired/stimulus';
import { store, send, getDefaults, getLabels } from './device.js';
import { factoryValue, isChanged } from './defaults.js';
import { linkedMembers } from './link.js';
import { format } from './scale.js';

/**
 * Base of the widgets that show one state value and write it back.
 *
 * Values: bind (state path), send (list of {command, args}, "@path" in args reads the state),
 * link ("inputs:3": the write also goes to the channels linked with In4), fmt (display format),
 * enabled (state path, the widget is disabled while it is falsy). A value that differs from the factory
 * default gets the class is-changed.
 */
export default class extends Controller {
    static values = { bind: String, send: Array, link: String, fmt: String, enabled: String };

    connect() {
        this.busy = false;
        this.unsubscribe = store.subscribe((state) => this.refresh(state));
        this.refresh(store.state);
    }

    disconnect() {
        this.unsubscribe?.();
    }

    get current() {
        return store.get(this.bindValue);
    }

    refresh(state) {
        const offline = null === state;
        const enabled = !offline && (!this.enabledValue || Boolean(store.get(this.enabledValue)));
        this.element.classList.toggle('is-disabled', !enabled);
        this.setDisabled(!enabled);
        this.element.classList.toggle('is-changed', !offline && this.changed(state));
        if (!offline && !this.busy) {
            this.render(this.current, state);
        }
    }

    render() {}

    changed(state) {
        return isChanged(state, getDefaults(), this.bindValue);
    }

    /** Whether a click on the changed mark can reset this widget: it writes to the device and holds no name or link. */
    canReset() {
        return 0 < this.sendValue.length && !/(?:^|\.)(?:name|link)$/.test(this.bindValue) && !this.element.classList.contains('is-disabled');
    }

    /** Writes the factory value through the widget's own write, so linked channels follow. */
    resetToDefault() {
        const value = factoryValue(getDefaults(), this.bindValue, this.current, this.bitValue ?? -1);
        if (null !== value && value !== this.current) {
            this.commit(value);
        }
    }

    setDisabled(disabled) {
        for (const node of this.element.querySelectorAll('input, select, button')) {
            node.disabled = disabled;
        }
        if (this.element.matches('input, select, button')) {
            this.element.disabled = disabled;
        }
    }

    text(value) {
        return format(this.fmtValue, value, store.state, getLabels());
    }

    /**
     * Sets the value locally, on the linked channels too, and sends the commands.
     */
    commit(value) {
        if (null === store.state) {
            return;
        }
        const [side, index] = this.linkValue ? this.linkValue.split(':') : [null, null];
        const members = side ? linkedMembers(store.get(side) ?? [], Number(index)) : [null];
        const commands = [];
        for (const member of members) {
            const path = this.retarget(this.bindValue, side, index, member);
            if (path) {
                store.set(path, value);
            }
        }
        for (const member of members) {
            for (const template of this.sendValue) {
                commands.push(this.resolve(template, side, Number(index), member));
            }
        }
        store.notify();
        send(commands);
    }

    retarget(path, side, index, member) {
        if (null === member || !path.startsWith(`${side}.${index}.`)) {
            return path;
        }
        return `${side}.${member}.${path.slice(`${side}.${index}.`.length)}`;
    }

    resolve(template, side, index, member) {
        const base = 'outputs' === side ? 8 : 0;
        const args = {};
        for (const [key, raw] of Object.entries(template.args ?? {})) {
            let value = raw;
            if (null !== member && 'channel' === key && raw === base + index) {
                value = base + member;
            }
            if ('string' === typeof value && value.startsWith('@')) {
                value = store.get(this.retarget(value.slice(1), side, index, member)) ?? 0;
            }
            args[key] = value;
        }
        return { command: template.command, args };
    }
}
