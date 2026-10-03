import { Controller } from '@hotwired/stimulus';
import { store } from '../lib/device.js';
import { channelId } from '../lib/channels.js';
import { t } from '../lib/i18n.js';
import { linkGaps, linkGroups, linkMark, linkName, linkPlacement, linkRuns } from '../lib/link.js';
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
 * and stacked leaves a gap around every group and heads it with one bar outside the channel headers, which then
 * leave out their own marks.
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
            this.placeTable(groups, placement, view);
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
            // Caps a block of strips at the width its members can fill
            block.style.setProperty('--members', String(members.length));
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

    placeTable(groups, placement, view) {
        const table = this.element;
        // The group bars of the last placement go first, so every row holds its channel cells only
        table.querySelectorAll('.matrix__groups, th.matrix__group').forEach((node) => node.remove());
        table.querySelectorAll('th.is-grouped').forEach((th) => th.classList.remove('is-grouped'));
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
        if ('stacked' === placement) {
            this.headRows(rows, rowOf, groups, rowSide, view);
            this.headColumns(groups, columnSide, view);
        }
    }

    /** A bar cell for one run: the group mark over its members, or an empty cell beside a channel alone. */
    groupBar(run, side, groups, view) {
        const bar = document.createElement('th');
        bar.className = 'matrix__group' + (run.group > 0 ? '' : ' is-empty');
        if (run.group > 0) {
            bar.dataset.group = view.colour ? String(run.group) : '';
            // The bar stands for the whole group in the Matrix page's hover (routefocus_controller.js)
            bar.dataset['inputs' === side ? 'sources' : 'outputs'] = run.channels.join(' ');
            bar.title = t('Linked: %channels%', { channels: run.channels.map((i) => channelId(('outputs' === side ? 8 : 0) + i)).join(', ') });
            bar.innerHTML = linkMark(view, run.group, linkName(view, side, groups[side], run.group));
        }
        return bar;
    }

    /**
     * A column of bars on the outer side of the row headers: right of them while the outputs are the rows, left of
     * them while the inputs are. The header rows and a row without a link channel (the Aux) get an empty cell there.
     */
    headRows(rows, rowOf, groups, side, view) {
        const table = this.element;
        // Rows end with their header while the outputs are the rows, and start with it while the inputs are
        const rowsLast = rows[0].lastElementChild?.matches('th[scope=row]');
        const outer = (row, cell) => (rowsLast ? row.append(cell) : row.prepend(cell));
        const byChannel = new Map(rows.map((row) => [rowOf(row) % 8, row]));
        for (const run of linkRuns(groups[side], 'stacked')) {
            const bar = this.groupBar(run, side, groups, view);
            bar.rowSpan = run.channels.length;
            outer(byChannel.get(run.channels[0]), bar);
            run.channels.forEach((i) => byChannel.get(i).querySelector('th[scope=row]').classList.toggle('is-grouped', run.group > 0));
        }
        const others = [...table.tBodies[0].rows].filter((row) => !rows.includes(row));
        for (const row of [...(table.tHead?.rows ?? []), ...others, ...(table.tFoot?.rows ?? [])]) {
            const empty = document.createElement('th');
            empty.className = 'matrix__group is-empty';
            outer(row, empty);
        }
    }

    /** A row of bars on the outer side of the column headers: above them at the top, below them at the bottom. */
    headColumns(groups, side, view) {
        const table = this.element;
        const header = table.tHead?.rows[0] ?? table.tFoot.rows[0];
        const all = linkRuns(groups[side], 'stacked');
        const runs = new Map(all.map((run) => [run.channels[0], run]));
        const grouped = new Set(all.filter((run) => run.group > 0).flatMap((run) => run.channels));
        const line = document.createElement('tr');
        line.className = 'matrix__groups';
        for (const cell of header.cells) {
            const column = undefined === cell.dataset.linkColumn ? -1 : Number(cell.dataset.linkColumn) % 8;
            if (column < 0) {
                const empty = document.createElement('th');
                empty.className = 'matrix__group is-empty';
                line.append(empty);
                continue;
            }
            cell.classList.toggle('is-grouped', grouped.has(column));
            if (!runs.has(column)) {
                continue;
            }
            const run = runs.get(column);
            const bar = this.groupBar(run, side, groups, view);
            bar.colSpan = run.channels.length;
            bar.classList.toggle('is-unit-start', cell.classList.contains('is-unit-start'));
            line.append(bar);
        }
        table.tHead ? header.before(line) : header.after(line);
    }
}
