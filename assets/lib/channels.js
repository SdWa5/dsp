// Channel IDs and names. Wherever the editor shows a channel ID it adds the channel's name, unless the
// name is only the device's default ("In1", "Out1").

/** ID of a channel index as the device names it: 0..7 In1..In8, 8..15 Out1..Out8, 16 or 18 Aux. */
export function channelId(index) {
    if (index < 8) {
        return `In${index + 1}`;
    }
    if (index < 16) {
        return `Out${index - 7}`;
    }
    return 'Aux';
}

/** The name the state holds for a channel index, '' when there is none. */
export function channelName(index, state) {
    if (!state) {
        return '';
    }
    const channel = index < 8 ? state.inputs?.[index] : index < 16 ? state.outputs?.[index - 8] : state.aux;
    return String(channel?.name ?? '').trim();
}

/** {id, name}: name is '' when it adds nothing to the ID. */
export function channelParts(index, state) {
    const id = channelId(index);
    const name = channelName(index, state);
    const plain = (text) => text.replace(/\s+/g, '').toLowerCase();
    return { id, name: '' === name || plain(name) === plain(id) ? '' : name };
}

/** "In1 (DJ L)", or just "In1" for an unnamed channel. */
export function channelLabel(index, state) {
    const { id, name } = channelParts(index, state);
    return '' === name ? id : `${id} (${name})`;
}
