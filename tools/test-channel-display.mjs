/** Browser regression checks use isolated state and intercept every API request, including writes. */
import assert from 'node:assert/strict';
import { checkEqOverview } from './check-eq-overview.mjs';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const browser = await chromium.launch({ headless: true });
const url = process.env.TRACKDSP_TEST_URL || 'https://trackdsp.ddev.site';
const results = [];
// Cover static controls and the panel/name/group controls generated after connection.
async function assertDisplayTooltips(settings) {
    const missing = await settings.locator('input, select, button, h1, h3').evaluateAll(nodes => nodes
        .filter(node => !node.title.trim() || /editor\.tip\.|%\w+%/.test(node.title))
        .map(node => node.outerHTML));
    assert.deepEqual(missing, []);
    const panels = settings.locator('[data-displaysettings-target=panels] input');
    assert.ok(await panels.count() > 20);
    assert.ok(await panels.evaluateAll(nodes => nodes.every(node => node.title.includes(node.closest('label').textContent))));
    assert.ok(await settings.locator('.link-name input').count() > 0);
    assert.ok(await settings.locator('.link-name input').evaluateAll(nodes => nodes.every(node => node.title.includes('12'))));
    assert.ok(await settings.locator('.group-selector input').count() > 0);
}
try {
    for (const width of [1440, 390]) {
        const page = await browser.newPage({ ignoreHTTPSErrors: true, viewport: { width, height: 1000 } });
        const writes = [], errors = [];
        page.on('pageerror', e => errors.push(e.message));
        await page.route('**/api/**', async route => {
            if (route.request().method() !== 'GET') writes.push({ url: route.request().url(), body: route.request().postDataJSON() });
            if (route.request().url().endsWith('/status')) { await route.fulfill({ json: { connected: true, target: 'mock', riders: [], queued: 0, resync: null } }); return; }
            if (route.request().url().endsWith('/state')) { await route.fulfill({ json: await page.evaluate(async () => {
                const asset = performance.getEntriesByType('resource').find(entry => /\/assets\/lib\/device-[^/]+\.js/.test(entry.name));
                const device = await import(asset.name); return structuredClone(device.store.state);
            }) }); return; }
            await route.fulfill({ json: route.request().url().endsWith('/autoeq') ? { session: null, devices: [], backups: [], recorder: false, device: '' } : {} });
        });
        await page.addInitScript(() => {
            window.EventSource = class extends EventTarget {
                constructor() {
                    super(); window.mockEvents = this;
                    setTimeout(() => {
                        window.mockState = JSON.parse(document.getElementById('trackdsp-defaults').textContent);
                        window.mockState.inputs.forEach(channel => channel.link = 0);
                        window.mockState.outputs.forEach(channel => channel.link = 0);
                        window.mockState.inputs[0].link = 5;
                        window.mockState.outputs[1].link = 10;
                        window.mockState.aux.select = 1 | (1 << 7);
                        window.mockState.outputs[1].route = 1 | (1 << 8) | (1 << 12);
                        window.mockState.outputs[3].route = 4 | (1 << 7);
                        this.dispatchEvent(new MessageEvent('status', { data: JSON.stringify({ connected: true, bridge: true, demo: true, target: 'mock', riders: [] }) }));
                        this.dispatchEvent(new MessageEvent('state', { data: JSON.stringify(window.mockState) }));
                    }, 20);
                }
                close() {}
            };
        });
        await page.goto(url + '/#gain');
        await page.waitForFunction(() => document.querySelector('[data-fader-bind-value="inputs.0.gain"] input').disabled === false);
        const tabs = page.locator('.chtab[data-side]:visible');
        assert.equal(await tabs.count(), 16);
        const preferences = async (hidden, grouped) => {
            await page.evaluate(({ hidden, grouped }) => {
                const dialog = document.querySelector('section[data-page=display]');
                dialog.querySelectorAll('[data-channelview-target=channel]').forEach(box => box.checked = !hidden.includes(Number(box.value)));
                const box = dialog.querySelector('[data-channelview-target=grouped]');
                box.checked = grouped; box.dispatchEvent(new Event('change', { bubbles: true }));
            }, { hidden, grouped });
        };
        const go = async name => { await page.evaluate(name => document.querySelector(`[data-editor-page-param="${name}"]`).click(), name); await page.waitForFunction(name => !document.querySelector(`section[data-page="${name}"]`).hidden || !document.querySelector('[data-editor-target=channelWarning]').hidden, name); };
        const inputStrips = page.locator('[data-collapse-key-value="gain.input"] .strip:visible');
        const memberSwitch = page.locator('.eq-overview__switch input[value="inputs.2"]');
        await memberSwitch.uncheck();
        await preferences([], true);
        await page.waitForFunction(() => document.querySelectorAll('.chtab[data-side]:not([hidden])').length === 14);
        // Representatives must name every member, including hidden members and live member renames.
        const namedInputTab = page.locator('.chtab--inputs[data-index="0"]:visible');
        const namedOutputTab = page.locator('.chtab--outputs[data-index="1"]:visible');
        const namedInputSwitch = page.locator('.eq-overview__switch--inputs:visible').filter({ has: page.locator('input[value="inputs.0"]') });
        const namedOutputSwitch = page.locator('.eq-overview__switch--outputs:visible').filter({ has: page.locator('input[value="outputs.1"]') });
        await page.evaluate(() => {
            window.mockState.inputs[0].name = 'DJ Left'; window.mockState.inputs[2].name = 'DJ Right';
            window.mockState.outputs[1].name = 'Main L'; window.mockState.outputs[3].name = 'Main R';
            window.mockEvents.dispatchEvent(new MessageEvent('state', { data: JSON.stringify(window.mockState) }));
        });
        for (const [tab, control, names, ids] of [[namedInputTab, namedInputSwitch, 'DJ Left, DJ Right', 'In1, In3'], [namedOutputTab, namedOutputSwitch, 'Main L, Main R', 'Out2, Out4']]) {
            assert.equal(await tab.locator('.chtab__name').textContent(), names);
            assert.equal(await control.locator('.ch__name').textContent(), names);
            assert.equal(await control.locator('.ch__id').textContent(), ids);
            assert.equal(await control.locator('.ch__id').count(), 1);
            assert.equal(await control.locator('.channel-members').count(), 0);
            assert.equal(await control.locator('.ch').evaluate(node => node.firstElementChild.className), 'ch__id');
        }
        await page.evaluate(() => {
            window.mockState.inputs[2].name = 'DJ New';
            window.mockEvents.dispatchEvent(new MessageEvent('state', { data: JSON.stringify(window.mockState) }));
        });
        assert.equal(await namedInputTab.locator('.chtab__name').textContent(), 'DJ Left, DJ New');
        assert.equal(await namedInputSwitch.locator('.ch__name').textContent(), 'DJ Left, DJ New');
        assert.ok((await namedInputTab.getAttribute('title')).includes('In3 (DJ New)'));
        assert.ok((await namedInputSwitch.locator('.ch').getAttribute('title')).includes('In3 (DJ New)'));
        await preferences([0], true);
        assert.equal(await page.locator('.chtab--inputs[data-index="2"]:visible .chtab__name').textContent(), 'DJ Left, DJ New');
        const hiddenLeaderSwitch = page.locator('.eq-overview__switch--inputs:visible input[value="inputs.2"]').locator('..');
        assert.equal(await hiddenLeaderSwitch.locator('.ch__name').textContent(), 'DJ Left, DJ New');
        assert.equal(await hiddenLeaderSwitch.locator('.ch__id').textContent(), 'In1, In3');
        assert.equal(await hiddenLeaderSwitch.locator('.channel-members').count(), 0);
        await preferences([], false);
        assert.equal(await namedInputTab.locator('.chtab__name').textContent(), 'DJ Left');
        assert.equal(await namedInputSwitch.locator('.ch__name').textContent(), 'DJ Left');
        assert.equal(await namedInputSwitch.locator('.ch__id').textContent(), 'In1');
        await preferences([], true);
        // The group-name input is the title; member IDs occur once next to individual name fields.
        for (const [channel, id, members] of [
            ['inputs0', 'In1', [0, 2]], ['inputs2', 'In3', [0, 2]],
            ['outputs1', 'Out2', [9, 11]], ['outputs3', 'Out4', [9, 11]],
            ['inputs1', 'In2', [1]], ['aux', 'Aux', [18]],
        ]) {
            await go(channel);
            const heading = page.locator(`section[data-page=${channel}] .channel-page-heading`);
            const grouped = members.length > 1;
            const linkField = heading.locator('.page__title .channel-link-name input');
            assert.equal(await heading.locator('.page__title .ch__name').count(), 0);
            assert.equal(await heading.locator('.page__title .ch__id').isVisible(), !grouped);
            assert.equal(await linkField.count(), grouped ? 1 : 0);
            assert.deepEqual(await heading.locator('.channel-name-edit:visible input').evaluateAll(nodes => nodes.map(node => node.dataset.textBindValue)), members.map(index => index === 18 ? 'aux.name' : `${index < 8 ? 'inputs' : 'outputs'}.${index % 8}.name`));
            if (grouped) {
                assert.equal(await linkField.getAttribute('maxlength'), '12');
                assert.equal(await linkField.getAttribute('placeholder'), 'L1');
                assert.ok((await linkField.getAttribute('title')).includes('Browser-only'));
                assert.equal(await linkField.locator('..').textContent(), '');
                assert.deepEqual(await heading.locator('.channel-name-id:visible').allTextContents(), members.map(index => (index < 8 ? 'In' : 'Out') + (index % 8 + 1)));
                const titleBox = await heading.locator('.page__title').boundingBox(), fieldsBox = await heading.locator('.channel-member-names').boundingBox();
                assert.ok(fieldsBox.y >= titleBox.y + titleBox.height);
            } else assert.equal(await heading.locator('.page__title .ch__id').textContent(), id);
        }
        const groupNameField = page.locator('[data-displaysettings-target=names] input[data-key="inputs:0"]');
        await groupNameField.evaluate(input => { input.value = 'DJ Group'; input.dispatchEvent(new Event('input', { bubbles: true })); });
        await preferences([0], true);
        await go('inputs2');
        const detailHeading = page.locator('section[data-page=inputs2] .channel-page-heading');
        const detailTitle = detailHeading.locator('.page__title');
        const pageLinkField = detailTitle.locator('.channel-link-name input');
        assert.equal(await pageLinkField.inputValue(), 'DJ Group');
        assert.equal(await detailTitle.locator('.ch__id:visible').count(), 0);
        assert.deepEqual(await detailHeading.locator('.channel-name-id:visible').allTextContents(), ['In1', 'In3']);
        await pageLinkField.fill('Page Group');
        await pageLinkField.dispatchEvent('input');
        assert.equal(await detailTitle.getAttribute('aria-label'), 'Page Group');
        assert.equal(await groupNameField.inputValue(), 'Page Group');
        assert.equal(await page.locator('section[data-page=inputs0] .channel-link-name input').inputValue(), 'Page Group');
        assert.equal(await pageLinkField.evaluate(input => document.activeElement === input), true);
        await page.locator('[data-editor-dialog-param=link]').first().evaluate(button => button.click());
        assert.equal(await page.locator('#dialog-link input[data-key="inputs:0"]').inputValue(), 'Page Group');
        // Group names keep all input fields together above the separate output row.
        const linkNames = page.locator('#dialog-link [data-editor-target=linkNames]');
        assert.deepEqual(await linkNames.locator('.link-names__row').evaluateAll(rows => rows.map(row => row.dataset.namesSide)), ['inputs', 'outputs']);
        assert.equal(await linkNames.evaluate(node => getComputedStyle(node).flexDirection), 'column');
        for (const [side, heading] of [['inputs', 'Input'], ['outputs', 'Output']]) {
            const row = linkNames.locator(`[data-names-side="${side}"]`);
            assert.equal(await row.getAttribute('aria-label'), heading);
            assert.ok(await row.locator('input').count() > 0);
            assert.ok(await row.locator('input').evaluateAll((inputs, side) => inputs.every(input => input.dataset.key.startsWith(side + ':')), side));
        }
        const inputNamesBox = await linkNames.locator('[data-names-side=inputs]').boundingBox();
        const outputNamesBox = await linkNames.locator('[data-names-side=outputs]').boundingBox();
        assert.ok(outputNamesBox.y >= inputNamesBox.y + inputNamesBox.height);
        await linkNames.screenshot({ path: `/tmp/trackdsp-link-group-name-rows-${width}.png` });
        // Link matrix: aligned cells, gaps around groups only, and the corner cycling lower half, upper half, full matrix.
        const linkPlacementBefore = await page.locator('#dialog-link input[data-editor-target=linkPlacement]:checked').getAttribute('value');
        const inputMatrix = page.locator('#dialog-link table[data-side=inputs]');
        const matrixLayout = () => inputMatrix.evaluate(table => {
            const head = table.tHead.rows[0];
            const rows = [...table.tBodies[0].rows];
            // A stacked group merges its row headers into its first row
            const rowTh = rows.map(row => row.querySelector('th[scope=row]')).find(th => !th.hidden);
            const cell = rowTh.parentElement.querySelector('td');
            return {
                layout: table.dataset.layout,
                cells: table.querySelectorAll('td:not(.is-masked) .link-cell:not(:disabled)').length,
                headBelow: head.getBoundingClientRect().top > table.tBodies[0].getBoundingClientRect().top,
                rowHeadRight: rowTh.getBoundingClientRect().left > cell.getBoundingClientRect().left,
                misaligned: rows.filter(row => new Set([...row.querySelectorAll('td:not(.is-masked) .link-cell:not(:disabled)')]
                    .map(button => Math.round(button.getBoundingClientRect().top))).size > 1).length,
                gaps: [...head.querySelectorAll('th.is-unit-start')].map(th => Number(th.dataset.index)),
                // A header at a gap starts where its column's buttons start
                gapOffsets: [...head.querySelectorAll('th.is-unit-start')].map(th => Math.round(th.getBoundingClientRect().left + parseFloat(getComputedStyle(th).borderLeftWidth)
                    - table.querySelector(`tbody td[data-index="${th.dataset.index}"] .link-cell`).getBoundingClientRect().left)),
            };
        });
        const expectedLayouts = { lower: [28, true, false], upper: [28, false, true], full: [56, false, false] };
        for (const layout of ['lower', 'upper', 'full']) {
            for (const placement of ['normal', 'side', 'stacked']) {
                await page.locator(`#dialog-link input[data-editor-target=linkPlacement][value=${placement}]`).check();
                const seen = await matrixLayout();
                assert.deepEqual([seen.layout, seen.cells, seen.headBelow, seen.rowHeadRight], [layout, ...expectedLayouts[layout]]);
                assert.equal(seen.misaligned, 0);
                // In1 + In3 stacked: a gap before In2 only, never between channels alone
                assert.deepEqual(seen.gaps, placement === 'stacked' ? [1] : []);
                assert.ok(seen.gapOffsets.every(offset => Math.abs(offset) <= 1));
            }
            await inputMatrix.locator('.matrix__corner').click();
            assert.equal(await inputMatrix.locator('.matrix__corner').evaluate(button => document.activeElement === button), true);
        }
        assert.equal(await inputMatrix.getAttribute('data-layout'), 'lower');
        // Display Settings holds the same preference as the corner button, in both directions
        const displayLayout = () => page.evaluate(() => document.querySelector('section[data-page=display] input[name=link-matrix-layout]:checked')?.value);
        assert.equal(await displayLayout(), 'lower');
        await inputMatrix.locator('.matrix__corner').click();
        assert.equal(await displayLayout(), 'upper');
        await page.evaluate(() => {
            const radio = document.querySelector('section[data-page=display] input[name=link-matrix-layout][value=full]');
            radio.checked = true; radio.dispatchEvent(new Event('change', { bubbles: true }));
        });
        assert.equal(await inputMatrix.getAttribute('data-layout'), 'full');
        await inputMatrix.locator('.matrix__corner').click();
        assert.equal(await inputMatrix.getAttribute('data-layout'), 'lower');
        // Pointing at an empty cell fades every channel that a click there would not change
        await page.locator(`#dialog-link input[data-editor-target=linkPlacement][value=normal]`).check();
        await inputMatrix.locator('.link-cell[data-row="3"][data-column="1"]').hover();
        assert.deepEqual(await inputMatrix.locator('th.is-focus').evaluateAll(nodes => nodes.map(th => th.dataset.index).sort()), ['1', '1', '3', '3']);
        assert.equal(await page.locator('#dialog-link .link-matrices').evaluate(box => box.classList.contains('has-link-focus')), true);
        await page.locator('#dialog-link .dialog__note').hover();
        assert.equal(await page.locator('#dialog-link .link-matrices').evaluate(box => box.classList.contains('has-link-focus')), false);
        await page.locator(`#dialog-link input[data-editor-target=linkPlacement][value=${linkPlacementBefore}]`).check();
        await page.locator('#dialog-link .dialog__close').click();
        assert.equal(writes.length, 0);
        await detailHeading.screenshot({ path: `/tmp/trackdsp-group-detail-title-${width}.png` });
        // Editing a hidden group member renames that member alone through its existing command.
        const memberName = detailHeading.locator('[data-text-bind-value="inputs.0.name"]');
        await memberName.fill('Header L'); await memberName.dispatchEvent('change');
        assert.equal(writes.length, 1);
        assert.deepEqual(writes[0].body.commands, [{ command: 'channelName', args: { channel: 0, name: 'Header L' } }]);
        writes.length = 0;
        await page.evaluate(() => { window.mockState.inputs[0].link = 255; window.mockEvents.dispatchEvent(new MessageEvent('state', { data: JSON.stringify(window.mockState) })); });
        assert.deepEqual(await detailHeading.locator('.channel-name-id:visible').allTextContents(), ['In1', 'In2', 'In3', 'In4', 'In5', 'In6', 'In7', 'In8']);
        assert.equal(await detailHeading.locator('.channel-name-edit:visible input').count(), 8);
        await page.evaluate(() => { window.mockState.inputs[0].link = 5; window.mockEvents.dispatchEvent(new MessageEvent('state', { data: JSON.stringify(window.mockState) })); });
        await preferences([], false);
        assert.equal(await detailTitle.locator('.ch__id:visible').textContent(), 'In3');
        assert.equal(await pageLinkField.isVisible(), true);
        assert.equal(await detailHeading.locator('.channel-name-edit:visible input').count(), 1);
        assert.equal(await detailHeading.locator('.channel-name-id:visible').count(), 0);
        assert.equal(await detailHeading.locator('[data-text-bind-value="inputs.2.name"]').inputValue(), 'DJ New');
        await groupNameField.evaluate(input => { input.value = ''; input.dispatchEvent(new Event('input', { bubbles: true })); });
        await preferences([], true);
        assert.equal(await pageLinkField.getAttribute('placeholder'), 'L1');
        assert.equal(await pageLinkField.inputValue(), '');
        await page.evaluate(() => { window.mockState.inputs[0].link = 0; window.mockEvents.dispatchEvent(new MessageEvent('state', { data: JSON.stringify(window.mockState) })); });
        assert.equal(await detailTitle.locator('.channel-link-name').isVisible(), false);
        assert.equal(await detailTitle.locator('.ch__id:visible').textContent(), 'In3');
        assert.equal(await detailHeading.locator('.channel-name-edit:visible input').count(), 1);
        await page.evaluate(() => { window.mockState.inputs[0].link = 5; window.mockEvents.dispatchEvent(new MessageEvent('state', { data: JSON.stringify(window.mockState) })); });
        assert.equal(await pageLinkField.isVisible(), true);
        assert.equal(await detailTitle.locator('.ch__id:visible').count(), 0);
        await go('gain');
        await page.locator('[data-editor-target=chtabs]').first().screenshot({ path: `/tmp/trackdsp-group-names-tabs-${width}.png` });
        await page.locator('.eq-overview__switches').screenshot({ path: `/tmp/trackdsp-group-names-overview-${width}.png` });
        await page.evaluate(() => {
            for (const [side, indices] of [['inputs', [0, 2]], ['outputs', [1, 3]]]) {
                for (const index of indices) window.mockState[side][index].name = (side === 'inputs' ? 'In' : 'Out') + (index + 1);
            }
            window.mockEvents.dispatchEvent(new MessageEvent('state', { data: JSON.stringify(window.mockState) }));
        });

        assert.equal(await inputStrips.count(), 7);
        assert.equal(await page.locator('.eq-overview__switch:visible').count(), 15);
        const overviewGroup = page.locator('.eq-overview__switch input[value="inputs.0"]');
        assert.equal(await overviewGroup.evaluate(box => box.indeterminate), true);
        await overviewGroup.check();
        await page.waitForFunction(() => !JSON.parse(localStorage.getItem('trackdsp-eq-overview-hidden')).includes('inputs.2'));
        const incoming = page.locator('section[data-page=gain] .aux-routes__column').first();
        const aggregate = incoming.locator('.group-selector input');
        assert.equal(await aggregate.count(), 1);
        assert.equal(await aggregate.evaluate(box => box.indeterminate), true);
        await aggregate.check();
        await page.waitForFunction(() => document.querySelector('section[data-page=gain] [data-toggle-bind-value="aux.select"][data-toggle-bit-value="2"]').classList.contains('is-on'));
        assert.equal(writes.length, 1);
        assert.deepEqual(writes[0].body.commands, [{ command: 'routing', args: { channel: 18, mask: 133 } }]);
        const outgoing = page.locator('section[data-page=gain] .aux-routes__column').nth(1);
        await outgoing.locator('.group-selector input').check();
        assert.equal(writes.length, 3);
        assert.deepEqual(writes.slice(1).map(write => write.body.commands[0]), [
            { command: 'routing', args: { channel: 9, mask: 4353 } },
            { command: 'routing', args: { channel: 11, mask: 388 } },
        ]);
        writes.length = 0;
        // A changed group can retain its number; representative labels must still refresh membership.
        for (const mask of [9, 5]) {
            await page.evaluate(mask => {
                window.mockState.inputs[0].link = mask;
                window.mockEvents.dispatchEvent(new MessageEvent('state', { data: JSON.stringify(window.mockState) }));
            }, mask);
            const summary = page.locator('[data-collapse-key-value="gain.input"] [data-channel-index-value="0"] .ch__id');
            assert.equal(await summary.textContent(), mask === 9 ? 'In1, In4' : 'In1, In3');
        }
        await preferences([0, 15], true);
        assert.equal(await tabs.count(), 13);
        assert.equal(await inputStrips.count(), 7);
        assert.equal(await page.locator('section[data-page=gain] .strip:visible [data-fader-bind-value="inputs.2.gain"]').count(), 1);
        assert.ok((await incoming.locator('.group-selector').getAttribute('title')).includes('Hidden members: In1'));
        await go('inputs0');
        const warning = page.locator('[data-editor-target=channelWarning]');
        assert.equal(await warning.isVisible(), true);
        await page.reload();
        await warning.waitFor({ state: 'visible' });
        await page.locator('[data-editor-target=channelWarning] [data-editor-page-param=gain]').click();
        assert.equal(await warning.isVisible(), false);
        await go('inputs0');
        assert.equal(await page.locator('section[data-page=inputs0]').isVisible(), false);
        await page.locator('[data-action="editor#unhideChannel"]').click();
        assert.equal(await warning.isVisible(), false);
        assert.equal(await page.locator('section[data-page=inputs0]').isVisible(), true);
        await go('inputs2');
        assert.equal(await page.locator('.chtab[data-side=inputs][data-index="0"]').getAttribute('aria-selected'), 'true');
        assert.equal(await page.locator('.chtab[data-side].is-active').count(), 1);
        await preferences([0, 15], true);
        await go('matrix');
        for (const swapped of [0, 1]) {
            if (swapped) await page.locator('.matrix:visible .matrix__corner').click();
            assert.equal(await page.locator('.matrix:visible .toggle--cell:visible').count(), 63);
            assert.equal(await page.locator('.matrix:visible .matrix__unsupported:visible').count(), 1);
        }
        assert.equal(await page.locator('.routing__node').count(), 15);
        assert.equal(await page.locator('.routing__node[data-source="0"]').count(), 0);
        assert.equal(await page.locator('.routing__edge[data-source="0"], .routing__edge[data-output="7"]').count(), 0);
        await page.locator('.routing__node[data-aux]').hover();
        assert.equal(await page.locator('.matrix-page.has-route-focus').count(), 1);
        await preferences(Array.from({ length: 16 }, (_, i) => i), true);
        assert.equal(await page.locator('.matrix-page.has-route-focus').count(), 0);
        assert.equal(await tabs.count(), 0);
        assert.equal(await page.locator('.routing__node').count(), 1);
        assert.equal(await page.locator('.routing__edge').count(), 0);
        assert.equal(await page.locator('.matrix:visible .toggle--cell:visible').count(), 0);
        await go('gate');
        await page.waitForFunction(() => document.querySelector('section[data-page=gate] canvas').getAttribute('aria-label') === 'No visible input channels.');
        await go('comp');
        await page.waitForFunction(() => document.querySelector('section[data-page=comp] [data-dynamics-follow-value]').getAttribute('data-dynamics-path-value') === 'aux');
        await go('autoeq');
        assert.equal(await page.locator('section[data-page=autoeq] input[name=outputs]').count(), 8);
        const formGroup = page.locator('section[data-page=autoeq] .group-selector input');
        await formGroup.check();
        assert.equal(await page.locator('section[data-page=autoeq] input[name=outputs]:checked').count(), 3);
        assert.equal(await formGroup.getAttribute('name'), null);
        // Aux visibility applies to both axes, the graph, processing strips and its detail URL.
        await preferences([18], true);
        await go('matrix');
        for (const swapped of [0, 1]) {
            if (swapped) await page.locator('.matrix:visible .matrix__corner').click();
            assert.equal(await page.locator('.matrix:visible .toggle--cell:visible').count(), 64);
            assert.equal(await page.locator('.matrix:visible .matrix__unsupported:visible').count(), 0);
            assert.equal(await page.locator('.matrix:visible th [data-channel-index-value="18"]:visible').count(), 0);
        }
        assert.equal(await page.locator('.routing__node').count(), 16);
        assert.equal(await page.locator('.routing__node[data-aux], .routing__edge[data-source="8"], .routing__edge[data-output="8"]').count(), 0);
        await go('gain');
        assert.equal(await page.locator('section[data-page=gain] [data-channelvisibility-index-value="18"]:visible').count(), 0);
        assert.equal(await page.locator('.eq-overview__switch--aux:visible').count(), 0);
        assert.equal(await page.locator('.tab[data-page=aux]:visible').count(), 0);
        await go('aux');
        assert.equal(await warning.isVisible(), true);
        await page.locator('[data-action="editor#unhideChannel"]').click();
        assert.equal(await page.locator('section[data-page=aux]').isVisible(), true);
        await preferences([...Array.from({ length: 16 }, (_, i) => i), 18], true);
        await go('comp');
        await page.waitForFunction(() => document.querySelector('section[data-page=comp] canvas').getAttribute('aria-label') === 'No visible compressor channels.');
        assert.equal(await page.locator('section[data-page=comp] .strip:visible').count(), 0);
        await go('matrix');
        assert.equal(await page.locator('.routing__node').count(), 0);
        assert.equal(await page.locator('.matrix:visible .toggle--cell:visible').count(), 0);
        await preferences([0, 15], true);
        await go('gain');
        // Every placement retains original group identities and complete selectors.
        for (const placement of ['normal', 'side', 'stacked']) {
            await page.evaluate(placement => {
                const radio = document.querySelector(`input[data-editor-target=linkPlacement][value="${placement}"]`);
                radio.checked = true; radio.dispatchEvent(new Event('change', { bubbles: true }));
            }, placement);
            assert.equal(await inputStrips.count(), 7);
            assert.equal(await incoming.locator('.toggle--route').count(), 8);
            assert.equal(await page.locator('.link-selectors').count(), 0);
            assert.ok(await incoming.locator('.group-selector').evaluateAll(nodes => nodes.every(node => node.parentElement.classList.contains('link-block__head'))));
            await go('matrix');
            assert.equal(await page.locator('.matrix:visible .toggle--cell:visible').count(), 63);
            assert.equal(await page.locator('.routing__node').count(), 15);
            await go('gain');
            if (placement === 'stacked') {
                await incoming.screenshot({ path: `/tmp/trackdsp-selector-before-${width}.png` });
                assert.equal(await incoming.locator('.link-block__head .group-selector').count(), 1,
                    'Stacked aggregate belongs inside its existing link-group header.');
                const order = await incoming.locator('.toggle--route .ch').evaluateAll(nodes => nodes.map(node => node.dataset.channelIndexValue));
                await preferences([0, 15], false);
                assert.equal(await incoming.locator('.group-selector').count(), 0);
                assert.deepEqual(await incoming.locator('.toggle--route .ch').evaluateAll(nodes => nodes.map(node => node.dataset.channelIndexValue)), order);
                await preferences([0, 15], true);
                assert.equal(await incoming.locator('.link-block__head .group-selector').count(), 1);
                assert.equal(await incoming.locator(':scope > .link-selectors .group-selector').count(), 0);
                assert.deepEqual(await incoming.locator('.toggle--route .ch').evaluateAll(nodes => nodes.map(node => node.dataset.channelIndexValue)), order);
                await incoming.screenshot({ path: `/tmp/trackdsp-selector-stacked-${width}.png` });
            }
        }
        // Reload retains display preferences, and a linked-member URL remains accessible.
        await page.reload();
        await page.waitForFunction(() => document.querySelectorAll('.chtab[data-side]:not([hidden])').length === 13);
        await go('inputs2');
        assert.equal(await page.locator('section[data-page=inputs2]').isVisible(), true);
        await page.evaluate(() => {
            window.mockState.inputs[0].link = 0;
            window.mockEvents.dispatchEvent(new MessageEvent('state', { data: JSON.stringify(window.mockState) }));
        });
        assert.equal(await page.locator('.chtab[data-side=inputs][data-index="2"]').getAttribute('aria-selected'), 'true');
        await go('gain');
        assert.equal(await incoming.locator('.group-selector').count(), 0);
        for (const [channel, expected] of [
            ['inputs2', ['bands', 'selected', 'gain', 'hpf', 'lpf', 'gate', 'fbq']],
            ['outputs0', ['bands', 'selected', 'gain', 'hpf', 'lpf', 'comp', 'delay']],
            ['aux', ['bands', 'selected', 'gain', 'comp', 'select', 'automix', 'effect', 'camera']],
        ]) {
            await go(channel);
            const panel = page.locator(`section[data-page=${channel}] .peq__panel`);
            assert.deepEqual(await panel.locator('[data-collapse-key-value]').evaluateAll(nodes => nodes.map(node => node.dataset.collapseKeyValue.split('.')[1])), expected);
            const stack = panel.locator('.peq__band-stack');
            const bands = stack.locator('[data-collapse-key-value$=".bands"]');
            const selected = stack.locator('[data-collapse-key-value$=".selected"]');
            const assertBandStack = async () => {
                const tableBox = await bands.boundingBox(), selectedBox = await selected.boundingBox();
                assert.equal(tableBox.x, selectedBox.x);
                assert.ok(selectedBox.y >= tableBox.y + tableBox.height, 'Selected band stays below Bands.');
            };
            await assertBandStack();
            await bands.locator('.fold').click();
            assert.equal(await selected.locator('input[type=range]').first().isVisible(), true);
            await assertBandStack();
            await bands.locator('.fold').click();
            await selected.locator('.fold').click();
            assert.equal(await bands.locator('table').isVisible(), true);
            await selected.locator('.fold').click();
            await assertBandStack();
            if (channel !== 'aux') {
                const hpf = await panel.locator('[data-collapse-key-value="channel.hpf"]').boundingBox();
                const lpf = await panel.locator('[data-collapse-key-value="channel.lpf"]').boundingBox();
                assert.equal(hpf.x, lpf.x);
                assert.ok(lpf.y >= hpf.y + hpf.height, 'HPF and LPF remain vertically stacked.');
            }
            await panel.screenshot({ path: `/tmp/trackdsp-channel-order-${channel}-${width}.png` });
        }
        await go('gain');
        // Settings has its own header-menu entry at both viewport sizes.
        assert.equal(await page.locator('nav.menu > button[data-editor-page-param=display]').count(), 1);
        await page.locator('[data-editor-page-param=display]').click();
        await page.locator('section[data-page=display]').waitFor({ state: 'visible' });
        const settings = page.locator('section[data-page=display]');
        await page.evaluate(() => {
            window.mockState.inputs[0].link = 5;
            window.mockEvents.dispatchEvent(new MessageEvent('state', { data: JSON.stringify(window.mockState) }));
        });

        const visibleCheck = index => settings.locator(`[data-channelview-target=channel][value="${index}"]`);
        await assertDisplayTooltips(settings);
        const assertRows = async (locator, ids, attribute) => {
            assert.deepEqual(await locator.evaluateAll((nodes, attr) => nodes.map(node => node.getAttribute(attr)), attribute), ids);
            const bounds = await locator.evaluateAll(nodes => nodes.map(node => { const rect = node.getBoundingClientRect(); return { top: rect.top, bottom: rect.bottom }; }));
            for (let i = 1; i < bounds.length; i++) assert.ok(bounds[i].top >= bounds[i - 1].bottom, 'Grouping rows do not share a line.');
        };
        await assertRows(settings.locator('.names__row'), ['inputs', 'outputs', 'aux'], 'data-names-side');
        for (const [side, count] of [['inputs', 8], ['outputs', 8], ['aux', 1]]) {
            assert.equal(await settings.locator(`.names__row[data-names-side=${side}] input`).count(), count);
        }
        await assertRows(settings.locator('.link-names__row'), ['inputs', 'outputs'], 'data-names-side');
        for (const side of ['inputs', 'outputs']) {
            assert.ok(await settings.locator(`.link-names__row[data-names-side=${side}] input`).count() > 0);
        }
        const pageRows = settings.locator('[data-panel-page]');
        const contexts = await page.locator('[data-collapse-key-value]').evaluateAll(nodes => [...new Set(nodes.map(node => node.dataset.collapseKeyValue.split('.')[0]))]);
        await assertRows(pageRows, contexts, 'data-panel-page');
        assert.ok(await pageRows.evaluateAll(nodes => nodes.every(row => [...row.querySelectorAll('input')].every(input => input.dataset.key.startsWith(row.dataset.panelPage + '.')))));
        for (const section of ['channels', 'eq']) {
            const columns = settings.locator(`[data-display-section=${section}] .channel-visibility > [data-display-side]`);
            assert.deepEqual(await columns.evaluateAll(nodes => nodes.map(node => node.dataset.displaySide)), ['inputs', 'outputs', 'aux']);
            const boxes = await columns.evaluateAll(nodes => nodes.map(node => { const r = node.getBoundingClientRect(); return { x: r.x, y: r.y, bottom: r.bottom, right: r.right }; }));
            if (width === 1440) {
                assert.equal(boxes[0].y, boxes[1].y); assert.equal(boxes[1].y, boxes[2].y);
                assert.ok(boxes[1].x >= boxes[0].right && boxes[2].x >= boxes[1].right);
            } else assert.ok(boxes[1].y >= boxes[0].bottom && boxes[2].y >= boxes[1].bottom);
        }
        for (const [selector, name] of [
            ['[data-display-section=panels]', 'panel-rows'],
            ['.names--rows', 'name-rows'],
            ['.link-names__list--rows', 'link-name-rows'],
            ['[data-display-section=channels]', 'channel-columns'],
            ['[data-display-section=eq] .channel-visibility', 'overview-columns'],
        ]) {
            const element = settings.locator(selector);
            await element.evaluate(node => node.scrollIntoView({ block: 'center' }));
            await element.screenshot({ path: `/tmp/trackdsp-display-${name}-${width}.png` });
        }
        await visibleCheck(18).uncheck();
        await settings.locator('[data-channelview-side-param=inputs]').click();
        assert.equal(await visibleCheck(0).isChecked(), true);
        assert.equal(await visibleCheck(15).isChecked(), false);
        assert.equal(await visibleCheck(18).isChecked(), false);
        await visibleCheck(7).uncheck();
        await settings.locator('[data-channelview-side-param=outputs]').click();
        assert.equal(await visibleCheck(7).isChecked(), false);
        assert.equal(await visibleCheck(15).isChecked(), true);
        assert.equal(await visibleCheck(18).isChecked(), false);
        assert.equal(await settings.locator('[data-channelview-target=grouped]').isChecked(), true);
        const visibilityRows = settings.locator('.display-settings__channels > .display-settings__row');
        assert.equal(await visibilityRows.count(), 2);
        const rows = await visibilityRows.evaluateAll(nodes => nodes.map(node => { const rect = node.getBoundingClientRect(); return { top: rect.top, bottom: rect.bottom }; }));
        assert.ok(rows[1].top >= rows[0].bottom);
        // Colour, icon and placement apply to the complete individual visibility controls.
        await page.evaluate(() => {
            for (const key of ['code', 'shape', 'colour']) {
                const box = document.querySelector(`input[data-editor-target=linkView][value="${key}"]`);
                box.checked = true; box.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
        for (const placement of ['normal', 'side', 'stacked']) {
            await page.evaluate(placement => {
                window.mockState.inputs[0].link = 5;
                window.mockState.inputs[1].link = 10;
                window.mockEvents.dispatchEvent(new MessageEvent('state', { data: JSON.stringify(window.mockState) }));
                const radio = document.querySelector(`input[data-editor-target=linkPlacement][value="${placement}"]`);
                radio.checked = true; radio.dispatchEvent(new Event('change', { bubbles: true }));
            }, placement);
            const inputSection = settings.locator('[data-display-section=channels] .channel-visibility__side').first();
            assert.equal(await inputSection.locator('[data-channelview-target=channel]').count(), 8);
            assert.deepEqual(await inputSection.locator('[data-channelview-target=channel]').evaluateAll(nodes => nodes.map(node => Number(node.value))),
                placement === 'normal' ? [0, 1, 2, 3, 4, 5, 6, 7] : [0, 2, 1, 3, 4, 5, 6, 7]);
            for (const [side, count, order] of [
                ['inputs', 2, [0, 2, 1, 3, 4, 5, 6, 7]],
                ['outputs', 1, [0, 1, 3, 2, 4, 5, 6, 7]],
            ]) {
                const eqSide = settings.locator(`[data-eq-side="${side}"]`);
                assert.equal(await eqSide.locator('.display-settings__eq').count(), 8);
                assert.deepEqual(await eqSide.locator('.ch').evaluateAll(nodes => nodes.map(node => Number(node.dataset.channelIndexValue) % 8)),
                    placement === 'normal' ? [0, 1, 2, 3, 4, 5, 6, 7] : order);
                assert.equal(await eqSide.locator(':scope > .link-block').count(), placement === 'stacked' ? count : 0);
                if (placement === 'stacked') {
                    assert.equal(await eqSide.locator('.link-block__head').count(), count);
                    assert.ok(await eqSide.locator('.link-block .ch__link').evaluateAll(nodes => nodes.every(node => getComputedStyle(node).display === 'none')));
                }
            }
            assert.equal(await settings.locator('[data-eq-side=aux] .link-block').count(), 0);
            assert.equal(await inputSection.locator('[data-channelview-target=channel]:visible').count(), 4);
            assert.equal(await inputSection.locator('.group-selector input:visible').count(), 2);
            assert.equal(await settings.locator('[data-eq-side=inputs] .display-settings__eq:visible').count(), 6);
            assert.equal(await settings.locator('[data-eq-side=outputs] .display-settings__eq:visible').count(), 7);
            assert.equal(await settings.locator('[data-displaysettings-target=overview]:visible').count(), 11);
            const saved = await page.evaluate(() => ['eq-view', 'eq-filter-labels', 'eq-overview-hidden'].map(key => localStorage.getItem('trackdsp-' + key)));
            await settings.locator('[data-channelview-target=grouped]').uncheck();
            assert.equal(await inputSection.locator('[data-channelview-target=channel]:visible').count(), 8);
            assert.equal(await inputSection.locator('.group-selector input').count(), 0);
            assert.equal(await settings.locator('[data-eq-side=inputs] .display-settings__eq:visible').count(), 8);
            assert.equal(await settings.locator('[data-displaysettings-target=overview]:visible').count(), 17);
            await settings.locator('[data-channelview-target=grouped]').check();
            assert.deepEqual(await page.evaluate(() => ['eq-view', 'eq-filter-labels', 'eq-overview-hidden'].map(key => localStorage.getItem('trackdsp-' + key))), saved);

            const groupedTab = page.locator('.chtab[data-group="1"]:visible').first();
            const shadow = await groupedTab.evaluate(tab => getComputedStyle(tab).boxShadow);
            assert.equal(shadow === 'none', placement === 'stacked');
            const groupLabels = page.locator('.group-selector');
            assert.ok(await groupLabels.count() > 0);
            assert.ok(await groupLabels.evaluateAll(labels => labels.every(label => !/\((?:In|Out)\d/.test(label.textContent))));
            assert.ok(await groupLabels.evaluateAll(labels => labels.every(label => /Linked: (?:In|Out)\d/.test(label.title))));
            if (placement === 'stacked') {
                assert.equal(await inputSection.locator('.link-block[data-group="1"]').count(), 1);
                assert.equal(await inputSection.locator('.link-block__head .link-shape').count(), 2);
                assert.ok(await settings.locator('.link-block[data-group]:not([data-group=""])').evaluateAll(blocks => blocks.every(block =>
                    getComputedStyle(block).backgroundColor === getComputedStyle(block.querySelector(':scope > .link-block__head')).backgroundColor)));
                assert.equal(await inputSection.locator('[data-link-group="1"]').evaluate(block => getComputedStyle(block).backgroundColor), 'rgb(79, 195, 247)');

            }
        }
        const inputSection = settings.locator('[data-display-section=channels] .channel-visibility__side').first();
        const visibilityGroup = inputSection.locator('[data-link-group="1"] .group-selector input');
        await visibleCheck(0).evaluate(box => { box.checked = false; box.dispatchEvent(new Event('change', { bubbles: true })); });
        assert.equal(await visibilityGroup.evaluate(box => box.indeterminate), true);
        await visibilityGroup.check();
        assert.equal(await visibleCheck(0).isChecked(), true);
        assert.equal(await visibleCheck(2).isChecked(), true);
        await visibilityGroup.uncheck();
        assert.equal(await visibleCheck(0).isChecked(), false);
        assert.equal(await visibleCheck(2).isChecked(), false);
        assert.equal(await visibleCheck(7).isChecked(), false);
        assert.equal(await visibleCheck(15).isChecked(), true);
        assert.equal(await visibleCheck(18).isChecked(), false);
        await visibilityGroup.check();
        await page.locator('section[data-page=display]').screenshot({ path: `/tmp/trackdsp-channel-display-${width}.png` });
        await page.locator('section[data-page=display] [data-action="channelview#showAll"]').click();
        assert.equal(await page.locator('section[data-page=display] [data-channelview-target=channel]:checked').count(), 17);
        // Device names share the existing individual write and both entry points update immediately.
        assert.deepEqual(writes, []);
        const channelName = settings.locator('[data-text-bind-value="inputs.0.name"]');
        await channelName.fill('Desk L');
        await channelName.dispatchEvent('change');
        await page.waitForFunction(() => document.querySelector('#dialog-names [data-text-bind-value="inputs.0.name"]').value === 'Desk L');
        assert.equal(writes.length, 1);
        assert.deepEqual(writes[0].body.commands, [{ command: 'channelName', args: { channel: 0, name: 'Desk L' } }]);
        writes.length = 0;
        await page.locator('[data-editor-dialog-param=names]').evaluate(button => button.click());
        const shortcutName = page.locator('#dialog-names [data-text-bind-value="inputs.0.name"]');
        await shortcutName.fill('Desk R');
        await shortcutName.dispatchEvent('change');
        await page.waitForFunction(() => document.querySelector('section[data-page=display] [data-text-bind-value="inputs.0.name"]').value === 'Desk R');
        assert.equal(writes.length, 1);
        assert.deepEqual(writes[0].body.commands, [{ command: 'channelName', args: { channel: 0, name: 'Desk R' } }]);
        writes.length = 0;
        await page.locator('#dialog-names .dialog__close').click();
        await settings.locator('.display-settings__eq-list').screenshot({ path: `/tmp/trackdsp-display-eq-groups-${width}.png` });
        // Display sections use the same fold behavior, preferences and recovery headers as processing pages.
        for (const key of ['appearance', 'channels', 'links', 'meters', 'eq', 'matrix', 'panels']) {
            const section = settings.locator(`[data-display-section="${key}"]`);
            const fold = section.locator(':scope > legend [data-collapse-target=toggle]');
            await fold.click();
            assert.equal(await fold.getAttribute('aria-expanded'), 'false');
            assert.ok(await fold.isVisible());
            assert.equal(await section.locator(':scope > :not(legend):visible').count(), 0);
            await fold.click();
            assert.equal(await fold.getAttribute('aria-expanded'), 'true');
        }
        // The full settings page shares values with every local shortcut.
        assert.equal(await settings.locator('[data-display-section]').count(), 7);
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
        await settings.locator('[data-displaysettings-target=theme]').selectOption('dark');
        assert.equal(await page.locator('html').getAttribute('data-theme'), 'dark');
        await page.locator('[data-editor-target=theme]').click();
        assert.equal(await settings.locator('[data-displaysettings-target=theme]').inputValue(), 'auto');
        await settings.locator('[data-displaysettings-target=theme]').selectOption('light');
        await settings.locator('[data-displaysettings-target=matrix]').check();
        await go('matrix');
        assert.equal(await page.locator('.matrix:visible').getAttribute('data-swapped'), '1');
        await page.locator('.matrix:visible [data-action="editor#swapMatrix"]').click();
        await go('display');
        assert.equal(await settings.locator('[data-displaysettings-target=matrix]').isChecked(), false);
        const eqChoice = (path, field) => settings.locator(`[data-displaysettings-target=eq][data-path="${path}"][data-field="${field}"]`);
        await eqChoice('inputs.0', 'phase').check();
        await eqChoice('inputs.0', 'labels').check();
        await go('inputs0');
        assert.equal(await page.locator('section[data-page=inputs0] [data-peq-target=phase]').isChecked(), true);
        assert.equal(await page.locator('section[data-page=inputs0] [data-peq-target=labels]').isChecked(), true);
        await page.locator('section[data-page=inputs0] [data-peq-target=mag]').uncheck();
        await go('display');
        assert.equal(await eqChoice('inputs.0', 'mag').isChecked(), false);
        assert.equal(await eqChoice('inputs.0', 'mag').evaluate(box => box.indeterminate), true);
        assert.equal(await eqChoice('inputs.0', 'mag').getAttribute('aria-checked'), 'mixed');
        await eqChoice('inputs.0', 'mag').check();
        assert.equal(await eqChoice('inputs.2', 'mag').isChecked(), true);
        assert.equal(await eqChoice('inputs.0', 'mag').evaluate(box => box.indeterminate), false);
        // Globally hidden members remain part of a group preference and recovery control.
        await preferences([0, 2], true);
        assert.equal(await eqChoice('inputs.0', 'phase').isVisible(), true);
        await eqChoice('inputs.0', 'phase').uncheck();
        assert.equal(await eqChoice('inputs.2', 'phase').isChecked(), false);
        await eqChoice('inputs.0', 'phase').check();
        assert.equal(await eqChoice('inputs.2', 'phase').isChecked(), true);
        await inputSection.locator('[data-link-group="1"] .group-selector input').check();
        assert.equal(await visibleCheck(0).isChecked(), true);
        assert.equal(await visibleCheck(2).isChecked(), true);
        // A live link removal restores member controls without changing stored choices.
        await page.evaluate(() => {
            window.mockState.inputs[0].link = 0;
            window.mockEvents.dispatchEvent(new MessageEvent('state', { data: JSON.stringify(window.mockState) }));
        });
        assert.equal(await eqChoice('inputs.2', 'phase').isVisible(), true);
        await page.evaluate(() => {
            window.mockState.inputs[0].link = 5;
            window.mockEvents.dispatchEvent(new MessageEvent('state', { data: JSON.stringify(window.mockState) }));
        });
        assert.equal(await eqChoice('inputs.2', 'phase').isVisible(), false);

        const panelKeys = await settings.locator('[data-displaysettings-target=panels] input').evaluateAll(nodes => nodes.map(node => node.dataset.key));
        const originalKeys = await page.locator('[data-collapse-key-value]').evaluateAll(nodes => [...new Set(nodes.map(node => node.dataset.collapseKeyValue))]);
        assert.deepEqual([...panelKeys].sort(), [...originalKeys].sort());
        assert.equal(new Set(panelKeys).size, panelKeys.length);
        const curvePanel = settings.locator('[data-displaysettings-target=panels] input[data-key="channel.curve"]');
        await curvePanel.uncheck();
        await go('inputs0');
        assert.equal(await page.locator('section[data-page=inputs0] [data-collapse-key-value="channel.curve"]').evaluate(panel => panel.classList.contains('is-collapsed')), true);
        await page.locator('section[data-page=inputs0] [data-collapse-key-value="channel.curve"] .fold').click();
        await go('display');
        assert.equal(await curvePanel.isChecked(), true);
        await settings.locator('[data-channelview-target=grouped]').uncheck();
        await settings.locator('[data-displaysettings-target=overview][value="inputs.0"]').uncheck();
        await settings.locator('[data-channelview-target=grouped]').check();
        const settingsOverviewGroup = settings.locator('[data-display-section=eq] [data-link-group="1"] .group-selector input').first();
        assert.equal(await settingsOverviewGroup.evaluate(box => box.indeterminate), true);
        await settingsOverviewGroup.check();
        assert.equal(await settings.locator('[data-displaysettings-target=overview][value="inputs.0"]').isChecked(), true);
        assert.equal(await settings.locator('[data-displaysettings-target=overview][value="inputs.2"]').isChecked(), true);
        await settingsOverviewGroup.uncheck();
        assert.equal(await settings.locator('[data-displaysettings-target=overview][value="inputs.0"]').isChecked(), false);
        assert.equal(await settings.locator('[data-displaysettings-target=overview][value="inputs.2"]').isChecked(), false);
        assert.equal(await settingsOverviewGroup.evaluate(box => box.indeterminate), false);
        await settingsOverviewGroup.check();

        await eqChoice('overview', 'phase').check();
        await go('gain');
        assert.equal(await page.locator('[data-eqoverview-target=phase]').isChecked(), true);
        await page.locator('[data-eqoverview-target=phase]').uncheck();
        await go('display');
        assert.equal(await eqChoice('overview', 'phase').isChecked(), false);
        await checkEqOverview(page, settings, width, go, preferences);
        const threshold = settings.locator('[data-meterview-target=show]');
        await threshold.uncheck();
        assert.equal(await settings.locator('[data-meterview-target=active]').isDisabled(), true);
        await threshold.check();
        const groupName = settings.locator('[data-displaysettings-target=names] input[data-key="inputs:0"]');
        const nameLabel = groupName.locator('..');
        assert.equal(await nameLabel.getAttribute('data-group'), '1');
        assert.equal(await nameLabel.locator('.link-shape').count(), 1);
        assert.equal(await nameLabel.locator('.link-name__mark').evaluate(node => getComputedStyle(node).backgroundColor), 'rgb(79, 195, 247)');
        const colourOption = settings.locator('[data-editor-target=linkView][value=colour]');
        await colourOption.uncheck();
        assert.equal(await nameLabel.getAttribute('data-group'), '');
        assert.ok(await settings.locator('.link-block').evaluateAll(blocks => blocks.every(block => getComputedStyle(block).backgroundColor === 'rgba(0, 0, 0, 0)')));
        await colourOption.check();
        await groupName.fill('Desk');
        const handle = await groupName.elementHandle();
        await page.evaluate(() => window.mockEvents.dispatchEvent(new MessageEvent('state', { data: JSON.stringify(window.mockState) })));
        assert.equal(await handle.evaluate(input => input === document.activeElement && input.isConnected), true);
        await page.locator('[data-editor-dialog-param=link]').evaluate(button => button.click());
        const dialogName = page.locator('#dialog-link input[data-key="inputs:0"]');
        assert.equal(await dialogName.inputValue(), 'Desk');
        assert.equal(await dialogName.locator('..').getAttribute('data-group'), '1');
        assert.equal(await dialogName.locator('..').locator('.link-name__mark').evaluate(node => getComputedStyle(node).backgroundColor), 'rgb(79, 195, 247)');
        await dialogName.fill('Mix');
        await page.locator('#dialog-link .dialog__close').click();
        assert.equal(await groupName.inputValue(), 'Mix');
        await settings.locator('[data-action="change->editor#language"]').selectOption('de');
        await page.waitForLoadState('load');
        await settings.locator('h1').filter({ hasText: 'Anzeigeeinstellungen' }).waitFor();
        assert.equal(await settings.locator('[data-action="change->editor#language"]').inputValue(), 'de');
        await assertDisplayTooltips(settings);
        assert.ok((await settings.locator('[data-displaysettings-target=theme]').getAttribute('title')).includes('Farbschema'));
        assert.ok((await settings.locator('.link-name input').first().getAttribute('title')).startsWith('Browsername'));
        assert.ok((await settings.locator('[data-displaysettings-target=panels] input').first().getAttribute('title')).includes('auf- oder einklappen'));
        assert.equal(await eqChoice('inputs.0', 'phase').isChecked(), true);
        assert.equal(await eqChoice('inputs.0', 'labels').isChecked(), true);
        assert.equal(await settings.locator('[data-displaysettings-target=theme]').inputValue(), 'light');
        await preferences([...Array.from({ length: 16 }, (_, i) => i), 18], true);
        await go('display');
        assert.equal(await settings.isVisible(), true);
        await settings.locator('[data-action="channelview#showAll"]').click();
        const eqSection = settings.locator('[data-display-section=eq]');
        const eqFold = eqSection.locator(':scope > legend [data-collapse-target=toggle]');
        await eqFold.click();
        await eqSection.screenshot({ path: `/tmp/trackdsp-display-collapsed-${width}.png` });
        await page.reload();
        await eqFold.waitFor({ state: 'visible' });
        assert.equal(await eqFold.getAttribute('aria-expanded'), 'false');
        assert.equal(await eqSection.locator(':scope > :not(legend):visible').count(), 0);
        await eqFold.click();
        assert.equal(await settings.locator('[data-displaysettings-target=panels] input[data-key="display.eq"]').isChecked(), true);
        await settings.locator('[data-displaysettings-target=panels] input[data-key="display.eq"]').uncheck();
        assert.equal(await eqFold.getAttribute('aria-expanded'), 'false');
        await eqFold.click();
        await page.evaluate(() => window.scrollTo(0, 0));
        await page.screenshot({ path: `/tmp/trackdsp-display-settings-${width}.png`, fullPage: true });
        await page.screenshot({ path: `/tmp/trackdsp-display-settings-viewport-${width}.png` });
        await settings.locator('.channel-visibility__side').first().screenshot({ path: `/tmp/trackdsp-display-group-fill-${width}.png` });
        assert.deepEqual(writes, []);
        assert.deepEqual(errors, []);
        results.push({ width, preferences: 'persisted', aggregates: 'masked writes', matrix: 'both orientations', errors });
        await page.close();
    }
    // No connected state is needed to configure browser preferences with storage blocked.
    const blocked = await browser.newPage({ ignoreHTTPSErrors: true, viewport: { width: 390, height: 1000 } });
    const blockedWrites = [], blockedErrors = [];
    blocked.on('pageerror', error => blockedErrors.push(error.message));
    await blocked.route('**/api/**', async route => {
        if (route.request().method() !== 'GET') blockedWrites.push(route.request().url());
        await route.fulfill({ json: route.request().url().endsWith('/autoeq') ? { session: null, devices: [], backups: [], recorder: false, device: '' } : {} });
    });
    await blocked.addInitScript(() => {
        Object.defineProperty(window, 'localStorage', { get() { throw new Error('storage blocked'); } });
        window.EventSource = class extends EventTarget { close() {} };
    });
    await blocked.goto(url + '/#display');
    const settings = blocked.locator('section[data-page=display]');
    await settings.locator('[data-displaysettings-target=separated]').check();
    await blocked.locator('.tab[data-editor-page-param=gain]').evaluate(button => button.click());
    assert.equal(await blocked.locator('[data-eqoverview-target=separated]').isChecked(), true);
    assert.equal(await blocked.locator('.eq-overview__pane:visible').count(), 3);
    assert.equal(await blocked.locator('.eq-overview__empty:visible').count(), 3);
    await blocked.locator('[data-eqoverview-target=separated]').uncheck();
    await blocked.locator('[data-editor-page-param=display]').evaluate(button => button.click());
    assert.equal(await settings.locator('[data-displaysettings-target=separated]').isChecked(), false);
    assert.equal(await settings.locator('[data-controller=text]:disabled').count(), 17);
    await settings.locator('[data-displaysettings-target=theme]').selectOption('dark');
    await settings.locator('[data-displaysettings-target=eq][data-path="inputs.0"][data-field=phase]').check();
    await settings.locator('[data-displaysettings-target=eq][data-path="inputs.0"][data-field=labels]').check();
    await settings.locator('[data-displaysettings-target=panels] input[data-key="channel.curve"]').uncheck();
    await settings.locator('[data-displaysettings-target=matrix]').check();
    await blocked.locator('[data-editor-page-param=inputs0]').evaluate(button => button.click());
    assert.equal(await blocked.locator('section[data-page=inputs0] [data-peq-target=phase]').isChecked(), true);
    assert.equal(await blocked.locator('section[data-page=inputs0] [data-peq-target=labels]').isChecked(), true);
    await blocked.locator('section[data-page=inputs0] [data-peq-target=phase]').uncheck();
    await blocked.locator('[data-editor-page-param=display]').evaluate(button => button.click());
    assert.equal(await settings.locator('[data-displaysettings-target=theme]').inputValue(), 'dark');
    assert.equal(await settings.locator('[data-displaysettings-target=eq][data-path="inputs.0"][data-field=phase]').isChecked(), false);
    assert.equal(await settings.locator('[data-displaysettings-target=matrix]').isChecked(), true);
    assert.equal(await settings.locator('[data-displaysettings-target=panels] input[data-key="channel.curve"]').isChecked(), false);
    assert.equal(await settings.locator('[data-displaysettings-target=eq][data-path="inputs.0"][data-field=labels]').isChecked(), true);
    assert.deepEqual(blockedErrors, []);
    assert.deepEqual(blockedWrites, []);
    await settings.locator('[data-display-section=channels]').screenshot({ path: '/tmp/trackdsp-display-channels-mobile.png' });
    results.push({ storage: 'blocked', state: 'offline', shortcuts: 'synchronized', errors: blockedErrors });
    await blocked.close();
    console.log(JSON.stringify(results));
} finally { await browser.close(); }
