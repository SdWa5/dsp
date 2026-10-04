import { channelId } from './channels.js';
import { LINK_NAME_LENGTH, linkMark, linkNameKey, linkUnits } from './link.js';
import { t } from './i18n.js';

/** Shared browser-only group names. Rebuild on membership changes, never while typing into an unchanged group. Marks follow shared colour/icon preferences. Optional rows separate input/output groups. Title fields omit repeated member captions. */
export function renderLinkNames(container, empty, groupsBySide, view, action, rows = false, caption = true) {
    const key = JSON.stringify([groupsBySide, rows, caption]);
    if (container.dataset.namesKey !== key) {
        container.dataset.namesKey = key;
        const fields = [], sections = [];
        for (const side of ['inputs', 'outputs']) {
            const groups = groupsBySide[side] ?? [];
            const section = document.createElement('div'); section.className = 'link-names__row'; section.dataset.namesSide = side;
            const heading = t(side === 'inputs' ? 'Input' : 'Output');
            section.setAttribute('role', 'group'); section.setAttribute('aria-label', heading);
            const title = document.createElement('strong'); title.className = 'link-names__heading'; title.textContent = heading;
            section.append(title);
            for (const unit of linkUnits(groups).filter(unit => unit.length > 1)) {
                const group = groups[unit[0]];
                const channels = unit.map(i => channelId((side === 'outputs' ? 8 : 0) + i)).join(', ');
                const label = document.createElement('label'); label.className = 'link-name';
                label.dataset.linkGroup = String(group);
                const mark = document.createElement('span'); mark.className = 'link-name__mark';
                mark.setAttribute('aria-hidden', 'true');
                const input = document.createElement('input'); input.type = 'text'; input.className = 'text';
                input.maxLength = LINK_NAME_LENGTH; input.placeholder = `L${group}`;
                input.dataset.key = linkNameKey(side, groups, group);
                input.dataset.action = action;
                input.setAttribute('aria-label', t('Name of the group %channels%', { channels }));
                label.title = input.title = t('Browser-only name for %channels%. Up to %length% characters. Leave empty to use the group code.', { channels, length: LINK_NAME_LENGTH });
                label.append(mark, ...(caption ? [channels] : []), input); fields.push(label); section.append(label);
            }
            if (section.querySelector('.link-name')) sections.push(section);
        }
        container.replaceChildren(...(rows ? sections : fields));
        empty.hidden = fields.length > 0;
    }
    for (const label of container.querySelectorAll('.link-name')) {
        label.dataset.group = view.colour ? label.dataset.linkGroup : '';
        const mark = label.querySelector('.link-name__mark');
        mark.innerHTML = linkMark(view, Number(label.dataset.linkGroup));
        mark.hidden = !view.colour && !view.code && !view.shape;
    }
    for (const input of container.querySelectorAll('input')) {
        if (input !== document.activeElement) input.value = view.names?.[input.dataset.key] ?? '';
    }
}
