import { Controller } from '@hotwired/stimulus';
import { getChannelView, onChannelView, setChannelView } from '../lib/channelview.js';

/** Preferences remain editable without a bridge and never write to device state. */
export default class extends Controller {
    static targets = ['channel', 'grouped'];
    connect() { this.off = onChannelView(() => this.render()); this.render(); }
    disconnect() { this.off?.(); }
    render() {
        const view = getChannelView();
        this.channelTargets.forEach(box => { box.checked = !view.hidden.includes(Number(box.value)); });
        this.groupedTarget.checked = view.grouped;
    }
    change() { setChannelView({ hidden: this.channelTargets.filter(box => !box.checked).map(box => Number(box.value)), grouped: this.groupedTarget.checked }); }
    /** Side actions restore only that side and preserve Aux and the other side's visibility. */
    showSide(event) {
        const base = event.params.side === 'inputs' ? 0 : event.params.side === 'outputs' ? 8 : null;
        if (base === null) return;
        setChannelView({ ...getChannelView(), hidden: getChannelView().hidden.filter(i => i < base || i >= base + 8) });
    }
    showAll() { setChannelView({ ...getChannelView(), hidden: [] }); }
}
