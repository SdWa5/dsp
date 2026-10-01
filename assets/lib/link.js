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
 * groups: group number per index (0 = alone), base: 0 for inputs, 8 for outputs.
 */
export function linkCommands(channels, groups, base) {
    const copies = [];
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
            if (k > 0 && channels[i].link !== value) {
                copies.push({ command: 'copyChannel', args: { source: base + members[0], target: base + i } });
            }
            if (channels[i].link !== value) {
                links.push({ command: 'link', args: { channel: base + i, flags: value } });
            }
        });
    }
    return [...copies, ...links];
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

/**
 * The mark of link group `group` (1..) for the display settings: the code, the group's shape (circle, square,
 * triangle, diamond), both next to each other, or nothing. 0 has no mark.
 */
export function linkMark(view, group) {
    if (group < 1) {
        return '';
    }
    const code = view.code ? `L${group}` : '';
    return view.shape ? `${code}<span class="link-shape" data-shape="${group}" aria-hidden="true"></span>` : code;
}

/** Where linked channels go: in DOM order, next to each other, or one above the other in one column. */
export const PLACEMENTS = ['normal', 'side', 'stacked'];

/**
 * Link display settings from what a browser stored: {code, colour, shape, placement}. Before 0.25.0 the placement
 * was the checkbox `columns`, which becomes 'side'. Before 0.30.0 a group showed its shape whenever the code was
 * off, so a stored code: false turns the shape on.
 */
export function linkView(stored) {
    const value = stored && 'object' === typeof stored ? stored : {};
    return {
        code: value.code ?? true,
        colour: value.colour ?? false,
        shape: value.shape ?? false === value.code,
        placement: PLACEMENTS.includes(value.placement) ? value.placement : true === value.columns ? 'side' : 'normal',
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
