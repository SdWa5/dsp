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
