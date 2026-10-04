import { Controller } from '@hotwired/stimulus';
import { store, send, history } from '../lib/device.js';
import { getChannelView, onChannelView } from '../lib/channelview.js';
import { getHideMute, onHideMute, setHideMute, hidingMutes, mutePath } from '../lib/hidemute.js';

/**
 * The "Mute hidden channels" option under Channel Visibility. On every change of the hidden channels or of the option
 * it mutes the channels this browser hides and unmutes the ones it had muted when they show again. Each channel is
 * written on its own, without its link group, so visible members keep playing. One undo step per change.
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
        setHideMute({ ...getHideMute(), on: this.onTarget.checked });
    }

    apply() {
        const next = this.view();
        if (JSON.stringify(next) === JSON.stringify(this.prev)) {
            return;
        }
        const prev = this.prev;
        this.prev = next;
        // Offline or during undo and redo nothing is written, and the record stays as it is
        if (null === store.state || history.locked) {
            return;
        }
        const isMuted = (i) => {
            const value = store.get(mutePath(i));
            return 'boolean' === typeof value ? value : null;
        };
        const plan = hidingMutes(prev, next, getHideMute().muted, isMuted);
        const writes = [...plan.mute.map((i) => [i, true]), ...plan.unmute.map((i) => [i, false])];
        if (writes.length > 0) {
            history.begin('Mute hidden channels');
            for (const [i, on] of writes) {
                store.set(mutePath(i), on);
            }
            store.notify();
            send(writes.map(([channel, on]) => ({ command: 'mute', args: { channel, on } })));
            history.end();
        }
        if (JSON.stringify(plan.muted) !== JSON.stringify(getHideMute().muted)) {
            setHideMute({ ...getHideMute(), muted: plan.muted });
        }
    }
}
