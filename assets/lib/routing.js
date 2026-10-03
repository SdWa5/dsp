// Routing uses source bits 0..8 and UI destination indices 0..8. Destination 8 is Aux, not Out9.
// Aux receives input bits 0..7 and feeds output bit 8; it never routes to itself.

/** The sources of an output's route mask, by bit: 0..7 In1..In8, 8 Aux. */
export const SOURCES = 9;
export const AUX = 8;
export const GRAPH_WIDTH = 720;
export const NODE_WIDTH = 150;
export const NODE_HEIGHT = 26;

/** The channel index of source bit `source` for channelId() and channelLabel(): In1..In8 are 0..7, Aux is 18. */
export function sourceChannel(source) {
    return source < 8 ? source : 18;
}

/**
 * Every connection as {source, output}, sorted by source and destination. UI destination 8 means Aux.
 * auxSelect is the existing aux.select mask; missing masks contribute no connections.
 */
export function routingEdges(outputs, auxSelect = null) {
    const edges = [];
    for (let source = 0; source < SOURCES; source += 1) {
        (outputs ?? []).forEach((output, index) => {
            if (Number.isInteger(output?.route) && (output.route >> source) & 1) {
                edges.push({ source, output: index });
            }
        });
        if (source < AUX && Number.isInteger(auxSelect) && (auxSelect >> source) & 1) {
            edges.push({ source, output: AUX });
        }
    }
    return edges;
}

/**
 * Where the nodes and the link group frames sit, in SVG units. columns holds the runs of each column in placed
 * order, {sources, outputs}, each run {group, channels} as linkRuns() gives them. A node is `height` high with
 * `gap` to the next. A run with a group gets a frame as a stacked block has: a head `head` high on top, then its
 * nodes `pad` apart and `pad` inside the frame. Both outer columns share a height; Aux occupies a separate row below both.
 * Returns, per column,
 * the y of each channel's centre by channel and the frames with their top and bottom.
 * @returns {{height: number, sources: {y: number[], frames: {group: number, channels: number[], top: number, bottom: number}[]}, outputs: object, aux: number}}
 */
export function routingLayout(columns, { height = 26, gap = 8, head = 14, pad = 4 } = {}) {
    const place = (runs) => {
        const y = [];
        const frames = [];
        let cursor = 0;
        for (const run of runs) {
            const framed = run.group > 0;
            const top = cursor;
            cursor += framed ? head + pad : 0;
            run.channels.forEach((channel, k) => {
                cursor += k > 0 ? (framed ? pad : gap) : 0;
                y[channel] = cursor + height / 2;
                cursor += height;
            });
            if (framed) {
                cursor += pad;
                frames.push({ group: run.group, channels: run.channels, top, bottom: cursor });
            }
            cursor += gap;
        }
        return { y, frames, length: Math.max(0, cursor - gap) };
    };
    const sources = place(columns.sources);
    const outputs = place(columns.outputs);
    const total = Math.max(sources.length, outputs.length);
    const centre = ({ y, frames, length }) => {
        const offset = (total - length) / 2;
        return {
            y: y.map((value) => value + offset),
            frames: frames.map((frame) => ({ ...frame, top: frame.top + offset, bottom: frame.bottom + offset })),
        };
    };
    return { height: total + gap + height, sources: centre(sources), outputs: centre(outputs), aux: total + gap + height / 2 };
}

/** SVG path for each route. Direct input/output lines cross a clear lane above or below the Aux box. */
export function routingPath({ source, output }, layout) {
    const left = NODE_WIDTH;
    const right = GRAPH_WIDTH - NODE_WIDTH;
    const auxLeft = (GRAPH_WIDTH - NODE_WIDTH) / 2;
    const auxRight = auxLeft + NODE_WIDTH;
    const curve = (x1, y1, x2, y2) => {
        const mid = (x1 + x2) / 2;
        return `M${x1},${y1} C${mid},${y1} ${mid},${y2} ${x2},${y2}`;
    };
    if (output === AUX) {
        return curve(left, layout.sources.y[source], auxLeft, layout.aux);
    }
    if (source === AUX) {
        return curve(auxRight, layout.aux, right, layout.outputs.y[output]);
    }
    const y1 = layout.sources.y[source];
    const y2 = layout.outputs.y[output];
    const mean = (y1 + y2) / 2;
    const clearance = NODE_HEIGHT / 2 + 12;
    const lane = mean <= layout.aux ? Math.min(mean, layout.aux - clearance) : Math.max(mean, layout.aux + clearance);
    const before = auxLeft - 12;
    const after = auxRight + 12;
    return `${curve(left, y1, before, lane)} L${after},${lane} C${(after + right) / 2},${lane} ${(after + right) / 2},${y2} ${right},${y2}`;
}

/** A data attribute as a list of channels: "3" or "0 1 3", none for a missing or empty one. */
const channelList = (value) => (undefined === value || '' === value ? [] : String(value).trim().split(/\s+/).map(Number));

/**
 * What a hovered element of the Matrix page stands for, from its data attributes: a connection with data-source
 * and data-output, a channel with one of them, a link group with data-sources or data-outputs listing its
 * channels, a central node with data-aux="1" for both Aux roles, or null with none.
 * @returns {{sources: number[], outputs: number[], aux?: boolean}|null}
 */
export function routingFocus(data) {
    const sources = [...channelList(data?.source), ...channelList(data?.sources)];
    const outputs = [...channelList(data?.output), ...channelList(data?.outputs)];
    // The central Aux node has both roles, but remains a channel rather than an Aux-to-Aux connection.
    if ('1' === data?.aux) {
        return { sources: [AUX], outputs: [AUX], aux: true };
    }
    return 0 === sources.length && 0 === outputs.length ? null : { sources, outputs };
}

/** Whether a focus or an element stands for channels on one side, a channel or a link group, rather than a connection. */
const isChannels = (item) => true === item.aux || 0 === item.sources.length || 0 === item.outputs.length;

/**
 * Whether an element of the Matrix page lies in a focus. A connection (a matrix cell, a graph line) lies in a
 * channel's or group's focus when it runs from or to one of its channels, and in a connection's focus when it is
 * that one. A channel (a header, a graph node) or a group (a group bar or head) lies in a focus that names all of
 * its channels, so hovering a member does not light its whole group.
 */
export function inRoutingFocus(focus, element) {
    if (null === focus || null === element) {
        return false;
    }
    if (!isChannels(element)) {
        if (true === focus.aux) {
            return element.sources.includes(AUX) || element.outputs.includes(AUX);
        }
        return (0 === focus.sources.length || element.sources.every((s) => focus.sources.includes(s)))
            && (0 === focus.outputs.length || element.outputs.every((o) => focus.outputs.includes(o)));
    }
    const covered = (list, named) => list.length > 0 && list.every((channel) => named.includes(channel));
    return covered(element.sources, focus.sources) || covered(element.outputs, focus.outputs);
}

/**
 * Whether an element of the Matrix page lights up under a focus. A cell or a line focuses its connection, which
 * lights that connection and its input and output channel only. A channel or a link group focuses its channels,
 * which lights them and their connections, the lines and the set cells, but not the channels at their far ends.
 * edges: [{source, output}] of the routing.
 */
export function routingLit(focus, element, edges) {
    if (null === focus || null === element || !inRoutingFocus(focus, element)) {
        return false;
    }
    if (isChannels(element) || !isChannels(focus)) {
        return true;
    }
    return edges.some((edge) => element.sources.includes(edge.source) && element.outputs.includes(edge.output));
}
