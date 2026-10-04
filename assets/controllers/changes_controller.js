import { Controller } from '@hotwired/stimulus';
import { t } from '../lib/i18n.js';
import { getDefaults, getUnits, history, send, store } from '../lib/device.js';
import { getChangeView } from '../lib/changeview.js';
import { channelId } from '../lib/channels.js';
import { channelReset, pageReset, resetCommands } from '../lib/reset.js';

/** The fields that carry a changed bar (app.css), the fader by its value field. */
const FIELD = ':is(.toggle, .choice, .radios, .text, .value, input, select, output, button):not(.tab).is-changed, .fader.is-changed';
/** On touch a tap this close to the left edge of a changed field hits its bar. */
const EDGE = 12;

/**
 * Resets changed settings to their factory default by a click on their mark. Over a changed field a small button
 * covers its bar and resets that field through the widget's own write, so linked channels follow. The triangle of a
 * channel tab resets the channel and its linked channels, the bar of a page tab resets that page on every channel,
 * both after a confirmation. Names and link membership stay. Every reset is one undo step. Nothing reacts while the
 * marks are switched off, offline or while the history replays.
 */
export default class extends Controller {
    connect() {
        this.mark = document.createElement('button');
        this.mark.type = 'button';
        this.mark.className = 'changed-mark';
        this.mark.hidden = true;
        this.mark.title = t('Click: back to the factory default');
        this.mark.setAttribute('aria-label', this.mark.title);
        this.mark.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            const field = this.field;
            this.hide();
            if (field && this.active) {
                this.resetField(field);
            }
        });
        this.element.append(this.mark);
        this.listeners = [
            ['pointermove', (event) => this.track(event), { passive: true }],
            ['pointerleave', () => this.hide(), { passive: true }],
            ['pointerdown', (event) => { this.pointerType = event.pointerType; }, { capture: true, passive: true }],
            ['click', (event) => this.click(event), { capture: true }],
            ['scroll', () => this.hide(), { capture: true, passive: true }],
        ];
        for (const [type, listener, options] of this.listeners) {
            this.element.addEventListener(type, listener, options);
        }
        this.off = store.subscribe(() => { if (this.field && !this.field.matches(FIELD)) this.hide(); });
    }

    disconnect() {
        for (const [type, listener, options] of this.listeners) {
            this.element.removeEventListener(type, listener, options);
        }
        this.off?.();
        this.mark.remove();
    }

    get active() {
        return getChangeView().marks && null !== store.state && !history.locked;
    }

    /** The widget controller that can reset field, or null. */
    widget(field) {
        for (const id of (field.dataset.controller ?? '').split(/\s+/).filter(Boolean)) {
            const controller = this.application.getControllerForElementAndIdentifier(field, id);
            if ('function' === typeof controller?.resetToDefault) {
                return controller.canReset() ? controller : null;
            }
        }
        return null;
    }

    /** The PEQ controller of a field it marks itself (band values, filters, EQ Bypass), or null. */
    peq(field) {
        const node = field.dataset.resetPath ? field.closest('[data-controller~=peq]') : null;
        return node ? this.application.getControllerForElementAndIdentifier(node, 'peq') : null;
    }

    fieldAt(node) {
        const field = node?.closest?.(FIELD);
        return field && (this.widget(field) || this.peq(field)) ? field : null;
    }

    anchor(field) {
        return field.matches('.fader') ? field.querySelector('.fader__value') ?? field : field;
    }

    track(event) {
        if (event.target === this.mark) {
            return;
        }
        const field = this.active && 'touch' !== event.pointerType ? this.fieldAt(event.target) : null;
        if (field) {
            this.show(field);
        } else {
            this.hide();
        }
    }

    /** Lays the button over the bar. Inside an open dialog it moves into it, since a dialog covers the page. */
    show(field) {
        const host = field.closest('dialog[open]') ?? this.element;
        if (this.mark.parentElement !== host) {
            host.append(this.mark);
        }
        const rect = this.anchor(field).getBoundingClientRect();
        Object.assign(this.mark.style, { left: `${rect.left}px`, top: `${rect.top}px`, height: `${rect.height}px` });
        this.mark.hidden = false;
        this.field = field;
    }

    hide() {
        this.mark.hidden = true;
        this.field = null;
    }

    /** Clicks on a tab mark, and on touch taps on a bar, reset before the tab or the field gets them. */
    click(event) {
        if (!this.active || event.target === this.mark) {
            return;
        }
        const triangle = event.target.closest?.('.chtab__changed.is-resettable');
        const page = event.target.closest?.('.tab.is-resettable .tab__mark');
        let done = false;
        if (triangle) {
            done = this.resetChannel(triangle.closest('.chtab'));
        } else if (page) {
            done = this.resetPage(page.closest('.tab'));
        } else if ('touch' === this.pointerType) {
            const field = this.fieldAt(event.target);
            if (field && event.clientX - this.anchor(field).getBoundingClientRect().left <= EDGE) {
                this.resetField(field);
                done = true;
            }
        }
        if (done) {
            event.preventDefault();
            event.stopPropagation();
        }
    }

    resetField(field) {
        history.begin('Reset setting');
        const widget = this.widget(field);
        if (widget) {
            widget.resetToDefault();
        } else {
            this.peq(field)?.resetField(field);
        }
        history.end();
    }

    /** Returns whether the click was taken, which it is once a confirmation was asked, answered or not. */
    resetChannel(tab) {
        // The Aux tab carries no side and resets the Aux alone
        const side = tab?.dataset.side ?? 'aux';
        const index = Number(tab?.dataset.index ?? 0);
        const plan = channelReset(store.state, getDefaults(), side, index);
        if (0 === plan.count) {
            return false;
        }
        const channels = 'aux' === side ? channelId(18) : plan.members.map((i) => channelId(('outputs' === side ? 8 : 0) + i)).join(', ');
        const message = 1 === plan.count
            ? 'Reset %channels% to the factory default? This changes %count% setting and keeps the names.'
            : 'Reset %channels% to the factory default? This changes %count% settings and keeps the names.';
        if (window.confirm(t(message, { channels, count: plan.count }))) {
            this.apply('Reset channel', plan.writes);
        }
        return true;
    }

    resetPage(tab) {
        const plan = pageReset(store.state, getDefaults(), tab?.dataset.page);
        if (0 === plan.count) {
            return false;
        }
        const page = [...tab.childNodes].filter((node) => Node.TEXT_NODE === node.nodeType).map((node) => node.textContent).join('').trim();
        const message = 1 === plan.count
            ? 'Reset the %page% page to the factory default on every channel, hidden ones included? This changes %count% setting and keeps the names.'
            : 'Reset the %page% page to the factory default on every channel, hidden ones included? This changes %count% settings and keeps the names.';
        if (window.confirm(t(message, { page, count: plan.count }))) {
            this.apply('Reset page', plan.writes);
        }
        return true;
    }

    /** Writes every reset locally, then sends one command per unit, all as one undo step. */
    apply(label, writes) {
        const { commands, writes: owned } = resetCommands(store.state, writes, getUnits());
        if (0 === owned.length) {
            return;
        }
        history.begin(label);
        for (const { path, value } of owned) {
            store.set(path, value);
        }
        store.notify();
        send(commands);
        history.end();
    }
}
