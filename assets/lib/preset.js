import { t } from './i18n.js';
import { format } from './scale.js';
import { channelLabel } from './channels.js';
import { linkedMembers } from './link.js';

/** Read-only sections of a parsed file. Unknown settings never borrow values from the live device. */
export function presetSections(device, labels, riders = []) {
    const unknown = () => t('Not stored in this file');
    const value = (raw, fmt = 'raw') => null == raw ? unknown()
        : 'seconds' === fmt ? `${raw} s` : 'db' === fmt ? `${raw} dB` : format(fmt, raw, device, labels);
    const named = (raw, table) => null == raw ? unknown() : labels[table]?.[raw] ?? String(raw);
    const on = (raw) => null == raw ? unknown() : t(raw ? 'On' : 'Off');
    const mask = (raw, outputs = false, aux = false) => null == raw ? unknown()
        : Array.from({ length: aux ? 9 : 8 }, (_, i) => i === 8 ? 'Aux' : channelLabel(i + (outputs ? 8 : 0), device))
            .filter((_, i) => raw & (1 << i)).join(', ') || t('none');
    const rows = (entries) => entries.map(([label, raw, fmt]) => [t(label), value(raw, fmt)]);
    const dynamics = (data, name) => rows([
        [`${name} threshold`, data.threshold, 'threshold'], [`${name} attack`, data.attack, 'ms'],
        [`${name} release`, data.release, 'ms'],
    ]);
    const sections = [{ label: t('Preset'), rows: [
        [t('Name'), device.preset_name],
        [t('Test tone'), named(device.tone_mode, 'TONE_MODES')],
        [t('Test tone frequency'), named(device.tone_freq, 'TONE_FREQUENCIES')],
        [t('Delay unit'), named(device.delay_unit, 'DELAY_UNITS')],
    ] }];
    const channel = (data, index) => {
        const result = rows([['Gain', data.gain, 'gain']]);
        result.push([t('Mute'), on(data.mute)], [t('EQ Bypass'), on(data.eq_bypass)]);
        if (index < 16) {
            result.push([t('Phase'), null == data.phase ? unknown() : t(data.phase ? 'Inverse' : 'Normal')],
                [t('Link'), linkedMembers(index < 8 ? device.inputs : device.outputs, index % 8).length > 1
                    ? linkedMembers(index < 8 ? device.inputs : device.outputs, index % 8)
                        .map((i) => channelLabel(i + (index < 8 ? 0 : 8), device)).join(', ') : t('none')],
                ...['hpf', 'lpf'].flatMap((key) => [
                    [t(`${key.toUpperCase()} frequency`), value(data[key].freq, 'hz')],
                    [t(`${key.toUpperCase()} type`), named(data[key].slope, 'SLOPES')],
                ]));
        }
        if (index < 8) {
            result.push([t('Input mode'), named(data.mode, 'INPUT_MODES')], [t('+48V'), on(data.phantom)],
                [t('FBQ level'), named(data.fbq, 'FBQ_LEVELS')], ...dynamics(data.gate, 'Gate'));
        } else {
            result.push(...dynamics(data.comp, 'Compressor'), [t('Compressor ratio'), named(data.comp.ratio, 'COMP_RATIOS')]);
            if (index < 16) {
                // A .prs does not store a display unit. Milliseconds are an explicit fallback, never a live value.
                result.push([t('Delay'), null == device.delay_unit ? format('delay', data.delay) : value(data.delay, 'delay')],
                    [t('Sources'), mask(data.route, false, true)]);
            }
        }
        for (const [i, band] of data.peq.entries()) {
            const bandName = t('Band %band%', { band: i + 1 });
            result.push(...rows([['Frequency', band.freq, 'hz'], ['Q', band.q, 'q'], ['Gain', band.gain, 'peq']])
                .map(([key, val]) => [`${bandName} · ${key}`, val]),
            [`${bandName} · ${t('Type')}`, named(band.type, 'PEQ_TYPES')],
            [`${bandName} · ${t('Bypass')}`, on(band.bypass)]);
        }
        return { label: channelLabel(index, device), rows: result };
    };
    sections.push(...device.inputs.map((data, i) => channel(data, i)),
        ...device.outputs.map((data, i) => channel(data, i + 8)), channel(device.aux, 18));
    const aux = device.aux;
    const auxRows = [[t('Sources'), mask(aux.select)], [t('AutoMix slope'), named(aux.automix_slope, 'AUTOMIX_SLOPES')]];
    for (const [key, name] of [['automix', 'AutoMix'], ['camera', 'Camera']]) {
        const data = aux[key];
        auxRows.push([`${t(name)} · ${t('State')}`, on(data.on)], [`${t(name)} · ${t('Sources')}`, mask(data.select)],
            ...dynamics(data, name));
    }
    auxRows.push([t('Effect'), on(aux.effect.on)], ...rows([
        ['Effect gain', aux.effect.gain, 'raw'], ['Echo level', aux.effect.echo, 'raw'],
        ['Echo repeat', aux.effect.echo_repeat, 'raw'], ['Echo delay', aux.effect.echo_delay, 'plainMs'],
        ['Echo predelay', aux.effect.echo_predelay, 'plainMs'], ['Reverb level', aux.effect.reverb, 'raw'],
        ['Reverb predelay', aux.effect.rev_predelay, 'plainMs'], ['Reflect', aux.effect.reflect, 'raw'],
        ['Reverb time', aux.effect.time, 'reverbTime'],
    ]));
    sections.push({ label: t('Aux functions'), rows: auxRows });
    for (const [key, name] of [['sub', 'Sub Control'], ['groups', 'Group Control']]) {
        const controls = device[key] ?? [];
        const groupRows = controls.flatMap((data, i) => {
            const outputs = i >= ('sub' === key ? 1 : 4);
            const group = `${t(outputs ? 'Output' : 'Input')} ${1 + (i % ('sub' === key ? 1 : 4))}`;
            return [[`${group} · ${t('Gain')}`, value(data.gain, 'percent')],
                [`${group} · ${t('Mute')}`, on(data.mute)], [`${group} · ${t('Channels')}`, mask(data.mask, outputs)]];
        });
        sections.push({ label: t(name), rows: groupRows.length ? groupRows : [[t('State'), unknown()]] });
    }
    sections.push({ label: t('Gain riders'), rows: riders.length ? riders.flatMap((rider) => [
        [channelLabel(rider.channel, device), t('Target %target% dB, gain %min%..%max% dB, %slew% dB/s', rider)],
        ...rows([['Average time', rider.average, 'seconds'], ['Silence threshold', rider.silence, 'db'],
            ['Deadband', rider.deadband, 'db'], ['Override hold', rider.hold, 'seconds']])
            .map(([key, val]) => [`${channelLabel(rider.channel, device)} · ${key}`, val]),
        [t('Allow boosts'), on(rider.boost)],
    ]) : [[t('State'), t('none')]] });
    return sections;
}
