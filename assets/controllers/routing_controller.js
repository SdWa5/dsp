import { Controller } from '@hotwired/stimulus';
import { store } from '../lib/device.js';
import { channelId, channelParts } from '../lib/channels.js';
import { linkGroups, linkName, linkRuns } from '../lib/link.js';
import { getLinkView, onLinkView } from '../lib/linkview.js';
import { routingEdges, routingLayout, routingPath, AUX, GRAPH_WIDTH, NODE_WIDTH, NODE_HEIGHT, SOURCES, sourceChannel } from '../lib/routing.js';
import { t } from '../lib/i18n.js';

const SVG = 'http://www.w3.org/2000/svg';
// One colour per source, the inputs as on the EQ overview, Aux in grey
const COLORS = ['#ff5252', '#ffd740', '#e040fb', '#40c4ff', '#69f0ae', '#448aff', '#b0bec5', '#ffab40', '#9e9e9e'];
const NODE = { width: NODE_WIDTH, height: NODE_HEIGHT };
const WIDTH = GRAPH_WIDTH;
// Room around the columns for the frames of stacked link groups, and the height of a frame's head
const MARGIN = 4;
const HEAD = 14;

/**
 * Inputs, Aux and outputs form three columns. Every direct or Aux route is coloured by its source.
 * Link placement and group frames apply to the outer columns; Aux stays independent in a separate bottom row between the columns.
 * Hover focus matches the matrix. This graph only displays routing; the matrix and selectors edit it.
 */
export default class extends Controller {
    static targets = ['svg'];

    connect() {
        this.signature = null;
        this.offs = [store.subscribe(() => this.render()), onLinkView(() => this.render())];
        this.render();
    }

    disconnect() {
        this.offs.forEach((off) => off());
    }

    render() {
        const state = store.state;
        const edges = routingEdges(state?.outputs, state?.aux?.select);
        const names = [...Array.from({ length: SOURCES }, (_, s) => sourceChannel(s)), ...Array.from({ length: 8 }, (_, o) => 8 + o)]
            .map((index) => channelParts(index, state));
        const view = getLinkView();
        const groups = { inputs: linkGroups(state?.inputs ?? []), outputs: linkGroups(state?.outputs ?? []) };
        const placement = null === state ? 'normal' : view.placement;
        // Only inputs and outputs belong to link groups; Aux has its own central column
        const runs = {
            sources: linkRuns(groups.inputs, placement),
            outputs: linkRuns(groups.outputs, placement),
        };
        // The state updates often, the graph only redraws when a connection, a name or the link display changes
        const signature = JSON.stringify([edges, names, groups, view]);
        if (signature === this.signature) {
            return;
        }
        this.signature = signature;
        const layout = routingLayout(runs, { height: NODE.height, head: HEAD, pad: MARGIN });
        const svg = this.svgTarget;
        svg.setAttribute('viewBox', `${-MARGIN} ${-MARGIN} ${WIDTH + 2 * MARGIN} ${layout.height + 2 * MARGIN}`);
        svg.replaceChildren();
        const right = WIDTH - NODE.width;
        // Stacked, every link group gets a frame with a head that carries its code, as a stacked block does
        const framed = new Set();
        for (const [column, side, base, x] of [['sources', 'inputs', 0, 0], ['outputs', 'outputs', SOURCES, right]]) {
            for (const frame of layout[column].frames) {
                frame.channels.forEach((i) => framed.add(base + i));
                const holder = this.svgNode(svg, 'g', { class: 'routing__group', ['data-' + column]: frame.channels.join(' ') });
                holder.dataset.group = view.colour ? String(frame.group) : '';
                const box = { x: x - MARGIN + 1, width: NODE.width + 2 * MARGIN - 2 };
                this.svgNode(holder, 'title').textContent = t('Linked: %channels%', { channels: frame.channels.map((i) => channelId(('outputs' === side ? 8 : 0) + i)).join(', ') });
                this.svgNode(holder, 'rect', { class: 'routing__frame', ...box, y: frame.top + 1, height: frame.bottom - frame.top - 2, rx: 5 });
                this.svgNode(holder, 'rect', { class: 'routing__head', ...box, y: frame.top + 1, height: HEAD, rx: 3 });
                if (view.code) {
                    const code = this.svgNode(holder, 'text', { class: 'routing__head-code', x: x + NODE.width / 2, y: frame.top + 1 + HEAD / 2 + 3, 'text-anchor': 'middle' });
                    code.textContent = linkName(view, side, groups[side], frame.group) || `L${frame.group}`;
                }
            }
        }
        for (const { source, output } of edges) {
            this.svgNode(svg, 'path', {
                class: 'routing__edge',
                d: routingPath({ source, output }, layout),
                stroke: COLORS[source],
                'data-source': source,
                'data-output': output,
            });
        }
        names.forEach((parts, n) => {
            const isAux = n === AUX;
            const isSource = n < SOURCES;
            const y = isAux ? layout.aux : isSource ? layout.sources.y[n] : layout.outputs.y[n - SOURCES];
            const group = n < 8 ? groups.inputs[n] : n >= SOURCES ? groups.outputs[n - SOURCES] : 0;
            const node = this.svgNode(svg, 'g', {
                class: 'routing__node',
                transform: `translate(${isAux ? (WIDTH - NODE.width) / 2 : isSource ? 0 : right},${y - NODE.height / 2})`,
                [isSource ? 'data-source' : 'data-output']: isSource ? n : n - SOURCES,
            });
            if (isAux) {
                node.dataset.aux = '1';
                node.dataset.output = String(AUX);
            }
            this.svgNode(node, 'rect', { class: 'routing__box', width: NODE.width, height: NODE.height, rx: 4 });
            if (group > 0) {
                // A framed member leaves its colour and code to the frame's head
                node.dataset.group = view.colour && !framed.has(n) ? String(group) : '';
                if (view.code && !framed.has(n)) {
                    const code = this.svgNode(node, 'text', { class: 'routing__link', x: NODE.width - 6, y: NODE.height / 2 + 4, 'text-anchor': 'end' });
                    const side = n < 8 ? 'inputs' : 'outputs';
                    code.textContent = linkName(view, side, groups[side], group) || `L${group}`;
                }
            }
            if (isSource) {
                this.svgNode(node, 'rect', { class: 'routing__swatch', width: 4, height: NODE.height, fill: COLORS[n] });
            }
            const text = this.svgNode(node, 'text', { x: 10, y: NODE.height / 2 + 4 });
            this.svgNode(text, 'tspan', { class: 'routing__id' }).textContent = parts.id;
            if ('' !== parts.name) {
                this.svgNode(text, 'tspan', { dx: 6 }).textContent = parts.name;
            }
        });
        // The page's hover focus (routefocus_controller.js) goes onto the new lines and nodes
        this.dispatch('rendered');
    }

    svgNode(parent, name, attributes = {}) {
        const node = document.createElementNS(SVG, name);
        for (const [key, value] of Object.entries(attributes)) {
            node.setAttribute(key, String(value));
        }
        parent.append(node);
        return node;
    }
}
