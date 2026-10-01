// Channel links, as in the vendor editor: the master holds the OR mask of its group, slaves hold 0.

const popcount = (v) => v.toString(2).split('').filter((b) => '1' === b).length;

/**
 * Indices (0..7) linked with channel index i of a side (list of channels with a link mask).
 */
export function linkedMembers(channels, i) {
    const bit = 1 << i;
    for (const channel of channels) {
        if (channel.link & bit && popcount(channel.link) > 1) {
            return [0, 1, 2, 3, 4, 5, 6, 7].filter((k) => (channel.link >> k) & 1);
        }
    }
    return [i];
}

/** Group number (1..4) per channel index, 0 = not linked. */
export function linkGroups(channels) {
    const groups = new Array(8).fill(0);
    let group = 0;
    for (const channel of channels) {
        if (popcount(channel.link) > 1) {
            group += 1;
            for (let k = 0; k < 8; k += 1) {
                if ((channel.link >> k) & 1) {
                    groups[k] = group;
                }
            }
        }
    }
    return groups;
}

/**
 * Commands for new link groups: copy the master to new slaves, set the link flags, then activate.
 * A slave is new when its group had another master before, which also covers a slave that moves from one
 * group to another and keeps its flags 0. The copy takes every parameter, and in the demo also the name, so
 * each new slave gets its own name back right after it. groups: group number per index (0 = alone), base: 0
 * for inputs, 8 for outputs.
 */
export function linkCommands(channels, groups, base) {
    const copies = [];
    const names = [];
    const links = [];
    const byGroup = new Map();
    groups.forEach((group, i) => {
        const key = 0 === group ? `solo${i}` : group;
        byGroup.set(key, [...(byGroup.get(key) ?? []), i]);
    });
    for (const members of byGroup.values()) {
        members.sort((a, b) => a - b);
        const mask = members.reduce((m, i) => m | (1 << i), 0);
        members.forEach((i, k) => {
            const value = 0 === k ? mask : 0;
            if (k > 0 && linkedMembers(channels, i)[0] !== members[0]) {
                copies.push({ command: 'copyChannel', args: { source: base + members[0], target: base + i } });
                names.push({ command: 'channelName', args: { channel: base + i, name: channels[i].name ?? '' } });
            }
            if (channels[i].link !== value) {
                links.push({ command: 'link', args: { channel: base + i, flags: value } });
            }
        });
    }
    return [...copies, ...names, ...links];
}

/**
 * Display order of the channel indices with the members of a link group next to each other. A group takes the
 * place of its lowest member. groups: group number per index, as linkGroups returns it.
 */
export function linkOrder(groups) {
    const order = [];
    groups.forEach((group, i) => {
        if (order.includes(i)) {
            return;
        }
        order.push(...(0 === group ? [i] : groups.flatMap((g, k) => (g === group ? [k] : []))));
    });
    return order;
}

/** The channels in link order, one array per link group and one per channel alone: [[0, 4], [1], [2, 3, 7], …]. */
export function linkUnits(groups) {
    const units = [];
    for (const i of linkOrder(groups)) {
        const unit = 0 === groups[i] ? undefined : units.find((u) => groups[u[0]] === groups[i]);
        if (unit) {
            unit.push(i);
        } else {
            units.push([i]);
        }
    }
    return units;
}

