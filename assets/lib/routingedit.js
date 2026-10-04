/** Normalize either endpoint order. Aux is a destination for inputs and a source for outputs. */
export function editableRoute(first, second) {
    const valid = node => node && ['input', 'output', 'aux'].includes(node.kind)
        && Number.isInteger(node.index) && node.index >= 0 && node.index <= (node.kind === 'aux' ? 8 : 7)
        && (node.kind !== 'aux' || node.index === 8);
    if (!valid(first) || !valid(second) || first.kind === second.kind) return null;
    const input = [first, second].find(node => node.kind === 'input');
    const output = [first, second].find(node => node.kind === 'output');
    const aux = [first, second].find(node => node.kind === 'aux');
    return { source: input ? input.index : aux.index, output: output ? output.index : 8 };
}

/** Toggle one bit of the latest reported destination mask. Linked outputs remain independent, as in Matrix. */
export function routingEdit(state, first, second) {
    const route = editableRoute(first, second);
    if (!state || !route) return null;
    const current = route.output === 8 ? state.aux?.select : state.outputs?.[route.output]?.route;
    if (!Number.isInteger(current)) return null;
    const mask = current ^ (1 << route.source);
    return {
        ...route, mask,
        path: route.output === 8 ? 'aux.select' : `outputs.${route.output}.route`,
        command: { command: 'routing', args: { channel: route.output === 8 ? 18 : 8 + route.output, mask } },
        enabled: Boolean(mask & (1 << route.source)),
    };
}

/** Node identifiers are separate from the graph's hover attributes, where Aux has both roles. */
export function routingEndpoint(value) {
    const [kind, raw] = (value ?? '').split(':');
    return { kind, index: raw === undefined || raw === '' ? NaN : Number(raw) };
}

/** Remove an existing edge only. A stale double-click must never create a route. */
export function routingRemoval(state, source, output) {
    const first = { kind: source === 8 ? 'aux' : 'input', index: source };
    const second = { kind: output === 8 ? 'aux' : 'output', index: output };
    const edit = routingEdit(state, first, second);
    return edit && !edit.enabled ? edit : null;
}
