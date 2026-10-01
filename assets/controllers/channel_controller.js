import { Controller } from '@hotwired/stimulus';
import { store } from '../lib/device.js';
import { channelLabel, channelParts } from '../lib/channels.js';
import { linkGroups, linkMark } from '../lib/link.js';
import { getLinkView, onLinkView } from '../lib/linkview.js';

/**
 * A channel ID with the channel's name next to it: "In1 DJ L". In an <option> as plain text "In1 · DJ L".
 * A linked input or output also shows its link group as the display settings ask: the code, the icon and the
 * colour, as on the channel tabs. Values: index (0..7 In, 8..15 Out, 18 Aux), link (false where the group
 * would mislead, as in the Link dialog, which shows the groups being edited).
 */
export default class extends Controller {
    static values = { index: Number, link: { type: Boolean, default: true } };

    connect() {
        this.shown = null;
        this.offs = [store.subscribe((state) => this.render(state)), onLinkView(() => this.render(store.state))];
        this.render(store.state);
    }

    disconnect() {
        this.offs.forEach((off) => off());
    }

    render(state) {
        const { id, name } = channelParts(this.indexValue, state);
        const view = getLinkView();
        const group = this.group(state);
        const key = JSON.stringify([id, name, group, view.code, view.shape, view.colour]);
        if (key === this.shown) {
            return;
        }
        this.shown = key;
        if (this.element.matches('option')) {
            this.element.textContent = channelLabel(this.indexValue, state);
            return;
        }
        const nameNode = this.element.querySelector('.ch__name');
        if (nameNode) {
            nameNode.textContent = name;
        }
        this.element.classList.toggle('is-named', '' !== name);
        this.element.title = channelLabel(this.indexValue, state);
        this.showLink(view, group);
    }

    /** The link group (1..) of this channel, 0 when it is alone, the Aux or not to be shown. */
    group(state) {
        const index = this.indexValue;
        if (!this.linkValue || !state || index > 15) {
            return 0;
        }
        const channels = index < 8 ? state.inputs : state.outputs;
        return Array.isArray(channels) ? linkGroups(channels)[index % 8] : 0;
    }

    showLink(view, group) {
        let mark = this.element.querySelector(':scope > .ch__link');
        const html = linkMark(view, group);
        this.element.dataset.group = view.colour && group > 0 ? String(group) : '';
        // With neither code nor icon, the colour alone shows as a dot
        if ('' === html && '' === this.element.dataset.group) {
            mark?.remove();
            return;
        }
        if (!mark) {
            mark = document.createElement('span');
            mark.className = 'ch__link';
            this.element.append(mark);
        }
        mark.innerHTML = html;
        mark.classList.toggle('is-dot', '' === html);
    }
}
