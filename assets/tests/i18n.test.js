import assert from 'node:assert/strict';
import { test } from 'node:test';
import { execFileSync } from 'node:child_process';
import { languageCookie, legacyLanguageCookie, makeTranslator, t } from '../lib/i18n.js';

test('messages fall back to English and interpolate translated parameters literally', () => {
    const translate = makeTranslator({ 'Connect %target%': '%target% verbinden' });
    assert.equal(translate('Connect %target%', { target: 'demo' }), 'demo verbinden');
    assert.equal(translate('Missing %target%', { target: '$& %target%' }), 'Missing $& %target%');
    assert.equal(translate('Connect %target%'), '%target% verbinden');
    assert.equal(translate('toString'), 'toString');
    assert.equal(t('No browser catalogue %count%', { count: 3 }), 'No browser catalogue 3');
});

test('language overrides use a persistent root cookie and automatic expires it', () => {
    assert.equal(languageCookie('de', true), 'sdwa5_dsp_locale=de; Path=/; Max-Age=31536000; SameSite=Lax; Secure');
    assert.equal(languageCookie('en'), 'sdwa5_dsp_locale=en; Path=/; Max-Age=31536000; SameSite=Lax');
    assert.equal(languageCookie('auto'), 'sdwa5_dsp_locale=; Path=/; Max-Age=0; SameSite=Lax');
    assert.equal(languageCookie('fr'), languageCookie('auto'));
    assert.equal(languageCookie('de; injected=1'), languageCookie('auto'));
});

test('browser catalogue translates runtime messages', async () => {
    globalThis.document = { getElementById: () => ({ textContent: JSON.stringify({ Muted: 'Stumm', 'Connect %target%': '%target% verbinden' }) }) };
    try {
        const browser = await import('../lib/i18n.js?browser-test');
        assert.equal(browser.t('Connect %target%', { target: 'usb' }), 'usb verbinden');
        assert.equal(browser.t('Muted'), 'Stumm');
    } finally {
        delete globalThis.document;
    }
});

test('translated mute display parses back to a muted gain', () => {
    const script = `
        import assert from 'node:assert/strict';
        globalThis.document = { getElementById: () => ({ textContent: '{"Muted":"Stumm"}' }) };
        const scale = await import('./assets/lib/scale.js');
        assert.equal(scale.format('gain', 0), 'Stumm');
        assert.equal(scale.parse('gain', scale.format('gain', 0)), 0);
        assert.equal(scale.parse('gain', 'mute'), 0);
        assert.equal(scale.parse('gain', '-6,0 dB'), 220);
    `;
    execFileSync(process.execPath, ['--input-type=module', '-e', script], { cwd: new URL('../..', import.meta.url) });
});

test('the trackdsp language cookie is deleted', () => {
    assert.equal(legacyLanguageCookie(true), 'trackdsp_locale=; Path=/; Max-Age=0; SameSite=Lax; Secure');
});
