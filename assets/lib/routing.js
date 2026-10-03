// The routing of the matrix as a graph: the sources (In1..In8, Aux) in a column on the left, the outputs in a
// column on the right, and an edge for every connection.

/** The sources of an output's route mask, by bit: 0..7 In1..In8, 8 Aux. */
export const SOURCES = 9;

/** The channel index of source bit `source` for channelId() and channelLabel(): In1..In8 are 0..7, Aux is 18. */
export function sourceChannel(source) {
    return source < 8 ? source : 18;
}

/**
 * Every connection of the state's outputs as {source, output}, sorted by source and then by output. An output
 * without a route mask has none.
 */
export function routingEdges(outputs) {
    const edges = [];
    for (let source = 0; source < SOURCES; source += 1) {
        (outputs ?? []).forEach((output, index) => {
            if (Number.isInteger(output?.route) && (output.route >> source) & 1) {
                edges.push({ source, output: index });
            }
        });
    }
    return edges;
}

/**
 * Where the nodes and the link group frames sit, in SVG units. columns holds the runs of each column in placed
 * order, {sources, outputs}, each run {group, channels} as linkRuns() gives them. A node is `height` high with
 * `gap` to the next. A run with a group gets a frame as a stacked block has: a head `head` high on top, then its
 * nodes `pad` apart and `pad` inside the frame. Both columns are centred on the same height. Returns, per column,
 * the y of each channel's centre by channel and the frames with their top and bottom.
 * @returns {{height: number, sources: {y: number[], frames: {group: number, channels: number[], top: number, bottom: number}[]}, outputs: object}}
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
    return { height: total, sources: centre(sources), outputs: centre(outputs) };
}

/** A data attribute as a list of channels: "3" or "0 1 3", none for a missing or empty one. */
const channelList = (value) => (undefined === value || '' === value ? [] : String(value).trim().split(/\s+/).map(Number));

/**
 * What a hovered element of the Matrix page stands for, from its data attributes: a connection with data-source
 * and data-output, a channel with one of them, a link group with data-sources or data-outputs listing its
 * channels, or null with none.
 * @returns {{sources: number[], outputs: number[]}|null}
 */
export function routingFocus(data) {
    const sources = [...channelList(data?.source), ...channelList(data?.sources)];
    const outputs = [...channelList(data?.output), ...channelList(data?.outputs)];
    return 0 === sources.length && 0 === outputs.length ? null : { sources, outputs };
}

/** Whether a focus or an element stands for channels on one side, a channel or a link group, rather than a connection. */
const isChannels = (item) => 0 === item.sources.length || 0 === item.outputs.length;

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
