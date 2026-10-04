import assert from 'node:assert/strict';
import { test } from 'node:test';
import { EditHistory, nativeUndo } from '../lib/history.js';
import { Store } from '../lib/store.js';

const units = [{ command: 'gain', args: { raw: '@outputs.0.gain' } }, { command: 'routing', args: { mask: '@outputs.0.route' } }, { command: 'peq', args: { freq: '@outputs.0.peq.0.freq', gain: '@outputs.0.peq.0.gain' } }, { command: 'mute', args: { on: '@aux.mute' } }, { command: 'link', args: { flags: '@outputs.0.link' } }];
const state = () => ({ inputs: Array.from({length:8}, () => ({link:0})), outputs: Array.from({length:8}, (_, i) => ({gain:280,route:0x1081,link:1 << i,peq:[{freq:100,gain:120}]})), aux:{mute:null} });
function action(h, s, path, value, label='Change setting') {
    h.begin(label,s);
    const store = new Store(); store.replace(s);
    h.change(path, store.get(path), value, s);
    store.set(path,value);
    h.end(store.state);
    return store.state;
}

test('one grouped action, no-op suppression, undo/redo branching and limit', () => {
    const h = new EditHistory(units,2);
    let s = state();
    h.begin('drag',s);
    h.change('outputs.0.gain',280,290,s);
    h.change('outputs.0.gain',290,300,s);
    s.outputs[0].gain=300;
    h.end(s); h.confirm(s);
    assert.equal(h.undo.length,1);
    assert.deepEqual(h.patch('undo',s).changes,[{path:'outputs.0.gain',before:300,after:280}]);
    s.outputs[0].gain=280; h.moved('undo');
    assert.equal(h.patch('redo',s).changes[0].after,300);
    s = action(h,s,'outputs.0.gain',280); h.confirm(s);
    assert.equal(h.redo.length,1);
    s = action(h,s,'outputs.0.gain',320); h.confirm(s);
    assert.equal(h.redo.length,0);
    s = action(h,s,'outputs.0.gain',330); h.confirm(s);
    s = action(h,s,'outputs.0.gain',340); h.confirm(s);
    assert.equal(h.undo.length,2);
});
test('masks preserve unrelated bits and overlapping changes conflict', () => {
    const h = new EditHistory(units); let s = state();
    s = action(h,s,'outputs.0.route',0x1080); h.confirm(s);
    s.outputs[0].route |= 0x2000;
    assert.equal(h.patch('undo',s).changes[0].mask,1);
    s.outputs[0].route |= 1;
    assert.throws(() => h.patch('undo',s),/conflict/);
});
test('compound fields and hidden linked members are leaf deltas, not snapshots', () => {
    const h = new EditHistory(units); let s = state();
    s = action(h,s,'outputs.0.peq.0',{freq:200,gain:120}); h.confirm(s);
    s.outputs[0].peq[0].gain=140;
    assert.deepEqual(h.patch('undo',s).changes,[{path:'outputs.0.peq.0.freq',before:200,after:100}]);
    s.outputs[6].link=192; s.outputs[7].link=0;
    assert.ok(h.patch('undo',s));
    s.outputs[0].link=3; s.outputs[1].link=0;
    assert.throws(() => h.patch('undo',s),/link/);
});
test('unknown values stop history; metadata never enters it', () => {
    const h = new EditHistory(units); let s = state();
    s = action(h,s,'outputs.0.gain',300);h.confirm(s);
    s = action(h,s,'aux.mute',true);h.confirm(s);
    assert.equal(h.undo.length,0);
    assert.match(h.message,/unknown/);
    h.change('raw','old','new',s);assert.equal(h.current,null);
});
test('normalization changes the last queued logical value only', () => {
    const h = new EditHistory(units);let s=state();
    s=action(h,s,'outputs.0.gain',290);
    s=action(h,s,'outputs.0.gain',300);
    h.confirm(s);
    assert.equal(h.undo[0].changes[0].after,290);
    assert.equal(h.undo[1].changes[0].before,290);
});
test('bulk rider configuration and device changes replay as one action', () => {
    const h = new EditHistory(units);const before=state();const riders=[{channel:0,target:-18}];
    h.begin('recall',before,riders);const after=state();after.outputs[0].gain=300;
    h.end(after,[],true);h.confirm(after);
    const patch=h.patch('undo',after,[]);
    assert.deepEqual(patch.riders,{channels:[0],before:[],after:riders});
    assert.ok(h.patch('undo',after,[{channel:2}]));
    assert.throws(() => h.patch('undo',after,[{channel:0,target:-20}]),/riders/);
});
test('raw incoming state stays independent from optimistic overlays', () => {
    const s=new Store();s.replace(state());s.set('outputs.0.gain',320);s.replace(state());
    assert.equal(s.state.outputs[0].gain,320);assert.equal(s.remote.outputs[0].gain,280);
});
test('native undo respects text inputs and contenteditable', () => {
    assert.equal(nativeUndo({closest:() => ({})}),true);
    assert.equal(nativeUndo({closest:() => null}),false);
});
