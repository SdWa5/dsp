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

/** Where linked channels go: in DOM order, next to each other, or one above the other in one column. */
export const PLACEMENTS = ['normal', 'side', 'stacked'];

/**
 * Link display settings from what a browser stored: {code, colour, placement}. Before 0.25.0 the placement was the
 * checkbox `columns`, which becomes 'side'.
 */
export function linkView(stored) {
    const value = stored && 'object' === typeof stored ? stored : {};
    return {
        code: value.code ?? true,
        colour: value.colour ?? false,
        placement: PLACEMENTS.includes(value.placement) ? value.placement : true === value.columns ? 'side' : 'normal',
    };
}

/**
 * Grid cells of one side's eight channels for a placement: {columns, rows, cells}, where cells[i] is
 * {column, row, span} (1-based). 'stacked' gives each link group one column with its members one above the
 * other; a channel alone, and the last member of a shorter group, spans the remaining rows.
 */
export function linkLayout(groups, placement) {
    if ('normal' === placement) {
        return { columns: 8, rows: 1, cells: groups.map((_g, i) => ({ column: i + 1, row: 1, span: 1 })) };
    }
    const order = linkOrder(groups);
    if ('side' === placement) {
        return { columns: 8, rows: 1, cells: groups.map((_g, i) => ({ column: order.indexOf(i) + 1, row: 1, span: 1 })) };
    }
    const units = [];
    for (const i of order) {
        const unit = 0 === groups[i] ? undefined : units.find((u) => groups[u[0]] === groups[i]);
        if (unit) {
            unit.push(i);
        } else {
            units.push([i]);
        }
    }
    const rows = Math.max(...units.map((u) => u.length));
    const cells = new Array(groups.length);
    units.forEach((unit, column) => {
        unit.forEach((i, row) => {
            cells[i] = { column: column + 1, row: row + 1, span: row === unit.length - 1 ? rows - row : 1 };
        });
    });
    return { columns: units.length, rows, cells };
}

/**
 * Link groups from the Link dialog, where every channel names a partner on its side (index, or -1 for none).
 * Partners chain, so In3 → In2 and In2 → In1 link all three. Groups are numbered by their lowest member, as
 * linkGroups numbers them.
 */
export function groupsFromPartners(partners) {
    const root = partners.map((_p, i) => i);
    const find = (i) => (root[i] === i ? i : (root[i] = find(root[i])));
    partners.forEach((partner, i) => {
        if (partner >= 0 && partner < partners.length && partner !== i) {
            const [a, b] = [find(i), find(partner)];
            root[Math.max(a, b)] = Math.min(a, b);
        }
    });
    const lowest = partners.map((_p, i) => find(i));
    const sizes = lowest.reduce((count, r) => count.set(r, (count.get(r) ?? 0) + 1), new Map());
    const numbers = new Map();
    return lowest.map((r) => {
        if (sizes.get(r) < 2) {
            return 0;
        }
        if (!numbers.has(r)) {
            numbers.set(r, numbers.size + 1);
        }
        return numbers.get(r);
    });
}

/** The partners the Link dialog shows for link groups: every member names the lowest one, which names the next. */
export function partnersFromGroups(groups) {
    return groups.map((group, i) => {
        if (0 === group) {
            return -1;
        }
        const members = groups.flatMap((g, k) => (g === group ? [k] : []));
        return members[0] === i ? members[1] : members[0];
    });
}
