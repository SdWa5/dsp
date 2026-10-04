import { Controller } from '@hotwired/stimulus';
import { store, send, history } from '../lib/device.js';
import { getChannelView, onChannelView } from '../lib/channelview.js';
import { getHideMute, onHideMute, setHideMute, hidingMutes, mutePath } from '../lib/hidemute.js';

/**
 * The "Mute hidden channels" option under Channel Visibility. On every change of the hidden channels or of the option
 * it mutes the channels this browser hides. It never unmutes. Each channel is written on its own, without its link
 * group, so visible members keep playing. One undo step per change.
 */
export default class extends Controller {
    static targets = ['on'];

    connect() {
        this.prev = this.view();
        this.offs = [onChannelView(() => this.apply()), onHideMute(() => this.render())];
        this.render();
    }

    disconnect() {
        this.offs.forEach((off) => off());
    }

    view() {
        return { hidden: getChannelView().hidden, on: getHideMute().on };
    }

    render() {
        this.onTarget.checked = getHideMute().on;
        this.apply();
    }

    change() {
        setHideMute({ on: this.onTarget.checked });
    }

    apply() {
        const next = this.view();
        if (JSON.stringify(next) === JSON.stringify(this.prev)) {
            return;
        }
        const prev = this.prev;
        this.prev = next;
        // Offline or during undo and redo nothing is written
        if (null === store.state || history.locked) {
            return;
        }
        const isMuted = (i) => {
            const value = store.get(mutePath(i));
            return 'boolean' === typeof value ? value : null;
        };
        const mute = hidingMutes(prev, next, isMuted);
        if (0 === mute.length) {
            return;
        }
        history.begin('Mute hidden channels');
        for (const i of mute) {
            store.set(mutePath(i), true);
        }
        store.notify();
        send(mute.map((channel) => ({ command: 'mute', args: { channel, on: true } })));
        history.end();
    }
}
