// The EQ overview's channel curves: which channels share a curve through their link, and which curve the pointer is on.
import { linkGroups, linkedMembers } from './link.js';

/** Partition already selected, visible channels without changing their identities or preferences. */
export function overviewPlots(channels, separated) {
    return separated
        ? ['inputs', 'outputs', 'aux'].map(key => ({ key, channels: channels.filter(([path]) => path.split('.')[0] === key) }))
        : [{ key: 'combined', channels }];
}

/**
 * The curve paths that belong together with one: the members of its link group on the same side, in channel order,
 * or the path alone. Linked channels share one EQ, so their curves lie on top of each other.
 * @returns {string[]}
 */
export function linkedPaths(path, state) {
    const [side, index] = path.split('.');
    if (undefined === index || !Array.isArray(state?.[side])) {
        return [path];
    }

    return linkedMembers(state[side], Number(index)).map((k) => `${side}.${k}`);
}

/**
 * Where a curve takes its colour from: the link group number (0 alone) and the lead path, the first member of the
 * group. A linked curve draws in its group's colour while the Channels menu shows link colours, otherwise in its
 * lead's channel colour, so the members of a group always match.
 * @returns {{group: number, lead: string}}
 */
export function curveColourSource(path, state) {
    const [side, index] = path.split('.');
    if (undefined === index || !Array.isArray(state?.[side])) {
        return { group: 0, lead: path };
    }

    return { group: linkGroups(state[side])[Number(index)] ?? 0, lead: linkedPaths(path, state)[0] };
}

/** Distance from point p to the segment a–b, all [x, y]. */
function segmentDistance([px, py], [ax, ay], [bx, by]) {
    const dx = bx - ax;
    const dy = by - ay;
    const length = dx * dx + dy * dy;
    const t = 0 === length ? 0 : Math.max(0, Math.min(1, ((px - ax) * dx + (py - ay) * dy) / length));

    return Math.hypot(px - ax - t * dx, py - ay - t * dy);
}

/**
 * The path of the curve nearest to the pointer within reach pixels, or null. curves: [{path, runs}], each run a
 * list of [x, y] in the canvas's CSS pixels as drawn.
 * @returns {string|null}
 */
export function nearestCurve(curves, x, y, reach = 6) {
    let best = null;
    let distance = reach;
    for (const { path, runs } of curves) {
        for (const run of runs) {
            for (let i = 1; i < run.length; ++i) {
                // Segments far off to the side cannot be nearer than the reach
                if (Math.min(run[i - 1][0], run[i][0]) - reach > x || Math.max(run[i - 1][0], run[i][0]) + reach < x) {
                    continue;
                }
                const d = segmentDistance([x, y], run[i - 1], run[i]);
                if (d <= distance) {
                    [best, distance] = [path, d];
                }
            }
        }
    }

    return best;
}
