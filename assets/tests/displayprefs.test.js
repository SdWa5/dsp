import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readPref, writePref, onPref } from '../lib/prefs.js';
import { EQ_PATHS, filterLabels, setFilterLabels, overviewHidden, selectOverview, setPanelCollapsed, eqPreferencePaths, eqPreferenceSelection, setEqPreference } from '../lib/displayprefs.js';
import { loadEqView, saveEqView } from '../lib/eq.js';
import { panelOptions, panelRows } from '../lib/displaypanels.js';

test('the EQ catalogue contains every channel and preserves per-channel settings', () => {
    assert.equal(EQ_PATHS.length, 17);
    assert.equal(EQ_PATHS.at(-1), 'aux');
    assert.deepEqual(loadEqView('inputs.0'), { mag: true, phase: false, left: 'db', right: null });
    assert.equal(filterLabels('inputs.0'), false);
    saveEqView('inputs.0', false, true);
    assert.equal(loadEqView('inputs.0').phase, true);
    assert.equal(loadEqView('outputs.0').phase, false);
    setFilterLabels('inputs.0', true);
    setFilterLabels('aux', true);
    assert.equal(filterLabels('inputs.0'), true);
    assert.equal(filterLabels('aux'), true);
    assert.equal(filterLabels('outputs.0'), false);
});

test('overview group selection preserves other channel choices and ignores invalid paths', () => {
    writePref('eq-overview-hidden', ['outputs.7', 'bad', 'inputs.1']);
    selectOverview(['inputs.0', 'inputs.1'], false);
    assert.deepEqual([...overviewHidden()], ['outputs.7', 'inputs.1', 'inputs.0']);
    selectOverview(['inputs.0', 'inputs.1', 'bad'], true);
    assert.deepEqual([...overviewHidden()], ['outputs.7']);
});

test('EQ and panel writes notify shortcuts and preserve unrelated preferences', () => {
    const notices = [];
    const off = onPref(['eq-view', 'eq-filter-labels', 'collapsed'], key => notices.push(key));
    writePref('collapsed', { 'gain.eq': true, 'channel.hpf': true });
    setPanelCollapsed('channel.hpf', false);
    assert.deepEqual(readPref('collapsed', {}), { 'gain.eq': true });
    setPanelCollapsed('aux.comp', true);
    saveEqView('overview', true, true);
    setFilterLabels('outputs.0', true);
    assert.deepEqual(notices, ['collapsed', 'collapsed', 'collapsed', 'eq-view', 'eq-filter-labels']);
    off();
});

test('malformed EQ display values fall back without changing stored settings', () => {
    writePref('eq-view', { aux: { mag: 'no', phase: 7 }, 'inputs.3': null });
    assert.equal(loadEqView('aux').mag, true);
    assert.equal(loadEqView('aux').phase, false);
    assert.equal(loadEqView('inputs.3').mag, true);
    writePref('eq-filter-labels', { aux: 'yes' });
    assert.equal(filterLabels('aux'), false);
    writePref('eq-overview-hidden', {});
    assert.deepEqual([...overviewHidden()], []);
});

test('panel inventory lists shared keys once with translated context and rendered title', () => {
    const panel = (key, title) => ({ dataset: { collapseKeyValue: key }, querySelector: () => ({ textContent: title }) });
    const root = { querySelectorAll: () => [panel('channel.hpf', 'HighPass'), panel('channel.hpf', 'HighPass'), panel('aux.comp', 'Comp'), panel('display.eq', 'EQ Plots')] };
    assert.deepEqual(panelOptions(root), [{ key: 'channel.hpf', label: 'Channel pages / HighPass' }, { key: 'aux.comp', label: 'Aux page / Comp' }, { key: 'display.eq', label: 'Display Settings / EQ Plots' }]);
});

test('group EQ choices include hidden members and preserve mixed preferences when grouping changes', () => {
    const state = { inputs: Array.from({ length: 8 }, () => ({ link: 0 })), outputs: Array.from({ length: 8 }, () => ({ link: 0 })) };
    state.inputs[2].link = (1 << 2) | (1 << 6);
    state.outputs[0].link = 129;
    const paths = eqPreferencePaths('inputs.6', state, true);
    assert.deepEqual(paths, ['inputs.2', 'inputs.6']);
    assert.deepEqual(eqPreferencePaths('inputs.6', state, false), ['inputs.6']);
    assert.deepEqual(eqPreferencePaths('outputs.7', state, true), ['outputs.0', 'outputs.7']);
    assert.deepEqual(eqPreferencePaths('aux', state, true), ['aux']);
    assert.deepEqual(eqPreferencePaths('overview', state, true), ['overview']);
    assert.deepEqual(eqPreferencePaths('inputs.6', null, true), ['inputs.6']);
    writePref('eq-view', { 'inputs.2': { mag: true, phase: false }, 'inputs.6': { mag: false, phase: true } });
    writePref('eq-filter-labels', { 'inputs.2': true, 'inputs.6': false });
    for (const field of ['mag', 'phase', 'labels']) assert.deepEqual(eqPreferenceSelection(paths, field), { selected: false, mixed: true, next: true });
    assert.equal(eqPreferenceSelection(['inputs.6'], 'phase').selected, true);
    const before = readPref('eq-view', {});
    eqPreferencePaths('inputs.6', state, false);
    assert.deepEqual(readPref('eq-view', {}), before);
    state.inputs[2].link = 0;
    assert.deepEqual(eqPreferencePaths('inputs.6', state, true), ['inputs.6']);
});

test('group EQ edits notify once and preserve other fields, channels and stored value formats', () => {
    writePref('eq-view', { 'inputs.2': { mag: false, phase: true }, 'inputs.6': { mag: true, phase: false }, aux: { mag: false, phase: true } });
    writePref('eq-filter-labels', { aux: true });
    const paths = ['inputs.2', 'inputs.6', 'invalid'];
    const notices = [], off = onPref(['eq-view', 'eq-filter-labels'], key => notices.push(key));
    setEqPreference(paths, 'mag', true);
    assert.deepEqual(readPref('eq-view', {}), { 'inputs.2': { mag: true, phase: true }, 'inputs.6': { mag: true, phase: false }, aux: { mag: false, phase: true } });
    setEqPreference(paths, 'labels', true);
    assert.deepEqual(readPref('eq-filter-labels', {}), { aux: true, 'inputs.2': true, 'inputs.6': true });
    assert.equal(eqPreferenceSelection(paths.slice(0, 2), 'mag').selected, true);
    assert.equal(eqPreferenceSelection(paths.slice(0, 2), 'phase').mixed, true);
    setEqPreference(paths, 'labels', false);
    assert.deepEqual(eqPreferenceSelection(paths.slice(0, 2), 'labels'), { selected: false, mixed: false, next: true });
    setEqPreference(paths, 'invalid', true);
    setEqPreference(['invalid'], 'phase', true);
    assert.deepEqual(notices, ['eq-view', 'eq-filter-labels', 'eq-filter-labels']);
    off();
});

test('panel rows group page contexts while retaining every key and translated label', () => {
    const options = [
        { key: 'channel.bands', label: 'Kanalseiten / Bänder' },
        { key: 'aux.bands', label: 'Aux-Seite / Bänder' },
        { key: 'channel.selected', label: 'Kanalseiten / Gewähltes Band' },
    ];
    assert.deepEqual(panelRows(options), [
        { context: 'channel', label: 'Kanalseiten', options: [options[0], options[2]] },
        { context: 'aux', label: 'Aux-Seite', options: [options[1]] },
    ]);
    assert.deepEqual(panelRows([]), []);
});
