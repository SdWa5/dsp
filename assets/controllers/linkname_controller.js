import { Controller } from '@hotwired/stimulus';
import { store } from '../lib/device.js';
import { linkGroups } from '../lib/link.js';
import { channelId } from '../lib/channels.js';
import { channelMembers, getChannelView, onChannelView } from '../lib/channelview.js';
import { getLinkView, setLinkView, onLinkView } from '../lib/linkview.js';
import { renderLinkNames } from '../lib/linknames.js';

/** Grouped headings contain one editable browser group name and an individual device-name field for every member. */
export default class extends Controller {
    static targets = ['fields', 'empty', 'heading', 'plainTitle', 'groupTitle', 'member'];
    static values = { index: Number };

    connect() {
        this.offs = [store.subscribe(() => this.render()), onLinkView(() => this.render()), onChannelView(() => this.render())];
        this.render();
    }
    disconnect() { this.offs.forEach(off => off()); }
    render() {
        const side = this.indexValue < 8 ? 'inputs' : 'outputs';
        const groups = linkGroups(store.state?.[side] ?? []);
        const group = this.indexValue < 16 ? (groups[this.indexValue % 8] ?? 0) : 0;
        // Keep original group numbers and the lowest member's name key, while selecting only this group.
        const selected = groups.map(value => group > 0 && value === group ? value : 0);
        // The input itself is the group title; marks carry only colour/icon, never a repeated group name.
        renderLinkNames(this.fieldsTarget, this.emptyTarget, { [side]: selected }, { ...getLinkView(), code: false }, 'input->linkname#rename', false, false);
        const grouped = group > 0 && getChannelView().grouped;
        this.element.classList.toggle('is-grouped', grouped);
        this.plainTitleTarget.hidden = grouped;
        this.groupTitleTarget.hidden = group === 0;
        const members = grouped ? channelMembers(this.indexValue, store.state) : [this.indexValue];
        for (const field of this.memberTargets) field.hidden = !members.includes(Number(field.dataset.nameIndex));
        const input = this.fieldsTarget.querySelector('input');
        this.headingTarget.setAttribute('aria-label', grouped ? (input.value || input.placeholder) : channelId(this.indexValue));
    }
    rename(event) {
        const view = getLinkView(), names = { ...view.names }, name = event.target.value.trim();
        if (name) names[event.target.dataset.key] = name; else delete names[event.target.dataset.key];
        setLinkView({ ...view, names });
    }
}
