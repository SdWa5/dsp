/** Mocked history checks intercept every API request and keep device settings in this process. */
import assert from 'node:assert/strict';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const browser = await chromium.launch({ headless: true });
const results = [];
const get = (s,p) => p.split('.').reduce((v,k)=>v?.[k],s);
const set = (s,p,v) => { const a=p.split('.'),last=a.pop();let n=s;for(const k of a)n=n?.[k];assert.ok(n, `Missing mocked state at ${p}`);n[last]=v; };
try {
 for(const width of [1440,390]) {
  const page=await browser.newPage({ignoreHTTPSErrors:true,viewport:{width,height:1000},hasTouch:true});
  let remote,units,riders=[];const writes=[],errors=[];
  page.on('pageerror',e=>errors.push(e.message));
  await page.route('**/api/**',async route=>{
   const name=new URL(route.request().url()).pathname.replace('/api/','');
   const body=route.request().method()==='GET'?undefined:route.request().postDataJSON();
   let result={};
   if(name==='status')result={connected:true,bridge:true,target:'mock',riders,queued:0,resync:null};
   else if(name==='state')result=structuredClone(remote);
   else if(name==='autoeq')result={session:null,devices:[],backups:[],recorder:false,device:''};
   else if(name==='write'){
    writes.push({name,body});
    for(const command of body.commands){
     if(command.command==='copyChannel'){
      const side=command.args.target<8?'inputs':'outputs';remote[side][command.args.target%8]=structuredClone(remote[side][command.args.source%8]);continue;
     }
     if(command.command==='loadPreset') {remote.outputs[0].gain=180;remote.outputs[1].gain=180;riders=[];continue;}
     for(const unit of units.filter(u=>u.command===command.command&&Object.entries(u.args).every(([k,v])=>typeof v==='string'&&v.startsWith('@')||command.args[k]===v))){
      for(const [k,v] of Object.entries(unit.args))if(typeof v==='string'&&v.startsWith('@'))set(remote,v.slice(1),command.args[k]);
     }
    }
    result={queued:0};
   }else if(name==='history'){
    writes.push({name,body});
    for(const c of body.changes){const now=get(remote,c.path);assert.ok(c.mask?(now&c.mask)===(c.before&c.mask):JSON.stringify(now)===JSON.stringify(c.before));}
    for(const c of body.changes)set(remote,c.path,c.mask?(get(remote,c.path)&~c.mask)|(c.after&c.mask):c.after);
    if(body.riders)riders=[...riders.filter(r=>!body.riders.channels.includes(r.channel)),...body.riders.after].sort((a,b)=>a.channel-b.channel);
    result={queued:0};
   }else if(route.request().method()!=='GET'){writes.push({name,body});}
   await route.fulfill({json:result});
  });
  await page.addInitScript(()=>{window.EventSource=class extends EventTarget{
   constructor(){super();window.mockEvents=this;setTimeout(()=>{
    const s=JSON.parse(document.getElementById('sdwa5-dsp-defaults').textContent);
    Object.assign(s,{sub_extra:[0,0],tone_mode:0,tone_freq:0,working_status:0,device_id:1});
    s.inputs.forEach((c,i)=>c.link=1<<i);s.outputs.forEach((c,i)=>{c.link=1<<i;c.route=0x1000|1<<i;});
    s.inputs[0].link=3;s.inputs[1].link=0;s.outputs[0].link=3;s.outputs[1].link=0;
    window.historyBaseline=structuredClone(s);
    this.dispatchEvent(new MessageEvent('status',{data:JSON.stringify({connected:true,bridge:true,target:'mock',riders:[],queued:0,resync:null})}));
    this.dispatchEvent(new MessageEvent('state',{data:JSON.stringify(s)}));
   },30);}close(){}
  };});
  await page.goto((process.env.SDWA5_DSP_TEST_URL||'https://sdwa5-dsp.ddev.site')+'/#gain');
  await page.waitForFunction(()=>window.historyBaseline);
  remote=await page.evaluate(()=>window.historyBaseline);
  units=await page.locator('#sdwa5-dsp-history').evaluate(e=>JSON.parse(e.textContent));
  await page.evaluate(async()=>{const asset=performance.getEntriesByType('resource').find(e=>/\/assets\/lib\/device-[^/]+\.js/.test(e.name));window.testDevice=await import(asset.name);});
  const idle=async()=>page.waitForFunction(()=>!window.testDevice.history.history.busy&&!window.testDevice.history.history.pending.length&&!window.testDevice.history.history.current);
  const action=async(direction)=>{
   await page.getByRole('button',{name:'Edit',exact:true}).click();
   await page.locator(`[data-history-target=${direction}]`).click();
   // Menus open on hover as well as focus, so the pointer leaves the list as a user's would.
   await page.mouse.move(width-5,995);await idle();
  };
  const reset=async()=>{await idle();writes.length=0;remote=await page.evaluate(()=>window.historyBaseline);await page.evaluate(s=>{testDevice.history.reset();testDevice.store.edits.clear();testDevice.store.replace(s);},structuredClone(remote));};
  const gain=page.locator('section[data-page=gain] [data-fader-bind-value="outputs.0.gain"] input[type=range]');
  // A real keyboard range edit reaches both linked members, including a hidden one.
  await gain.focus();await gain.press('ArrowUp');await idle();
  assert.equal(await page.evaluate(()=>testDevice.history.history.undo.length),1);
  assert.equal(remote.outputs[0].gain,remote.outputs[1].gain);
  const after=remote.outputs[0].gain;
  await action('undo');assert.equal(remote.outputs[0].gain,await page.evaluate(()=>historyBaseline.outputs[0].gain));
  await action('redo');assert.equal(remote.outputs[0].gain,after);
  // Native text undo never submits a device history request.
  await page.locator('[data-editor-target=tab][data-page=inputs0]').click();
  const name=page.locator('section[data-page=inputs0] input[data-controller=text]').first();
  await name.focus();await name.press('Control+z');assert.equal(writes.filter(w=>w.name==='history').length,2);
  await page.evaluate(()=>{document.activeElement.blur();location.hash='#gain';document.querySelector('[data-editor-page-param=gain]').click();});
  await reset();await gain.focus();await gain.press('ArrowUp');await idle();
  // Shortcut synchronization and redo branching.
  await gain.press('Control+z');await idle();assert.equal(await page.evaluate(()=>testDevice.history.history.redo.length),1);
  await gain.press('Control+Shift+z');await idle();assert.equal(remote.outputs[0].gain,after);
  await gain.press('Control+z');await idle();await gain.press('ArrowDown');await idle();assert.equal(await page.evaluate(()=>testDevice.history.history.redo.length),0);
  // A conflicting external value leaves the stack untouched and emits no history write.
  await reset();await gain.focus();await gain.press('ArrowUp');await idle();remote.outputs[0].gain+=20;
  const before=writes.length;await action('undo');assert.equal(writes.length,before);assert.equal(await page.evaluate(()=>testDevice.history.history.undo.length),1);
  assert.match(await page.locator('[data-history-target=announcement]').textContent(),/conflict/);
  // Display settings, navigation and collapse controls do not enter history or write to the device.
  await reset();await page.evaluate(()=>document.querySelector('[data-editor-page-param=display]').click());
  await page.locator('section[data-page=display] input[data-channelview-target=channel][value="1"]').uncheck();
  assert.equal(writes.length,0);assert.equal(await page.evaluate(()=>testDevice.history.history.undo.length),0);
  await page.evaluate(()=>document.querySelector('[data-editor-page-param=gain]').click());
  await gain.focus();await gain.press('ArrowUp');await idle();assert.equal(remote.outputs[1].gain,after);
  await action('undo');assert.equal(remote.outputs[1].gain,await page.evaluate(()=>historyBaseline.outputs[1].gain));
  // Routing masks preserve independent external bits on replay and update the graph.
  await reset();await page.evaluate(()=>document.querySelector('[data-editor-page-param=matrix]').click());
  const cell=page.locator('.matrix:visible td[data-source="8"][data-output="7"] button');await cell.click();await idle();
  remote.outputs[7].route|=0x2000;await action('undo');assert.equal(remote.outputs[7].route,0x3080);
  assert.equal(await page.locator('.routing__edge[data-source="8"][data-output="7"]').count(),0);
  // Bulk copies record a single action and never repeat copyChannel during replay.
  await reset();remote.outputs[0].gain=320;await page.evaluate(s=>{testDevice.store.edits.clear();testDevice.store.replace(s);},remote);
  await page.evaluate(async()=>{await testDevice.history.run('Copy channel',()=>testDevice.send([{command:'copyChannel',args:{source:8,target:9}}],false),{paths:testDevice.history.history.paths.filter(p=>p.startsWith('outputs.1.'))});});
  assert.equal(await page.evaluate(()=>testDevice.history.history.undo.length),1);await action('undo');assert.equal(remote.outputs[1].gain,await page.evaluate(()=>historyBaseline.outputs[1].gain));
  assert.equal(writes.filter(w=>w.name==='write'&&w.body.commands.some(c=>c.command==='copyChannel')).length,1);
  // A recalled live configuration is reversible without another slot recall.
  await reset();await page.evaluate(async()=>{await testDevice.history.run('Recall preset',()=>testDevice.send([{command:'loadPreset',args:{number:1}}],false));});
  await action('undo');assert.equal(remote.outputs[0].gain,await page.evaluate(()=>historyBaseline.outputs[0].gain));
  assert.equal(writes.filter(w=>w.name==='write'&&w.body.commands.some(c=>c.command==='loadPreset')).length,1);
  await page.screenshot({path:`/tmp/sdwa5-dsp-history-${width}.png`,fullPage:true});
  assert.deepEqual(errors,[]);
  await page.reload();await page.waitForFunction(()=>window.testDevice===undefined&&window.historyBaseline);assert.equal(await page.locator('[data-history-target=undo]').isDisabled(),true);
  results.push({width,history:'controls, shortcuts, linked edits, masks, bulk operations and conflicts',errors});
  await page.close();
 }
 console.log(JSON.stringify(results));
}finally{await browser.close();}
