import { Controller } from '@hotwired/stimulus';
import { channelVisible, onChannelView } from '../lib/channelview.js';

/** Standalone channel strips follow visibility without adopting link ordering or combined controls. */
export default class extends Controller {
    static values = { index: Number };
    connect() { this.off = onChannelView(() => this.render()); this.render(); }
    disconnect() { this.off?.(); }
    render() { this.element.hidden = !channelVisible(this.indexValue); }
}
