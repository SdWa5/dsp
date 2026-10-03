/** Browser regression checks use isolated state and intercept every API request, including writes. */
import assert from 'node:assert/strict';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const browser = await chromium.launch({ headless: true });
const url = process.env.TRACKDSP_TEST_URL || 'https://trackdsp.ddev.site';
const results = [];
try {
    for (const width of [1440, 390]) {
        const page = await browser.newPage({ ignoreHTTPSErrors: true, viewport: { width, height: 1000 } });
        const writes = [], errors = [];
        page.on('pageerror', e => errors.push(e.message));
        await page.route('**/api/**', async route => {
            if (route.request().method() !== 'GET') writes.push({ url: route.request().url(), body: route.request().postDataJSON() });
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
                const dialog = document.querySelector('#dialog-display');
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
            const summary = page.locator('[data-collapse-key-value="gain.input"] [data-channel-index-value="0"] .channel-members');
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
            if (channel !== 'aux') {
                const hpf = await panel.locator('[data-collapse-key-value="channel.hpf"]').boundingBox();
                const lpf = await panel.locator('[data-collapse-key-value="channel.lpf"]').boundingBox();
                assert.equal(hpf.x, lpf.x);
                assert.ok(lpf.y >= hpf.y + hpf.height, 'HPF and LPF remain vertically stacked.');
            }
            await panel.screenshot({ path: `/tmp/trackdsp-channel-order-${channel}-${width}.png` });
        }
        await go('gain');
        // Opening the settings through the real menu works at both viewport sizes.
        const menu = page.locator('.menu__item').filter({ has: page.locator('[data-editor-dialog-param=display]') });
        await menu.locator('.menu__title').click();
        await page.locator('[data-editor-dialog-param=display]').click();
        await page.locator('#dialog-display').waitFor({ state: 'visible' });
        const settings = page.locator('#dialog-display');
        const visibleCheck = index => settings.locator(`[data-channelview-target=channel][value="${index}"]`);
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
            const inputSection = settings.locator('.channel-visibility__side').first();
            assert.equal(await inputSection.locator('[data-channelview-target=channel]').count(), 8);
            assert.deepEqual(await inputSection.locator('[data-channelview-target=channel]').evaluateAll(nodes => nodes.map(node => Number(node.value))),
                placement === 'normal' ? [0, 1, 2, 3, 4, 5, 6, 7] : [0, 2, 1, 3, 4, 5, 6, 7]);
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
            }
        }
        const inputSection = settings.locator('.channel-visibility__side').first();
        const visibilityGroup = inputSection.locator('[data-link-group="1"] .group-selector input');
        await visibleCheck(0).uncheck();
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
        await page.locator('#dialog-display').screenshot({ path: `/tmp/trackdsp-channel-display-${width}.png` });
        await page.locator('#dialog-display [data-action="channelview#showAll"]').click();
        assert.equal(await page.locator('#dialog-display [data-channelview-target=channel]:checked').count(), 17);
        assert.deepEqual(writes, []);
        assert.deepEqual(errors, []);
        results.push({ width, preferences: 'persisted', aggregates: 'masked writes', matrix: 'both orientations', errors });
        await page.close();
    }
    console.log(JSON.stringify(results));
} finally { await browser.close(); }