const escapeHtml = (text) => text.replace(/[&<>"']/g, (c) => `&#${c.charCodeAt(0)};`);

/** The longest group name, about as wide as a channel tab. */
export const LINK_NAME_LENGTH = 12;

/**
 * The mark of link group `group` (1..) for the display settings: the code, the group's shape (circle, square,
 * triangle, diamond), both next to each other, or nothing. A group with a name shows the name instead of the
 * code. 0 has no mark.
 */
export function linkMark(view, group, name = '') {
    if (group < 1) {
        return '';
    }
    // A name sits in an element of its own, which cuts it short where the room ends
    const code = !view.code ? '' : '' === name ? `L${group}` : `<span class="link-label" title="${escapeHtml(name)}">${escapeHtml(name)}</span>`;
    return view.shape ? `${code}<span class="link-shape" data-shape="${group}" aria-hidden="true"></span>` : code;
}

/**
 * The key of a group's name: its side and its lowest channel, so the name stays with the group when other groups
 * come or go. groups: group number per index, as linkGroups returns it.
 */
export function linkNameKey(side, groups, group) {
    return `${side}:${groups.indexOf(group)}`;
}

/** The name the display settings hold for link group `group` of a side, '' for none. */
export function linkName(view, side, groups, group) {
    return group > 0 ? (view.names?.[linkNameKey(side, groups, group)] ?? '') : '';
}

/** Where linked channels go: in DOM order, next to each other, or one above the other in one column. */
export const PLACEMENTS = ['normal', 'side', 'stacked'];

/**
 * Link display settings from what a browser stored: {code, colour, shape, placement, names}. Before 0.25.0 the
 * placement was the checkbox `columns`, which becomes 'side'. Before 0.30.0 a group showed its shape whenever the
 * code was off, so a stored code: false turns the shape on. names maps a linkNameKey() to a group name, and an
 * empty or foreign entry is left out.
 */
export function linkView(stored) {
    const value = stored && 'object' === typeof stored ? stored : {};
    const names = {};
    for (const [key, name] of Object.entries(value.names && 'object' === typeof value.names ? value.names : {})) {
        const text = 'string' === typeof name ? name.trim().slice(0, LINK_NAME_LENGTH) : '';
        if (/^(inputs|outputs):[0-7]$/.test(key) && '' !== text) {
            names[key] = text;
        }
    }
    return {
        code: value.code ?? true,
        colour: value.colour ?? false,
        shape: value.shape ?? false === value.code,
        placement: PLACEMENTS.includes(value.placement) ? value.placement : true === value.columns ? 'side' : 'normal',
        names,
    };
}

/** Least common multiple, the row count in which every stacked group divides its column equally. */
export function lcm(a, b) {
    const gcd = (x, y) => (0 === y ? x : gcd(y, x % y));
    return (a * b) / gcd(a, b);
}

/**
 * Grid cells of one side's eight channels for a placement: {columns, rows, cells}, where cells[i] is
 * {column, row, span} (1-based). 'stacked' gives each link group one column with its members one above the
 * other. The rows are the least common multiple of the group sizes, so the members of a group share their column
 * equally, and a channel alone spans all rows.
 */
export function linkLayout(groups, placement) {
    if ('normal' === placement) {
        return { columns: 8, rows: 1, cells: groups.map((_g, i) => ({ column: i + 1, row: 1, span: 1 })) };
    }
    if ('side' === placement) {
        const order = linkOrder(groups);
        return { columns: 8, rows: 1, cells: groups.map((_g, i) => ({ column: order.indexOf(i) + 1, row: 1, span: 1 })) };
    }
    const units = linkUnits(groups);
    const rows = units.reduce((r, u) => lcm(r, u.length), 1);
    const cells = new Array(groups.length);
    units.forEach((unit, column) => {
        const span = rows / unit.length;
        unit.forEach((i, position) => {
            cells[i] = { column: column + 1, row: position * span + 1, span };
        });
    });
    return { columns: units.length, rows, cells };
}

/** Group numbers 1.. in the order of each group's lowest member, a group of one becomes 0, as linkGroups numbers them. */
export function normalizeGroups(groups) {
    const numbers = new Map();
    return groups.map((group) => {
        if (0 === group || groups.filter((g) => g === group).length < 2) {
            return 0;
        }
        if (!numbers.has(group)) {
            numbers.set(group, numbers.size + 1);
        }
        return numbers.get(group);
    });
}

/**
 * A click on cell (row, column) of the Link matrix. An empty cell links the two channels, merging their groups.
 * A lit cell takes the column channel out of its group. groups: group number per index, as linkGroups returns it.
 */
export function toggleLink(groups, row, column) {
    if (row === column) {
        return normalizeGroups(groups);
    }
    const next = [...groups];
    if (0 !== groups[row] && groups[row] === groups[column]) {
        next[column] = 0;
        return normalizeGroups(next);
    }
    const group = groups[row] || groups[column] || Math.max(0, ...groups) + 1;
    groups.forEach((g, i) => {
        if (i === row || i === column || (0 !== g && (g === groups[row] || g === groups[column]))) {
            next[i] = group;
        }
    });
    return normalizeGroups(next);
}

/**
 * How a section with one item per channel of a side places its items: `order` lists the channel indices in display
 * order, and `blocks` the link groups that form a block of their own (only when stacked). In channel order
 * nothing moves, side by side and stacked put the members of a group next to each other.
 */
export function linkPlacement(groups, placement) {
    if ('normal' === placement) {
        return { order: groups.map((_g, i) => i), blocks: [] };
    }
    const units = linkUnits(groups);
    return { order: units.flat(), blocks: 'stacked' === placement ? units.filter((u) => u.length > 1) : [] };
}

/**
 * The channels before which a table leaves a gap when stacked: the first member of a link group and the first
 * channel after a group, never between channels alone. Empty for the other placements.
 */
export function linkGaps(groups, placement) {
    const { order, blocks } = linkPlacement(groups, placement);
    const gaps = new Set();
    if (0 === blocks.length) {
        return gaps;
    }
    order.forEach((i, position) => {
        const previous = order[position - 1];
        if (position > 0 && (0 !== groups[i] || 0 !== groups[previous]) && groups[i] !== groups[previous]) {
            gaps.add(i);
        }
    });
    return gaps;
}
