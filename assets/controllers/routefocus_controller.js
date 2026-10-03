import { Controller } from '@hotwired/stimulus';
import { routingEdges, routingFocus, routingLit } from '../lib/routing.js';
import { store } from '../lib/device.js';

/**
 * Hovering on the Matrix page brings out the same routing in the matrix and in the routing graph. Every element
 * that stands for a channel or a connection carries data-source and data-output: the matrix cells and headers, the
 * graph's nodes and lines. Pointing at a cell or a line lights that connection and its input and output only.
 * Pointing at a channel lights the channel and its connections, its lines and its set cells. Everything else fades. The graph redraws when the routing changes,
 * so it reports each drawing and the focus is applied again.
 */
export default class extends Controller {
    connect() {
        this.focus = null;
        this.key = 'null';
    }

    over(event) {
        const holder = event.target.closest('[data-source], [data-output]');
        this.apply(holder && this.element.contains(holder) ? routingFocus(holder.dataset) : null);
    }

    leave() {
        this.apply(null);
    }

    reapply() {
        this.apply(this.focus, true);
    }

    apply(focus, force = false) {
        const key = JSON.stringify(focus);
        if (!force && key === this.key) {
            return;
        }
        this.focus = focus;
        this.key = key;
        this.element.classList.toggle('has-route-focus', null !== focus);
        const edges = routingEdges(store.state?.outputs);
        for (const node of this.element.querySelectorAll('[data-source], [data-output]')) {
            node.classList.toggle('is-focus', routingLit(focus, routingFocus(node.dataset), edges));
        }
    }
}
