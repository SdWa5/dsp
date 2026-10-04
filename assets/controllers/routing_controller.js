import { Controller } from '@hotwired/stimulus';
import { store, send } from '../lib/device.js';
import { channelId, channelParts } from '../lib/channels.js';
import { linkGroups, linkName } from '../lib/link.js';
import { getLinkView, onLinkView } from '../lib/linkview.js';
import { routingEdges, routingLayout, routingPath, AUX, GRAPH_WIDTH, NODE_WIDTH, NODE_HEIGHT, SOURCES, sourceChannel } from '../lib/routing.js';
import { t } from '../lib/i18n.js';
import { getChannelView, onChannelView, channelVisible, visibleRuns, visibleEdge } from '../lib/channelview.js';
import { routingEdit, routingEndpoint, routingRemoval } from '../lib/routingedit.js';
import { RoutingGesture } from '../lib/routinggesture.js';

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
 * Hover focus matches the matrix. Either endpoint can start a pointer or keyboard route toggle.
 * Writes preserve unrelated destination bits and keep the Matrix's independent per-output behavior.
 */
export default class extends Controller {
    static targets = ['svg', 'announcement'];

    connect() {
        this.signature = null;
        this.gesture = new RoutingGesture(this.svgTarget, (first, second) => this.edit(first, second), (first, second) => this.commit(first, second));
        this.offs = [store.subscribe(() => this.render()), onLinkView(() => this.render()), onChannelView(() => this.render())];
        this.render();
    }

    disconnect() {
        this.offs.forEach((off) => off());
        this.gesture.disconnect();
    }

    edit(first, second) {
        return routingEdit(store.state, routingEndpoint(first.dataset.routingEndpoint), routingEndpoint(second.dataset.routingEndpoint));
    }

    commit(first, second) {
        const edit = this.edit(first, second);
        this.write(edit);
    }

    remove(event) {
        const edge = event.target.closest('.routing__hit');
        if (!edge || !this.svgTarget.contains(edge)) return;
        event.preventDefault();
        this.gesture.cancel();
        this.write(routingRemoval(store.state, Number(edge.dataset.source), Number(edge.dataset.output)));
    }

    removeKey(event) {
        if (!event.repeat && ['Delete', 'Backspace', 'Enter', ' '].includes(event.key)) this.remove(event);
    }

    write(edit) {
        if (!edit) return;
        store.set(edit.path, edit.mask);
        store.notify();
        send([edit.command]);
        this.announcementTarget.textContent = t(edit.enabled ? 'Connected %source% to %destination%.' : 'Disconnected %source% from %destination%.', {
            source: channelId(sourceChannel(edit.source)), destination: channelId(edit.output === AUX ? 18 : 8 + edit.output),
        });
    }

    render() {
        const state = store.state;
        const edges = routingEdges(state?.outputs, state?.aux?.select).filter(edge => visibleEdge(edge));
        const names = [...Array.from({ length: SOURCES }, (_, s) => sourceChannel(s)), ...Array.from({ length: 8 }, (_, o) => 8 + o)]
            .map((index) => channelParts(index, state));
        const view = getLinkView();
        const groups = { inputs: linkGroups(state?.inputs ?? []), outputs: linkGroups(state?.outputs ?? []) };
        const placement = null === state ? 'normal' : view.placement;
        // Only inputs and outputs belong to link groups; Aux has its own central column
        const runs = {
            sources: visibleRuns(groups.inputs, placement, 0),
            outputs: visibleRuns(groups.outputs, placement, 8),
        };
        // The state updates often, the graph only redraws when a connection, a name or the link display changes
        const reported = [...Array.from({ length: 8 }, (_, index) => Number.isInteger(state?.outputs?.[index]?.route)), Number.isInteger(state?.aux?.select)];
        const signature = JSON.stringify([edges, names, groups, view, getChannelView(), reported, state !== null]);
        if (signature === this.signature) {
            return;
        }
        this.signature = signature;
        const active = this.svgTarget.contains(document.activeElement) ? document.activeElement : null;
        const focused = active?.dataset.routingEndpoint ?? (active?.matches('.routing__hit')
            ? `${active.dataset.output === '8' ? 'aux' : 'output'}:${active.dataset.output}` : null);
        this.gesture.cancel();
        const layout = routingLayout(runs, { height: NODE.height, head: HEAD, pad: MARGIN, aux: channelVisible(18) });
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
            const label = t('%source% to %destination%. Double-click or press Delete to remove this connection.', {
                source: channelId(sourceChannel(source)), destination: channelId(output === AUX ? 18 : 8 + output),
            });
            const hit = this.svgNode(svg, 'path', {
                class: 'routing__hit', d: routingPath({ source, output }, layout),
                'data-source': source, 'data-output': output, role: 'button', tabindex: 0, 'aria-label': label,
            });
            this.svgNode(hit, 'title').textContent = label;
        }
        names.forEach((parts, n) => {
            if (!channelVisible(n < 8 ? n : n === AUX ? 18 : n - SOURCES + 8)) return;
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
            const endpoint = `${isAux ? 'aux' : isSource ? 'input' : 'output'}:${isAux ? AUX : isSource ? n : n - SOURCES}`;
            const available = state !== null && (isAux ? reported.some(Boolean) : isSource ? reported.some(Boolean) : reported[n - SOURCES]);
            node.dataset.routingEndpoint = endpoint;
            node.setAttribute('role', 'button');
            node.setAttribute('tabindex', available ? '0' : '-1');
            node.setAttribute('aria-disabled', String(!available));
            node.setAttribute('aria-pressed', 'false');
            node.setAttribute('aria-label', [parts.id, parts.name].filter(Boolean).join(' '));
            node.setAttribute('aria-describedby', 'routing-help');
            this.svgNode(node, 'title').textContent = t('Drag between compatible channels to toggle a route. Enter or Space selects an endpoint; Escape cancels.');
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
            // Chromium negotiates touch scrolling on HTML boxes, not SVG group/shape touch-action rules.
            // A transparent HTML surface blocks scrolling only inside enabled node rectangles.
            const surface = this.svgNode(node, 'foreignObject', { width: NODE.width, height: NODE.height });
            const handle = document.createElement('div');
            handle.className = 'routing__handle';
            surface.append(handle);
        });
        if (focused) svg.querySelector(`[data-routing-endpoint="${focused}"]`)?.focus({ preventScroll: true });
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
