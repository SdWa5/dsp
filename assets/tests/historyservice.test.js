import assert from 'node:assert/strict';
import { test } from 'node:test';
import { HistoryService } from '../lib/historyservice.js';
import { Store, setPath, getPath } from '../lib/store.js';
import { SendQueue } from '../lib/queue.js';

function setup() {
    const store=new Store();
    let remote={outputs:Array.from({length:8}, (_,i)=>({gain:280,link:1<<i})),inputs:Array.from({length:8}, (_,i)=>({gain:280,link:1<<i})),aux:{mute:null}};
    store.replace(remote);
    const status={connected:true,target:'mock',riders:[]};
    const calls=[],errors=[];
    const api=async (path,body) => {
        calls.push({path,body});
        if(path==='status')return {...status,queued:0,resync:null};
        if(path==='state')return structuredClone(remote);
        if(path==='history') { body.changes.forEach(c=>setPath(remote,c.path,c.after)); return {queued:0}; }
        if(path==='write') { body.commands.forEach(c=>remote.outputs[c.args.channel-8].gain=c.args.raw); return {queued:0}; }
        return {};
    };
    const queue=new SendQueue(commands=>api('write',{commands}));
    const units=[{command:'gain',args:{raw:'@outputs.0.gain'}},{command:'gain',args:{raw:'@outputs.1.gain'}},{command:'mute',args:{on:'@aux.mute'}}];
    const h=new HistoryService({store,queue,api,status,updateStatus:data=>Object.assign(status,data),units,report:m=>errors.push(m)});
    return {h,store,queue,status,calls,errors,remote};
}
const wait=async h=>{await new Promise(r=>setTimeout(r,0));if(h.pump)await h.pump;};
test('completed linked edits are one action, replay preserves direction, preferences and incoming state create none',async()=>{
 const {h,store,queue,calls}=setup();h.begin('linked');
 store.set('outputs.0.gain',300);store.set('outputs.1.gain',300);
 await queue.push({command:'gain',args:{channel:8,raw:300}});await queue.push({command:'gain',args:{channel:9,raw:300}});h.end();await wait(h);
 assert.equal(h.history.undo.length,1);assert.equal(h.history.undo[0].changes.length,2);
 await h.replay('undo');assert.equal(store.get('outputs.0.gain'),280);assert.equal(h.history.redo.length,1);
 await h.replay('redo');assert.equal(store.get('outputs.0.gain'),300);
 store.replace(structuredClone(store.state));assert.equal(h.history.undo.length,1);
 assert.equal(calls.filter(c=>c.path==='history').length,2);h.disconnect();
});
test('external conflicts block replay without writes or moving history',async()=>{
 const {h,store,queue,remote,calls}=setup();store.set('outputs.0.gain',300);await queue.push({command:'gain',args:{channel:8,raw:300}});await wait(h);
 remote.outputs[0].gain=320;await h.replay('undo');
 assert.equal(calls.filter(c=>c.path==='history').length,0);assert.equal(h.history.undo.length,1);assert.match(h.history.message,/conflict/);h.disconnect();
});
test('bulk diff scope excludes unrelated automatic settings and includes rider changes',async()=>{
 const {h,remote,status}=setup();await h.run('copy',async()=>{remote.outputs[0].gain=300;remote.outputs[1].gain=310;status.riders=[{channel:0,target:-18}];},{paths:['outputs.0.gain']});
 assert.deepEqual(h.history.undo[0].changes.map(c=>c.path),['outputs.0.gain']);assert.equal(h.history.undo[0].ridersAfter.length,1);h.disconnect();
});
test('queue failures refresh and clear uncertain history',async()=>{
 const {h,store,queue,calls}=setup();queue.post=async()=>{throw new Error('failed');};
 store.set('outputs.0.gain',300);await queue.push({command:'gain',args:{channel:8,raw:300}});await wait(h);
 assert.equal(h.history.undo.length,0);assert.ok(calls.some(c=>c.path==='refresh'));assert.equal(store.get('outputs.0.gain'),280);h.disconnect();
});
test('pointer cancellation closes applied edits, wheel bursts coalesce, empty previews do not record',async()=>{
 const {h,store,queue}=setup();h.hold('pointer');h.release('pointer');await wait(h);assert.equal(h.history.undo.length,0);
 h.hold('pointer');store.set('outputs.0.gain',300);await queue.push({command:'gain',args:{channel:8,raw:300}});h.release('pointer');await wait(h);assert.equal(h.history.undo.length,1);
 h.wheel('gain');store.set('outputs.0.gain',301);h.wheel('gain');store.set('outputs.0.gain',302);await queue.push({command:'gain',args:{channel:8,raw:302}});h.release('gain');await wait(h);assert.equal(h.history.undo.length,2);assert.equal(h.history.undo.at(-1).changes[0].before,300);h.disconnect();
});
test('reset invalidates an in-flight confirmation from the previous target',async()=>{
 const {h,store,queue}=setup();let release;const old=h.api;h.api=async(path,...args)=>path==='state'?await new Promise(r=>{release=r;}):old(path,...args);
 store.set('outputs.0.gain',300);await queue.push({command:'gain',args:{channel:8,raw:300}});
 while(!release)await new Promise(r=>setTimeout(r,0));h.reset('new target');release({outputs:[{gain:300}]});await wait(h);assert.equal(h.history.undo.length,0);assert.equal(h.history.message,'new target');h.disconnect();
});
