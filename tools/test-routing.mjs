/** Isolated graph regression checks intercept every API write and use synthetic device state. */
import assert from 'node:assert/strict';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const browser = await chromium.launch({ headless: true });
const url = process.env.TRACKDSP_TEST_URL || 'https://trackdsp.ddev.site';
const results = [];
try {
    for (const width of [1440, 390]) {
        const page = await browser.newPage({ ignoreHTTPSErrors: true, hasTouch: true, viewport: { width, height: 1000 } });
        const writes = [], errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.route('**/api/**', async route => {
            if (route.request().method() !== 'GET') writes.push(route.request().postDataJSON());
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
                        const state = JSON.parse(document.getElementById('trackdsp-defaults').textContent);
                        state.inputs.forEach(channel => channel.link = 0);
                        state.outputs.forEach((channel, i) => { channel.link = 0; channel.route = 0x1000 | (1 << i); });
                        state.inputs[0].link = 5; state.outputs[1].link = 10;
                        state.aux.select = 0x1081;
                        window.routingBaseline = structuredClone(state);
                        this.dispatchEvent(new MessageEvent('status', { data: JSON.stringify({ connected: true, bridge: true, target: 'mock', demo: true, riders: [] }) }));
                        this.dispatchEvent(new MessageEvent('state', { data: JSON.stringify(state) }));
                    }, 20);
                }
                close() {}
            };
        });
        await page.goto(url + '/#matrix');
        await page.locator('[data-routing-endpoint="input:0"][aria-disabled=false]').waitFor();
        // Import the same mapped module instance to reset only this isolated test's local edits.
        await page.evaluate(async () => {
            const asset = performance.getEntriesByType('resource').find(entry => /\/assets\/lib\/device-[^/]+\.js/.test(entry.name));
            window.routingDevice = await import(asset.name);
        });
        const graph = page.locator('.routing__graph');
        const node = id => page.locator(`[data-routing-endpoint="${id}"]`);
        const reset = async () => {
            await page.evaluate(() => { window.routingDevice.store.edits.clear(); window.routingDevice.store.replace(structuredClone(window.routingBaseline)); });
            writes.length = 0;
        };
        const centre = async id => {
            const box = await node(id).boundingBox();
            return { x: box.x + box.width / 2, y: box.y + box.height / 2 };
        };
        const drag = async (first, second, preview = false) => {
            await graph.evaluate(svg => svg.scrollIntoView({ block: 'center' }));
            const start = await centre(first), end = await centre(second);
            await page.mouse.move(start.x, start.y); await page.mouse.down();
            await page.mouse.move(end.x, end.y, { steps: 5 });
            if (preview) {
                assert.equal(await page.locator('.routing__preview').count(), 1);
                assert.equal(await node(second).evaluate(n => n.classList.contains('is-target')), true);
                assert.equal(writes.length, 0);
                await page.locator('.routing').screenshot({ path: `/tmp/trackdsp-routing-drag-${width}.png` });
            }
            await page.mouse.up();
        };
        const command = async (channel, mask) => {
            await page.waitForFunction(() => !document.querySelector('.routing__preview'));
            await page.waitForTimeout(80);
            assert.deepEqual(writes, [{ commands: [{ command: 'routing', args: { channel, mask } }] }]);
        };
        const matrixOn = async (source, output, enabled) => {
            const cell = page.locator(`.matrix:visible td[data-source="${source}"][data-output="${output}"] button`);
            assert.equal(await cell.getAttribute('aria-pressed'), String(enabled));
            const edge = page.locator(`.routing__edge[data-source="${source}"][data-output="${output}"]`);
            assert.equal(await edge.count(), Number(enabled));
        };
        for (const [first, second, channel, mask, source, output, enabled] of [
            ['input:0', 'output:7', 15, 0x1081, 0, 7, true],
            ['output:7', 'input:7', 15, 0x1000, 7, 7, false],
            ['input:7', 'aux:8', 18, 0x1001, 7, 8, false],
            ['aux:8', 'input:1', 18, 0x1083, 1, 8, true],
            ['aux:8', 'output:1', 9, 0x1102, 8, 1, true],
            ['output:1', 'aux:8', 9, 0x1102, 8, 1, true],
        ]) {
            await reset(); await drag(first, second, first === 'input:0'); await command(channel, mask);
            await matrixOn(source, output, enabled);
            await page.locator('.matrix:visible .matrix__corner').click();
            await matrixOn(source, output, enabled);
            assert.equal(await page.evaluate(() => window.routingDevice.store.state.outputs[3].route), 0x1008);
            if (output === 8) {
                for (const scope of ['section[data-page=aux]', 'section[data-page=gain]', '#dialog-sub']) {
                    assert.equal(await page.locator(`${scope} [data-toggle-bind-value="aux.select"][data-toggle-bit-value="${source}"]`).getAttribute('aria-pressed'), String(enabled));
                }
            } else if (source === 8) {
                for (const scope of ['section[data-page=aux]', 'section[data-page=gain]', '#dialog-sub']) {
                    assert.equal(await page.locator(`${scope} [data-toggle-bind-value="outputs.${output}.route"][data-toggle-bit-value="8"]`).getAttribute('aria-pressed'), String(enabled));
                }
            }
        }
        await reset();
        // Hover still identifies the same incoming/outgoing Aux routes in both views.
        await node('aux:8').hover();
        assert.equal(await page.locator('.matrix-page.has-route-focus').count(), 1);
        assert.equal(await page.locator('.matrix:visible td[data-source="0"][data-output="8"].is-focus').count(), 1);
        await graph.dispatchEvent('pointerleave');
        // Matrix edits redraw the graph and retain destination-specific writes.
        await page.locator('.matrix:visible td[data-source="8"][data-output="7"] button').click();
        await command(15, 0x1180); await matrixOn(8, 7, true);
        await reset();
        // All link placements retain individual graph endpoints.
        for (const placement of ['normal', 'adjacent', 'stacked']) {
            await page.evaluate(async placement => {
                const asset = performance.getEntriesByType('resource').find(entry => /\/assets\/lib\/linkview-[^/]+\.js/.test(entry.name));
                const view = await import(asset.name); view.setLinkView({ ...view.getLinkView(), placement });
            }, placement);
            await drag('output:1', 'input:7'); await command(9, 0x1082); await reset();
        }
        // A wide edge hit area removes only its existing route, with mouse or keyboard.
        for (const [source, output, channel, mask] of [[0, 0, 8, 0x1000], [7, 8, 18, 0x1001]]) {
            await reset();
            const hit = page.locator(`.routing__hit[data-source="${source}"][data-output="${output}"]`);
            await graph.evaluate(svg => svg.scrollIntoView({ block: 'center' }));
            const position = await hit.evaluate(path => {
                const point = path.getPointAtLength(path.getTotalLength() * 0.45);
                const screen = new DOMPoint(point.x, point.y).matrixTransform(path.getScreenCTM());
                return { x: screen.x, y: screen.y };
            });
            await page.mouse.dblclick(position.x, position.y); await command(channel, mask);
            await matrixOn(source, output, false);
        }
        await reset();
        await page.locator('.routing__hit[data-source="0"][data-output="0"]').focus();
        await page.keyboard.press('Delete'); await command(8, 0x1000); await reset();
        // Keyboard uses the same commands and keeps focus after rebuilding graph nodes.
        await node('input:0').focus(); await page.keyboard.press('Enter');
        assert.equal(await node('input:0').getAttribute('aria-pressed'), 'true');
        await node('output:7').focus(); await page.keyboard.press('Space');
        await command(15, 0x1081);
        assert.equal(await page.evaluate(() => document.activeElement.dataset.routingEndpoint), 'output:7');
        assert.ok((await page.locator('[data-routing-target=announcement]').textContent()).includes('In1'));
        await reset();
        await node('aux:8').focus(); await page.keyboard.press('Space'); await page.keyboard.press('Escape');
        assert.equal(await page.locator('.routing.is-editing').count(), 0);
        await page.keyboard.press('Enter'); await node('input:0').focus(); await page.keyboard.press('Enter');
        await command(18, 0x1080); await reset();
        // Invalid and short mouse gestures never write.
        await drag('input:0', 'input:7');
        await drag('output:0', 'output:7');
        await drag('aux:8', 'aux:8');
        const start = await centre('input:0');
        await page.mouse.move(start.x, start.y); await page.mouse.down();
        await page.mouse.move(start.x + 2, start.y + 2); await page.mouse.up();
        await page.mouse.move(start.x, start.y); await page.mouse.down();
        await page.mouse.move(start.x + 170, start.y + 20); await page.mouse.up();
        assert.deepEqual(writes, []);
        // Touch uses real browser pointer capture; cancellation and completion are separate cases.
        const cdp = await page.context().newCDPSession(page);
        await graph.evaluate(svg => svg.scrollIntoView({ block: 'center' }));
        const touchStart = await centre('input:0'), touchEnd = await centre('output:7');
        await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [touchStart] });
        await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [touchEnd] });
        assert.equal(await page.locator('.routing__preview').count(), 1);
        await cdp.send('Input.dispatchTouchEvent', { type: 'touchCancel', touchPoints: [] });
        assert.equal(await page.locator('.routing__preview').count(), 0);
        assert.deepEqual(writes, []);
        await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [touchStart] });
        await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [touchEnd] });
        await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
        await command(15, 0x1081); await reset();
        // Meter updates keep the preview; a non-visible mask bit changes the latest commit value.
        await graph.evaluate(svg => svg.scrollIntoView({ block: 'center' }));
        const a = await centre('input:0'), b = await centre('output:7');
        await page.mouse.move(a.x, a.y); await page.mouse.down();
        await page.evaluate(() => {
            window.mockEvents.dispatchEvent(new MessageEvent('meters', { data: JSON.stringify({ levels: Array(17).fill(-20) }) }));
            window.routingDevice.store.set('outputs.7.route', 0x3080); window.routingDevice.store.notify();
        });
        assert.equal(await page.locator('.routing__preview').count(), 1);
        await page.mouse.move(b.x, b.y); await page.mouse.up(); await command(15, 0x3081); await reset();
        // Collapse, navigation, reconstruction and disconnection cancel unfinished keyboard gestures.
        for (const reason of ['collapse', 'navigation', 'reconstruction', 'disconnect']) {
            await node('input:0').focus(); await page.keyboard.press('Enter');
            await page.evaluate(reason => {
                if (reason === 'collapse') document.querySelector('.routing .fold').click();
                if (reason === 'navigation') document.querySelector('[data-editor-page-param=gain]').click();
                if (reason === 'reconstruction') { window.routingDevice.store.set('inputs.0.name', 'Changed'); window.routingDevice.store.notify(); }
                if (reason === 'disconnect') window.routingDevice.store.replace(null);
            }, reason);
            await page.waitForFunction(() => !document.querySelector('.routing.is-editing'));
            assert.deepEqual(writes, []);
            if (reason === 'disconnect') assert.equal(await page.locator('[data-routing-endpoint][aria-disabled=false]').count(), 0);
            if (reason === 'collapse') await page.locator('.routing .fold').click();
            if (reason === 'navigation') await page.evaluate(() => document.querySelector('[data-editor-page-param=matrix]').click());
            await reset();
        }
        // Reported masks and visibility gate editing without manufacturing unknown values.
        await page.evaluate(() => { window.routingDevice.store.state.outputs[7].route = undefined; window.routingDevice.store.notify(); });
        assert.equal(await node('output:7').getAttribute('aria-disabled'), 'true');
        await reset();
        await node('input:0').focus(); await page.keyboard.press('Enter');
        await page.evaluate(async () => {
            const asset = performance.getEntriesByType('resource').find(entry => /\/assets\/lib\/channelview-[^/]+\.js/.test(entry.name));
            const prefs = await import(asset.name); prefs.setChannelView({ ...prefs.getChannelView(), hidden: [0, 18] });
        });
        assert.equal(await node('input:0').count(), 0); assert.equal(await node('aux:8').count(), 0);
        assert.equal(await page.locator('.routing.is-editing').count(), 0);
        assert.deepEqual(writes, []); assert.deepEqual(errors, []);
        await page.locator('.routing').screenshot({ path: `/tmp/trackdsp-routing-edited-${width}.png` });
        if (width === 390) {
            await graph.evaluate(svg => svg.scrollIntoView({ block: 'center' }));
            const bounds = await graph.boundingBox();
            const blank = { x: bounds.x + bounds.width / 2, y: bounds.y + bounds.height / 3 };
            assert.equal(await page.evaluate(p => Boolean(document.elementFromPoint(p.x, p.y)?.closest('[data-routing-endpoint]')), blank), false);
            const scroll = await page.evaluate(() => scrollY);
            await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [blank] });
            await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ ...blank, y: blank.y + 65 }] });
            await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
            await page.waitForFunction(before => scrollY !== before, scroll);
            assert.deepEqual(writes, []);
        }
        await cdp.detach();
        results.push({ width, directions: 'both', routes: 'all kinds', touch: 'capture and cancellation', errors });
        await page.close();
    }
    console.log(JSON.stringify(results));
} finally { await browser.close(); }
