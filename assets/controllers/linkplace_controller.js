import { Controller } from '@hotwired/stimulus';
import { store } from '../lib/device.js';
import { channelId } from '../lib/channels.js';
import { t } from '../lib/i18n.js';
import { linkGaps, linkGroups, linkMark, linkName, linkPlacement } from '../lib/link.js';
import { getLinkView, onLinkView } from '../lib/linkview.js';

/** The channel index (0..15) a node shows through its first channel label, -1 for none or the Aux. */
const channelOf = (node) => {
    const label = node.matches('.ch') ? node : node.querySelector('.ch');
    const index = label ? Number(label.dataset.channelIndexValue) : -1;
    return index >= 0 && index < 16 ? index : -1;
};

/**
 * Places the channels of one section by the link placement of the Channels menu, as on the channel tabs.
 * An item is a child with a channel label (a strip, a switch, a route button), its side follows from the label.
 * In channel order nothing moves. Side by side puts the members of a link group next to each other, and stacked
 * also gathers them in a block with one header that carries the group's code, icon and colour. Strips are too
 * tall to stack, so their block runs across. In a table (the matrix), the rows and the columns follow the order,
 * and stacked leaves a gap around every group.
 */
export default class extends Controller {
    connect() {
        this.shown = null;
        if ('TABLE' === this.element.tagName) {
            this.tagColumns();
        }
        this.offs = [store.subscribe(() => this.place()), onLinkView(() => this.place())];
        this.place();
    }

    disconnect() {
        this.offs.forEach((off) => off());
    }

    place() {
        const state = store.state;
        const view = getLinkView();
        const groups = { inputs: linkGroups(state?.inputs ?? []), outputs: linkGroups(state?.outputs ?? []) };
        const key = JSON.stringify([groups, view]);
        if (key === this.shown) {
            return;
        }
        this.shown = key;
        const placement = null === state ? 'normal' : view.placement;
        if ('TABLE' === this.element.tagName) {
            this.placeTable(groups, placement);
        } else {
            this.placeItems(groups, placement, view);
        }
    }

    placeItems(groups, placement, view) {
        // Blocks from before go first, so their items are children again
        for (const block of this.element.querySelectorAll(':scope > .link-block')) {
            block.querySelector(':scope > .link-block__head')?.remove();
            block.replaceWith(...block.children);
        }
        const items = [...this.element.children].filter((node) => channelOf(node) >= 0);
        if (0 === items.length) {
            return;
        }
        const index = channelOf(items[0]);
        const side = index < 8 ? 'inputs' : 'outputs';
        const { order, blocks } = linkPlacement(groups[side], placement);
        const byChannel = new Map(items.map((node) => [channelOf(node) % 8, node]));
        const anchor = items[items.length - 1].nextSibling;
        for (const i of order) {
            if (byChannel.has(i)) {
                this.element.insertBefore(byChannel.get(i), anchor);
            }
        }
        for (const unit of blocks) {
            const members = unit.map((i) => byChannel.get(i)).filter(Boolean);
            const group = groups[side][unit[0]];
            const block = document.createElement('div');
            block.className = 'link-block';
            block.dataset.group = view.colour ? String(group) : '';
            const head = document.createElement('span');
            head.className = 'link-block__head';
            head.title = t('Linked: %channels%', { channels: unit.map((i) => channelId(('outputs' === side ? 8 : 0) + i)).join(', ') });
            head.innerHTML = linkMark(view, group, linkName(view, side, groups[side], group));
            members[0].before(block);
            block.append(head, ...members);
        }
    }

    /** Every cell of a column gets the channel of its column header, so the columns can move as a whole. */
    tagColumns() {
        const header = this.element.tHead?.rows[0] ?? this.element.tFoot?.rows[0];
        const channels = [...(header?.cells ?? [])].map(channelOf);
        for (const row of this.element.rows) {
            [...row.cells].forEach((cell, position) => {
                if (channels[position] >= 0) {
                    cell.dataset.linkColumn = String(channels[position]);
                }
            });
        }
    }

    placeTable(groups, placement) {
        const table = this.element;
        const columnSide = Number(table.querySelector('[data-link-column]')?.dataset.linkColumn) < 8 ? 'inputs' : 'outputs';
        const columns = linkPlacement(groups[columnSide], placement);
        const columnStarts = linkGaps(groups[columnSide], placement);
        for (const row of table.rows) {
            const cells = [...row.cells].filter((cell) => undefined !== cell.dataset.linkColumn);
            if (0 === cells.length) {
                continue;
            }
            const anchor = cells[cells.length - 1].nextSibling;
            const byChannel = new Map(cells.map((cell) => [Number(cell.dataset.linkColumn) % 8, cell]));
            for (const i of columns.order) {
                row.insertBefore(byChannel.get(i), anchor);
            }
            cells.forEach((cell) => cell.classList.toggle('is-unit-start', columnStarts.has(Number(cell.dataset.linkColumn) % 8)));
        }
        const body = table.tBodies[0];
        const rowOf = (row) => {
            const head = row.querySelector('th[scope=row]');
            return head ? channelOf(head) : -1;
        };
        const rows = [...body.rows].filter((row) => rowOf(row) >= 0);
        if (0 === rows.length) {
            return;
        }
        const rowSide = rowOf(rows[0]) < 8 ? 'inputs' : 'outputs';
        const anchor = rows[rows.length - 1].nextSibling;
        const byChannel = new Map(rows.map((row) => [rowOf(row) % 8, row]));
        for (const i of linkPlacement(groups[rowSide], placement).order) {
            body.insertBefore(byChannel.get(i), anchor);
        }
        const rowStarts = linkGaps(groups[rowSide], placement);
        rows.forEach((row) => row.classList.toggle('is-unit-start', rowStarts.has(rowOf(row) % 8)));
    }
}
