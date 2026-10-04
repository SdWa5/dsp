import { Controller } from '@hotwired/stimulus';
import { store, history } from '../lib/device.js';
import { channelId } from '../lib/channels.js';
import { getChannelView, onChannelView, selectionState, setMaskBits } from '../lib/channelview.js';
import { getLinkView, onLinkView } from '../lib/linkview.js';
import { linkGroups, linkUnits, linkName, linkMark } from '../lib/link.js';
import { t } from '../lib/i18n.js';

/** Additional selectors aggregate multi-channel binary choices in group headers. Individual choices stay complete; member IDs belong in tooltips. */
export default class extends Controller {
    static values = { compact: Boolean };
    connect() {
        this.extraHeads = [];
        this.offs = [store.subscribe(() => this.render()), onChannelView(() => this.render()), onLinkView(() => this.render())];
        this.changed = () => this.render();
        this.element.addEventListener('change', this.changed);
        this.render();
        queueMicrotask(() => this.render());
    }
    disconnect() { this.offs.forEach(off => off()); this.element.removeEventListener('change', this.changed); this.extraHeads.forEach(head => head.remove()); }
    choices() {
        return [...this.element.querySelectorAll('.toggle--route, input[name=outputs], input[data-channelview-target=channel], input[data-displaysettings-target=overview]')].map(element => {
            const label = element.matches('input') ? element.closest('label') : element;
            const index = Number(label.querySelector('[data-channel-index-value]')?.dataset.channelIndexValue);
            return { element, index };
        }).filter(choice => Number.isInteger(choice.index));
    }
    render() {
        this.extraHeads.forEach(head => head.remove());
        this.extraHeads = [];
        this.element.querySelectorAll('.group-selector').forEach(label => label.remove());
        const choices = this.choices();
        // Compact browser settings keep backing checkboxes for stored individual choices and recovery.
        if (this.compactValue) choices.forEach(({ element }) => { element.closest('label').hidden = false; });
        if (!getChannelView().grouped) return;
        if (!choices.length) return;
        const base = choices[0].index < 8 ? 0 : 8;
        const side = base ? 'outputs' : 'inputs';
        const groups = linkGroups(store.state?.[side] ?? []);
        for (const unit of linkUnits(groups).filter(unit => unit.length > 1)) {
            const members = unit.map(i => choices.find(choice => choice.index === base + i)).filter(Boolean);
            if (members.length !== unit.length) continue;
            const state = selectionState(members.map(choice => this.selected(choice.element)));
            const label = document.createElement('label');
            label.className = 'check group-selector';
            const box = document.createElement('input');
            box.type = 'checkbox'; box.checked = state.selected; box.indeterminate = state.mixed;
            box.disabled = members.some(({ element }) => element.disabled || element.classList.contains('is-disabled') || element.classList.contains('is-unknown'));
            box.setAttribute('aria-checked', state.mixed ? 'mixed' : String(state.selected));
            const ids = unit.map(i => channelId(base + i)).join(', ');
            const hidden = unit.filter(i => getChannelView().hidden.includes(base + i)).map(i => channelId(base + i));
            label.title = t('Linked: %channels%', { channels: ids }) + (hidden.length ? '\n' + t('Hidden members: %channels%', { channels: hidden.join(', ') }) : '');
            label.title += '\n' + t('Select every linked member, including hidden channels. Clear every member when all are selected.');
            box.title = label.title;
            const name = linkName(getLinkView(), side, groups, groups[unit[0]]) || `L${groups[unit[0]]}`;
            label.dataset.group = getLinkView().colour ? String(groups[unit[0]]) : '';
            let head = this.element.querySelector(`:scope > .link-block[data-link-group="${groups[unit[0]]}"] > .link-block__head`);
            if (!head) {
                head = document.createElement('span');
                head.className = 'link-block__head link-block__head--selector';
                head.dataset.group = label.dataset.group;
                const first = members[0].element;
                (first.matches('input') ? first.closest('label') : first).before(head);
                this.extraHeads.push(head);
            }
            head.title = label.title;
            const mark = document.createElement('span');
            mark.innerHTML = linkMark(getLinkView(), groups[unit[0]], name);
            label.append(box, mark);
            head.replaceChildren(label);
            if (this.compactValue) members.forEach(({ element }) => { element.closest('label').hidden = true; });
            box.addEventListener('change', () => this.select(members, state.next));
        }
    }
    selected(element) {
        if (element.matches('input')) return element.checked;
        const widget = this.application.getControllerForElementAndIdentifier(element, 'toggle');
        return Boolean(widget && (widget.current >> widget.bitValue) & 1);
    }
    select(members, on) {
        if (members[0].element.matches('input')) {
            members.forEach(({ element }) => { element.checked = on; });
            // Browser choices dispatch their existing preference action; Auto EQ remains form-only.
            if (members[0].element.matches('[data-channelview-target=channel], [data-displaysettings-target=overview]')) {
                members[0].element.dispatchEvent(new Event('change', { bubbles: true }));
            } else this.render();
            return;
        }
        const widgets = members.map(({ element }) => this.application.getControllerForElementAndIdentifier(element, 'toggle'));
        if (widgets.some(widget => !widget || widget.element.disabled || widget.current == null)) return;
        // One command for a shared mask. Different output masks keep their other bits independently.
        const byPath = new Map();
        widgets.forEach(widget => { const entry = byPath.get(widget.bindValue) ?? { widget, bits: [] }; entry.bits.push(widget.bitValue); byPath.set(widget.bindValue, entry); });
        history.begin('Change group selection');
        for (const { widget, bits } of byPath.values()) widget.commit(setMaskBits(widget.current, bits, on));
        history.end();
        this.render();
    }
}
