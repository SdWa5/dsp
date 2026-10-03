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
 * Where the nodes sit, in SVG units: `rows` sources on the left and `outputs` on the right, both columns centred
 * on the same height, each node `height` high with `gap` between them. Returns the y of each node's centre.
 */
export function routingRows(rows, outputs, height = 26, gap = 8) {
    const pitch = height + gap;
    const total = Math.max(rows, outputs) * pitch - gap;
    const column = (count) => {
        const top = (total - (count * pitch - gap)) / 2;
        return Array.from({ length: count }, (_, i) => top + i * pitch + height / 2);
    };
    return { height: total, sources: column(rows), outputs: column(outputs) };
}

/**
 * What a hovered element of the Matrix page stands for, from its data-source and data-output: a connection with
 * both, a channel with one of them, or null with neither.
 * @returns {{source?: number, output?: number}|null}
 */
export function routingFocus(data) {
    const focus = {};
    for (const key of ['source', 'output']) {
        if (undefined !== data?.[key] && '' !== data[key]) {
            focus[key] = Number(data[key]);
        }
    }
    return 0 === Object.keys(focus).length ? null : focus;
}

/**
 * Whether an element of the Matrix page lies in a focus. A connection (a matrix cell, a graph line) carries both
 * source and output and lies in a channel's focus when it runs from or to that channel, and in a connection's focus
 * when it is that one. A channel (a header, a graph node) carries one and lies in any focus that names it.
 */
export function inRoutingFocus(focus, element) {
    if (null === focus || null === element) {
        return false;
    }
    const both = undefined !== element.source && undefined !== element.output;
    if (both) {
        return (undefined === focus.source || focus.source === element.source) && (undefined === focus.output || focus.output === element.output);
    }
    return (undefined !== element.source && focus.source === element.source) || (undefined !== element.output && focus.output === element.output);
}

/**
 * Whether an element of the Matrix page lights up under a focus. A cell or a line focuses its connection, which
 * lights that connection and its input and output channel only. A channel focuses itself, which lights the
 * channel and its connections, the lines and the set cells, but not the channels at their far ends. edges:
 * [{source, output}] of the routing.
 */
export function routingLit(focus, element, edges) {
    if (null === focus || null === element || !inRoutingFocus(focus, element)) {
        return false;
    }
    const isChannel = (item) => undefined === item.source || undefined === item.output;
    if (isChannel(element) || !isChannel(focus)) {
        return true;
    }
    return edges.some((edge) => edge.source === element.source && edge.output === element.output);
}
