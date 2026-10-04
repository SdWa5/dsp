/** Gain overview checks run inside the channel-display suite's mocked, API-intercepted page. */
import assert from 'node:assert/strict';

export async function checkEqOverview(page, settings, width, go, preferences) {
    const saved = await page.evaluate(() => ({
        display: JSON.parse(localStorage.getItem('trackdsp-channel-display') || '{}'),
        hidden: JSON.parse(localStorage.getItem('trackdsp-eq-overview-hidden') || '[]'),
        view: JSON.parse(localStorage.getItem('trackdsp-eq-view') || '{}').overview || { mag: true, phase: false },
        state: window.mockState,
    }));
    const layout = settings.locator('[data-displaysettings-target=separated]');
    const shortcut = page.locator('[data-eqoverview-target=separated]');
    const canvas = side => page.locator(`canvas[data-eqoverview-target=canvas][data-overview-side=${side}]`);
    const pane = side => page.locator(`figure[data-overview-side=${side}]`);
    const drawn = async () => {
        await page.waitForFunction(() => [...document.querySelectorAll('.eq-overview__pane:not([hidden]) canvas')].every(c => c.clientWidth > 0 && c.width > 0));
        await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
    };
    const image = side => canvas(side).evaluate(c => c.toDataURL());
    const select = path => settings.locator(`[data-displaysettings-target=overview][value="${path}"]`);
    const setView = async (mag, phase) => {
        await settings.locator('[data-displaysettings-target=eq][data-path=overview][data-field=mag]').setChecked(mag);
        await settings.locator('[data-displaysettings-target=eq][data-path=overview][data-field=phase]').setChecked(phase);
    };
    await preferences([], false);
    await go('display');
    assert.equal(await layout.isChecked(), false);
    for (const box of await settings.locator('[data-displaysettings-target=overview]').all()) await box.check();
    await setView(true, false);
    await layout.check();
    await go('gain');
    assert.equal(await shortcut.isChecked(), true);
    await drawn();
    assert.equal(await canvas('combined').isVisible(), false);
    const sides = ['inputs', 'outputs', 'aux'];
    const bounds = [];
    for (const side of sides) {
        assert.equal(await canvas(side).isVisible(), true);
        assert.equal(await pane(side).locator('.eq-overview__empty').isVisible(), false);
        assert.ok((await canvas(side).getAttribute('aria-label')).length > 0);
        bounds.push(await pane(side).boundingBox());
    }
    if (width === 1440) {
        assert.equal(bounds[0].y, bounds[1].y); assert.equal(bounds[1].y, bounds[2].y);
        assert.ok(bounds[1].x >= bounds[0].x + bounds[0].width && bounds[2].x >= bounds[1].x + bounds[1].width);
    } else {
        assert.ok(bounds[1].y >= bounds[0].y + bounds[0].height && bounds[2].y >= bounds[1].y + bounds[1].height);
    }
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
    await page.locator('.eq-overview').screenshot({
        path: `/tmp/trackdsp-eq-overview-separated-${width}.png`,
        style: '.editor__head, .editor__foot { visibility: hidden !important; }',
    });

    // Flat factory EQ curves overlap. Hover must still pick only a channel in this canvas.
    for (const side of sides) {
        const size = await canvas(side).boundingBox();
        await canvas(side).hover({ position: { x: size.width / 2, y: 126 } });
        await drawn();
        const lit = await page.locator('.eq-overview__switch:not(.is-dimmed) input').evaluateAll(boxes => boxes.map(box => box.value));
        assert.ok(lit.length > 0 && lit.every(path => path.split('.')[0] === side), `Hover stays within ${side}.`);
        await canvas(side).dispatchEvent('pointerleave');
    }
    await drawn();
    const before = await image('inputs'), outputBefore = await image('outputs');
    await page.evaluate(() => {
        window.mockState.inputs[0].peq[0].gain = 180;
        window.mockEvents.dispatchEvent(new MessageEvent('state', { data: JSON.stringify(window.mockState) }));
    });
    await drawn();
    assert.notEqual(await image('inputs'), before);
    assert.equal(await image('outputs'), outputBefore);

    // One view preference updates every plot, and a shortcut synchronizes back to settings.
    await go('display'); await setView(true, true); await go('gain'); await drawn();
    assert.equal(await page.locator('[data-eqoverview-target=phase]').isChecked(), true);
    await page.locator('[data-eqoverview-target=phase]').uncheck();
    await go('display');
    assert.equal(await settings.locator('[data-displaysettings-target=eq][data-path=overview][data-field=phase]').isChecked(), false);
    await select('inputs.2').uncheck();
    await preferences([], true);
    const selection = await page.evaluate(() => localStorage.getItem('trackdsp-eq-overview-hidden'));
    await go('gain');
    assert.equal(await page.locator('.eq-overview__switch input[value="inputs.0"]').evaluate(box => box.indeterminate), true);
    await shortcut.uncheck(); await drawn();
    assert.equal(await canvas('combined').isVisible(), true);
    assert.equal(await page.locator('.eq-overview__pane:visible').count(), 1);
    await go('display'); assert.equal(await layout.isChecked(), false);
    await layout.check();
    assert.equal(await page.evaluate(() => localStorage.getItem('trackdsp-eq-overview-hidden')), selection);
    await go('gain'); await drawn();
    assert.equal(await page.locator('.eq-overview__switch input[value="inputs.0"]').evaluate(box => box.indeterminate), true);

    // Hiding channels suppresses their curves without touching their curve selections.
    await preferences([...Array.from({ length: 8 }, (_, i) => i + 8), 18], true);
    await drawn();
    assert.equal(await pane('outputs').locator('.eq-overview__empty').isVisible(), true);
    assert.equal(await pane('aux').locator('.eq-overview__empty').isVisible(), true);
    await preferences([...Array.from({ length: 16 }, (_, i) => i), 18], true);
    await drawn();
    for (const side of sides) assert.equal(await pane(side).locator('.eq-overview__empty').isVisible(), true);
    assert.equal(await page.evaluate(() => localStorage.getItem('trackdsp-eq-overview-hidden')), selection);
    await preferences([], false);
    await go('display');
    for (let i = 0; i < 8; i++) await select(`inputs.${i}`).uncheck();
    await go('gain'); await drawn();
    assert.equal(await pane('inputs').locator('.eq-overview__empty').isVisible(), true);
    await go('display');
    for (let i = 0; i < 8; i++) await select(`inputs.${i}`).check();
    await go('gain'); await drawn();

    // Edits while collapsed must appear after expanding, including canvases newly shown by the layout.
    const collapsedBefore = await image('inputs');
    const fold = page.locator('.eq-overview > h2 .fold');
    await fold.click();
    await page.evaluate(() => {
        window.mockState.inputs[0].peq[0].gain = 60;
        window.mockEvents.dispatchEvent(new MessageEvent('state', { data: JSON.stringify(window.mockState) }));
    });
    await go('display'); await layout.uncheck(); await go('gain');
    assert.equal(await fold.getAttribute('aria-expanded'), 'false');
    await go('display'); await layout.check(); await go('gain');
    assert.equal(await fold.getAttribute('aria-expanded'), 'false');
    await fold.click(); await drawn();
    assert.notEqual(await image('inputs'), collapsedBefore);
    await page.reload();
    await shortcut.waitFor();
    assert.equal(await shortcut.isChecked(), true);
    await drawn();
    assert.equal(await pane('outputs').isVisible(), true);

    // Restore the surrounding suite's preferences and simulated state through the same UI controls.
    await go('display');
    for (const box of await settings.locator('[data-displaysettings-target=overview]').all()) {
        await box.setChecked(!saved.hidden.includes(await box.getAttribute('value')));
    }
    await setView(saved.view.mag, saved.view.phase);
    await layout.uncheck();
    await preferences(saved.display.hidden || [], saved.display.grouped === true);
    await page.evaluate(state => {
        window.mockState = state;
        window.mockEvents.dispatchEvent(new MessageEvent('state', { data: JSON.stringify(state) }));
    }, saved.state);
}
