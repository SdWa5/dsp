import { Controller } from '@hotwired/stimulus';
import { history as service, on, status, store } from '../lib/device.js';
import { nativeUndo, describeSetting, historyMessage } from '../lib/history.js';
import { t } from '../lib/i18n.js';

/** Native text undo stays local. DSP gestures, wheel bursts and keyboard range edits share session history. */
export default class extends Controller {
    static targets = ['undo', 'redo', 'clear', 'announcement'];
    connect() {
        this.offs = [service.history.subscribe(() => this.render()), on('status', () => this.render())];
        this.handlers = {
            pointerdown: e => { if (e.target.matches('input[type=range]') || e.target.closest('[data-controller~=peq] canvas')) { this.pointer = e.pointerId; service.hold('pointer'); } },
            pointerup: e => { if (e.pointerId === this.pointer) { this.pointer = null; service.release('pointer'); } },
            pointercancel: () => { this.pointer = null; service.release('pointer'); },
            wheel: e => { if (e.target.matches('input[type=range]') || e.target.closest('[data-controller~=peq] canvas')) service.wheel(e.target); },
            keydown: e => this.key(e),
            keyup: () => service.release('keyboard'),
            blur: () => { this.pointer = null; service.release('pointer'); service.release('keyboard'); },
        };
        Object.entries(this.handlers).forEach(([name, handler]) => window.addEventListener(name, handler, true));
        this.block = e => { if (service.locked && !e.target.closest('[data-history-target]')) { e.preventDefault(); e.stopImmediatePropagation(); } };
        this.blockedEvents = ['pointerdown', 'click', 'dblclick', 'wheel', 'keydown', 'change', 'input', 'submit'];
        this.blockedEvents.forEach(name => this.element.addEventListener(name, this.block, true));
        this.render();
    }
    disconnect() {
        this.offs.forEach(off => off());
        Object.entries(this.handlers).forEach(([name, handler]) => window.removeEventListener(name, handler, true));
        this.blockedEvents.forEach(name => this.element.removeEventListener(name, this.block, true));
        service.release('pointer'); service.release('keyboard');
    }
    render() {
        const h = service.history;
        const reason = !status.connected || store.state === null ? t('Connect to use history.') : h.busy || service.suspended || h.current || h.pending.length ? t('History is waiting for device writes.') : '';
        for (const direction of ['undo', 'redo']) {
            const button = this[`${direction}Target`];
            const entry = h[direction].at(-1);
            button.disabled = Boolean(reason || !entry);
            const action = entry ? t(entry.label) + (entry.changes[0] ? ` (${describeSetting(entry.changes[0].path)})` : '') : '';
            button.textContent = entry ? t(direction === 'undo' ? 'Undo %action%' : 'Redo %action%', { action }) : t(direction === 'undo' ? 'Undo' : 'Redo');
            button.title = reason || (entry ? button.textContent : t('No action is available.'));
        }
        this.clearTarget.disabled = h.busy || !h.undo.length && !h.redo.length;
        this.announcementTarget.textContent = this.message(h.message);
    }
    message(message) { return historyMessage(message); }
    undo(event) { this.closeMenu(event); service.replay('undo'); }
    redo(event) { this.closeMenu(event); service.replay('redo'); }
    clear(event) { this.closeMenu(event); if (!service.history.busy) service.reset('History cleared.'); }
    /** A menu stays open while it holds the focus, so an Edit menu action releases it as the other menus do. */
    closeMenu(event) { if (event?.currentTarget?.closest?.('.menu') && document.activeElement?.closest('.menu')) document.activeElement.blur(); }
    key(event) {
        if (nativeUndo(event.target)) return;
        if (service.locked) { event.preventDefault(); return; }
        if (event.target.matches('input[type=range]') && ['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'PageUp', 'PageDown', 'Home', 'End'].includes(event.key)) service.hold('keyboard');
        if (!(event.ctrlKey || event.metaKey) || event.altKey || event.repeat) return;
        const key = event.key.toLowerCase();
        if (key !== 'z' && key !== 'y') return;
        event.preventDefault();
        service.replay(key === 'y' || event.shiftKey ? 'redo' : 'undo');
    }
}
