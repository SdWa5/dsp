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
