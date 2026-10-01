import { Controller } from '@hotwired/stimulus';
import { store } from '../lib/device.js';
import { channelParts } from '../lib/channels.js';
import { routingEdges, routingRows, SOURCES, sourceChannel } from '../lib/routing.js';

const SVG = 'http://www.w3.org/2000/svg';
// One colour per source, the inputs as on the EQ overview, Aux in grey
const COLORS = ['#ff5252', '#ffd740', '#e040fb', '#40c4ff', '#69f0ae', '#448aff', '#b0bec5', '#ffab40', '#9e9e9e'];
const NODE = { width: 130, height: 26 };
const WIDTH = 440;

/**
 * The routing of the matrix as the vendor editor draws it: In1..In8 and Aux on the left, Out1..Out8 on the right,
 * and a line for every connection, coloured by its source. Hovering a channel brings out its lines. It only
 * shows the routing, the matrix next to it sets it.
 */
export default class extends Controller {
    static targets = ['svg'];

    connect() {
        this.signature = null;
        this.unsubscribe = store.subscribe(() => this.render());
        this.render();
    }

    disconnect() {
        this.unsubscribe?.();
    }

    render() {
        const state = store.state;
        const edges = routingEdges(state?.outputs);
        const names = [...Array.from({ length: SOURCES }, (_, s) => sourceChannel(s)), ...Array.from({ length: 8 }, (_, o) => 8 + o)]
            .map((index) => channelParts(index, state));
        // The state updates often, the graph only redraws when a connection or a name changes
        const signature = JSON.stringify([edges, names]);
        if (signature === this.signature) {
            return;
        }
        this.signature = signature;
        const rows = routingRows(SOURCES, 8, NODE.height);
        const svg = this.svgTarget;
        svg.setAttribute('viewBox', `0 0 ${WIDTH} ${rows.height}`);
        svg.replaceChildren();
        const left = NODE.width;
        const right = WIDTH - NODE.width;
        for (const { source, output } of edges) {
            const y1 = rows.sources[source];
            const y2 = rows.outputs[output];
            const mid = (left + right) / 2;
            this.element(svg, 'path', {
                class: 'routing__edge',
                d: `M${left},${y1} C${mid},${y1} ${mid},${y2} ${right},${y2}`,
                stroke: COLORS[source],
                'data-source': source,
                'data-output': output,
            });
        }
        names.forEach((parts, n) => {
            const isSource = n < SOURCES;
            const y = isSource ? rows.sources[n] : rows.outputs[n - SOURCES];
            const node = this.element(svg, 'g', {
                class: 'routing__node',
                transform: `translate(${isSource ? 0 : right},${y - NODE.height / 2})`,
                [isSource ? 'data-source' : 'data-output']: isSource ? n : n - SOURCES,
            });
            this.element(node, 'rect', { width: NODE.width, height: NODE.height, rx: 4 });
            if (isSource) {
                this.element(node, 'rect', { class: 'routing__swatch', width: 4, height: NODE.height, fill: COLORS[n] });
            }
            const text = this.element(node, 'text', { x: 10, y: NODE.height / 2 + 4 });
            this.element(text, 'tspan', { class: 'routing__id' }).textContent = parts.id;
            if ('' !== parts.name) {
                this.element(text, 'tspan', { dx: 6 }).textContent = parts.name;
            }
            node.addEventListener('pointerenter', () => this.focus(node.dataset));
            node.addEventListener('pointerleave', () => this.focus(null));
        });
    }

    /** Brings out the lines of one channel, or all of them again with null. */
    focus(data) {
        const svg = this.svgTarget;
        svg.classList.toggle('has-focus', null !== data);
        for (const edge of svg.querySelectorAll('.routing__edge')) {
            const on = null !== data && (edge.dataset.source === data.source || edge.dataset.output === data.output);
            edge.classList.toggle('is-focus', on);
        }
    }

    element(parent, name, attributes) {
        const node = document.createElementNS(SVG, name);
        for (const [key, value] of Object.entries(attributes)) {
            node.setAttribute(key, String(value));
        }
        parent.append(node);
        return node;
    }
}
