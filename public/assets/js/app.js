(() => {
  const $ = (s,root=document)=>root.querySelector(s), $$=(s,root=document)=>[...root.querySelectorAll(s)];
  $$('[data-menu]').forEach(btn=>btn.addEventListener('click',e=>{e.stopPropagation();const id=btn.dataset.menu;const menu=document.getElementById(id);$$('.menu').forEach(m=>{if(m!==menu)m.hidden=true});if(menu)menu.hidden=!menu.hidden;}));
  document.addEventListener('click',()=>$$('.menu').forEach(m=>m.hidden=true));
  $$('[data-popover]').forEach(btn=>btn.addEventListener('click',e=>{e.stopPropagation();const p=document.getElementById(btn.dataset.popover);$$('.popover').forEach(x=>{if(x!==p)x.hidden=true});p.hidden=!p.hidden;}));
  document.addEventListener('click',e=>{if(!e.target.closest('.popover'))$$('.popover').forEach(p=>p.hidden=true)});
  $$('[data-drawer-open]').forEach(a=>a.addEventListener('click',e=>{e.preventDefault();const d=document.getElementById(a.dataset.drawerOpen);if(d){d.hidden=false;document.body.style.overflow='hidden';}}));
  $$('[data-drawer-close]').forEach(b=>b.addEventListener('click',()=>{const d=b.closest('[data-drawer]');if(d)d.hidden=true;document.body.style.overflow=''}));
  $$('[data-copy]').forEach(b=>b.addEventListener('click',async()=>{const t=document.getElementById(b.dataset.copy)?.textContent||b.dataset.copyText||'';const old=b.innerHTML;try{if(navigator.clipboard?.writeText){await navigator.clipboard.writeText(t);}else{const ta=document.createElement('textarea');ta.value=t;ta.setAttribute('readonly','');ta.style.position='fixed';ta.style.opacity='0';document.body.appendChild(ta);ta.select();document.execCommand('copy');ta.remove();}b.textContent='Copied';setTimeout(()=>b.innerHTML=old,1200);}catch(_){b.textContent='Copy failed';setTimeout(()=>b.innerHTML=old,1200);}}));
  $$('[data-toggle-submit]').forEach(inp=>inp.addEventListener('change',()=>inp.closest('form')?.submit()));
  $$('[data-confirm-scan]').forEach(b=>b.addEventListener('click',()=>{const m=document.getElementById('scanConfirm');if(m)m.hidden=false;}));
  $$('[data-scan-submit]').forEach(b=>{const f=b.closest('form');if(!f||f.matches('[data-progressive-scan-form]'))return;f.addEventListener('submit',()=>{b.disabled=true;b.textContent='Scanning evidence…';});});
  $$('[data-modal-open]').forEach(b=>b.addEventListener('click',()=>{const m=document.getElementById(b.dataset.modalOpen);if(m)m.hidden=false;}));
  $$('[data-modal-close]').forEach(b=>b.addEventListener('click',()=>{const m=b.closest('.modal-layer');if(m)m.hidden=true;}));
  const acc=$('[data-snippet-toggle]'); if(acc)acc.addEventListener('click',()=>{const box=$('#snippetBox');box.hidden=!box.hidden;});
  const filterForm=$('#sessionFilters'); if(filterForm){$$('[data-filter-auto]',filterForm).forEach(x=>x.addEventListener('change',()=>filterForm.submit()));}
})();

// Readiness progressive scanner — one bounded server phase per request, live evidence updates.
(() => {
  const root=document.querySelector('[data-scan-controller]');
  if(!root)return;
  const $=(s,r=document)=>r.querySelector(s), $$=(s,r=document)=>[...r.querySelectorAll(s)];
  const form=$('[data-progressive-scan-form]');
  const modal=$('#scanConfirm');
  const track=$('[data-scan-track]',root);
  const liveSection=$('[data-scan-live-results]');
  const feed=$('[data-scan-live-feed]');
  const warningBox=$('[data-scan-warning-box]');
  const warningList=$('[data-scan-warnings]');
  const scanButton=$('[data-confirm-scan]');
  const buttonLabel=$('[data-scan-button-label]');
  const stateEl=$('[data-scan-state]',root), messageEl=$('[data-scan-message]',root);
  const checkedEl=$('[data-scan-checked]',root), progressEl=$('[data-scan-progress]',root);
  const scoreEl=$('[data-live-score]'), readyEl=$('[data-summary-ready]'), attentionEl=$('[data-summary-attention]'), pendingEl=$('[data-summary-pending]');
  const readyBar=$('[data-scan-ready]',root), attentionBar=$('[data-scan-attention]',root), reviewedBar=$('[data-scan-reviewed]',root);
  const scoreDelta=$('[data-score-delta]'), scoreExplain=$('[data-score-explain]');
  let scanId=Number(root.dataset.activeScanId||0), stepping=false, done=false;
  const seenFeed=new Set();let feedQueue=[],feedTimer=null,summaryAnimToken=0;
  let visual={checked:0,ready:0,attention:0};
  const coverageClass=s=>['complete','fresh','connected'].includes(s)?'ok':['blocked','failed'].includes(s)?'bad':['stale','stored','skipped','disconnected','disabled','none'].includes(s)?'limited':'';
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const showScanUi=()=>{
    if(modal)modal.hidden=true;
    if(liveSection)liveSection.hidden=false;
    track?.classList.add('scanning');
    if(scanButton)scanButton.disabled=true;
    if(buttonLabel)buttonLabel.textContent='Scanning…';
    if(stateEl)stateEl.textContent='SCANNING…';
    root.querySelector('[data-scan-linear-wrap]')?.scrollIntoView({behavior:'auto',block:'center'});
  };
  const stopScanUi=(failed=false)=>{
    track?.classList.remove('scanning');
    if(scanButton)scanButton.disabled=false;
    if(buttonLabel)buttonLabel.textContent='AI Scan again';
    if(stateEl)stateEl.textContent=failed?'SCAN STOPPED':'SCAN COMPLETE';
  };
  const showWarnings=(warnings=[])=>{
    if(!warningBox||!warningList)return;
    warningBox.hidden=!warnings.length;
    warningList.innerHTML=warnings.map(w=>`<div>• ${esc(w)}</div>`).join('');
  };
  const updateCoverage=(coverage={})=>{
    $$('[data-coverage-key]',root).forEach(el=>{
      const v=coverage[el.dataset.coverageKey]||{};
      const state=typeof v==='string'?v:(v.state||'none');
      const detail=typeof v==='object'?(v.detail||''):'';
      el.classList.remove('ok','bad','limited');const cls=coverageClass(state);if(cls)el.classList.add(cls);
      el.dataset.state=state;el.title=detail;
      const base=el.dataset.coverageKey==='url'?'URL':el.dataset.coverageKey==='pages'?'Pages':el.dataset.coverageKey==='source'?'Source':el.dataset.coverageKey==='runtime'?'Runtime':'AI';
      el.textContent=base+(!['none','queued','complete'].includes(state)?` · ${state.replaceAll('_',' ')}`:'');
    });
  };
  const paintCheckRow=c=>{
    const row=$$('[data-check-row]').find(x=>x.dataset.checkRow===String(c.key||''));if(!row)return;
    row.dataset.liveStatus=c.status||'pending';
    const sev=$('.finding-sev',row),desc=$('.finding-desc',row);
    if(sev)sev.textContent=c.status==='ready'?'✓':c.status==='need_attention'?'△':'◌';
    if(desc&&c.description)desc.textContent=c.description;
    row.classList.add('scan-updated');setTimeout(()=>row.classList.remove('scan-updated'),900);
  };
  const pumpFeed=()=>{
    if(feedTimer||!feedQueue.length||!feed)return;
    const next=()=>{
      const c=feedQueue.shift();if(!c){feedTimer=null;return;}
      const key=`${c.key}|${c.status}|${c.source}|${c.evidence}`;
      if(!seenFeed.has(key)){
        seenFeed.add(key);const state=c.status==='ready'?'ready':c.status==='need_attention'?'need_attention':'pending';const icon=state==='ready'?'✓':state==='need_attention'?'△':'◌';
        const item=document.createElement('div');item.className=`scan-live-item ${state}`;
        item.innerHTML=`<span class="state">${icon}</span><div><strong>${esc(c.title)}</strong><div class="muted">${esc(c.description||c.evidence||'Evidence updated.')}</div></div><small>${esc(String(c.source||'evidence').toUpperCase())}</small>`;
        feed.prepend(item);paintCheckRow(c);while(feed.children.length>12)feed.lastElementChild?.remove();
      }
      feedTimer=setTimeout(()=>{feedTimer=null;next();},115);
    };next();
  };
  const updateFeed=(changes=[])=>{if(!feed)return;feedQueue.push(...[...changes].reverse());pumpFeed();};
  const updateEvents=(events=[])=>{
    if(!feed)return;
    for(const ev of events){
      const key=`event|${ev.phase||''}|${ev.text||''}`;if(seenFeed.has(key))continue;seenFeed.add(key);
      const state=ev.state==='complete'?'ready':ev.state==='warning'?'need_attention':'pending';
      const icon=state==='ready'?'✓':state==='need_attention'?'!':'…';const item=document.createElement('div');item.className=`scan-live-item ${state} phase-event`;
      item.innerHTML=`<span class="state">${icon}</span><div><strong>${esc(String(ev.phase||'scan').replaceAll('_',' ').toUpperCase())}</strong><div class="muted">${esc(ev.text||'Phase updated.')}</div></div><small>LIVE</small>`;
      feed.prepend(item);while(feed.children.length>12)feed.lastElementChild?.remove();
    }
  };
  const resetLiveDisplay=()=>{
    summaryAnimToken++;visual={checked:0,ready:0,attention:0};
    const total=Number(root.dataset.applicable||0);
    if(scoreEl)scoreEl.textContent='0';if(readyEl)readyEl.textContent='0';if(attentionEl)attentionEl.textContent='0';if(pendingEl)pendingEl.textContent=String(total);
    if(checkedEl)checkedEl.textContent=`0 of ${total} checked`;
    if(readyBar)readyBar.style.width='0%';if(attentionBar)attentionBar.style.width='0%';if(reviewedBar)reviewedBar.style.width='0%';
  };
  const animateLiveSummary=(s,sp,live)=>{
    const total=Math.max(0,Number(sp.total??live.applicable??root.dataset.applicable??0));
    const final=s.status==='completed';
    const target={
      checked:final?total:Math.max(0,Number(sp.checked||0)),
      ready:Math.max(0,Number(live.ready||0)),
      attention:Math.max(0,Number(live.need_attention||0))
    };
    const start={...visual},token=++summaryAnimToken,startAt=performance.now(),duration=final?420:620;
    const draw=(v)=>{
      visual=v;const denom=Math.max(1,total);const pending=Math.max(0,total-v.ready-v.attention);
      if(scoreEl)scoreEl.textContent=String(total?Math.round(v.ready/total*100):0);
      if(readyEl)readyEl.textContent=String(v.ready);if(attentionEl)attentionEl.textContent=String(v.attention);if(pendingEl)pendingEl.textContent=String(pending);
      if(checkedEl)checkedEl.textContent=`${v.checked} of ${total} checked in this scan`;
      if(readyBar)readyBar.style.width=`${Math.min(100,v.ready/denom*100)}%`;
      if(attentionBar)attentionBar.style.width=`${Math.min(100,v.attention/denom*100)}%`;
      if(reviewedBar)reviewedBar.style.width=`${Math.min(100,Math.max(0,v.checked-v.ready-v.attention)/denom*100)}%`;
    };
    const tick=now=>{
      if(token!==summaryAnimToken)return;const t=Math.min(1,(now-startAt)/duration),ease=1-Math.pow(1-t,3);
      const v={checked:Math.round(start.checked+(target.checked-start.checked)*ease),ready:Math.round(start.ready+(target.ready-start.ready)*ease),attention:Math.round(start.attention+(target.attention-start.attention)*ease)};
      draw(v);if(t<1)requestAnimationFrame(tick);else draw(target);
    };
    requestAnimationFrame(tick);
  };
  const render=s=>{
    if(!s||!s.ok)return;
    const live=s.liveSummary||s.summary||{};
    if(messageEl)messageEl.textContent=s.message||'Scanning evidence…';
    if(progressEl)progressEl.textContent=s.status==='completed'?'100%':`${Math.max(1,Number(s.progress||1))}% · ${(s.phase||'scan').replaceAll('_',' ')}`;
    const sp=s.scanProgress||{}, ready=Number(sp.ready||0), attention=Number(sp.attention||0), checked=Number(sp.checked||0);
    animateLiveSummary(s,sp,live);
    if(track)track.style.setProperty('--scan-phase-progress',`${Math.max(1,Math.min(100,Number(s.progress||1)))}%`);
    updateCoverage(s.coverage||{});updateEvents(s.events||[]);updateFeed(s.changes||[]);showWarnings(s.warnings||[]);
    if(scoreDelta){const d=Number(s.scoreDelta||0);scoreDelta.textContent=d===0?'Current scan: no score change':`Current scan: ${d>0?'+':''}${d} points`;scoreDelta.classList.toggle('up',d>0);scoreDelta.classList.toggle('down',d<0);}
    if(scoreExplain){
      scoreExplain.hidden=false;
      if(s.status!=='completed'){scoreExplain.textContent=`Live scan: ${checked} of ${Number(sp.total||0)} checked · ${ready} ready · ${attention} need attention. Pending means evidence is not sufficient yet.`;}
      else{const r=s.scoreReason||{},parts=[];if(Number(r.ready_delta||0))parts.push(`${Number(r.ready_delta)>0?'+':''}${Number(r.ready_delta)} ready`);if(Number(r.attention_delta||0))parts.push(`${Number(r.attention_delta)>0?'+':''}${Number(r.attention_delta)} attention`);if(Number(r.pending_delta||0))parts.push(`${Number(r.pending_delta)>0?'+':''}${Number(r.pending_delta)} pending`);scoreExplain.textContent=parts.length?`Why the stored readiness score changed: ${parts.join(' · ')}`:'The stored readiness check totals did not change.';}
    }
  };
  const request=async(url,options={})=>{
    const r=await fetch(url,{credentials:'same-origin',headers:{'Accept':'application/json',...(options.headers||{})},...options});
    const text=await r.text();let j;try{j=JSON.parse(text)}catch{j={ok:false,error:text||`HTTP ${r.status}`}};
    if(!r.ok||!j.ok){const e=new Error(j.error||`HTTP ${r.status}`);e.payload=j;throw e;}return j;
  };
  const step=async()=>{
    if(!scanId||stepping||done)return;stepping=true;showScanUi();
    try{
      const body=new URLSearchParams();body.set('_csrf',root.dataset.csrf||'');
      const s=await request(`${root.dataset.stepPrefix}${scanId}/step`,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:body.toString()});
      render(s);
      if(s.status==='completed'){
        done=true;stopScanUi(false);
        if(messageEl)messageEl.textContent=s.message||((s.warnings||[]).length?'Scan complete with limited coverage.':'Scan complete.');
        setTimeout(()=>location.assign(`/builds/${encodeURIComponent(root.dataset.projectSlug)}/dashboard/readiness/overview?rescanned=1`),900);
        return;
      }
      setTimeout(step,720);
    }catch(err){
      stopScanUi(true);showWarnings([err.message||'The scan stopped safely.']);if(messageEl)messageEl.textContent=err.message||'The scan stopped safely.';
      // The server marks a failed progressive scan terminal. Clear the client lock so
      // the owner can start a fresh scan from the same page without a forced reload.
      scanId=0;root.dataset.activeScanId='0';done=false;
    }finally{stepping=false;}
  };
  form?.addEventListener('submit',async e=>{
    e.preventDefault();if(stepping||scanId)return;
    showScanUi();
    if(feed)feed.innerHTML='';seenFeed.clear();feedQueue=[];if(feedTimer){clearTimeout(feedTimer);feedTimer=null;}
    resetLiveDisplay();if(track)track.style.setProperty('--scan-phase-progress','1%');
    if(progressEl)progressEl.textContent='1% · starting';if(messageEl)messageEl.textContent='Starting fresh evidence scan…';
    const btn=$('[data-scan-submit]',form);if(btn){btn.disabled=true;btn.textContent='Starting…';}
    try{
      const s=await request(root.dataset.startUrl,{method:'POST',body:new FormData(form)});scanId=Number(s.scanId||0);root.dataset.activeScanId=String(scanId);render(s);setTimeout(step,80);
    }catch(err){
      if(err.payload?.scanId){scanId=Number(err.payload.scanId);root.dataset.activeScanId=String(scanId);setTimeout(step,80);return;}
      stopScanUi(true);showWarnings([err.message||'Could not start the scan.']);if(messageEl)messageEl.textContent=err.message||'Could not start the scan.';
    }finally{if(btn){btn.disabled=false;btn.textContent='Start scan';}}
  });
  if(scanId){showScanUi();resetLiveDisplay();if(track)track.style.setProperty('--scan-phase-progress','1%');setTimeout(step,120);}
})();

// Sessions interactions
(() => {
  const $$=(s,r=document)=>[...r.querySelectorAll(s)], $=(s,r=document)=>r.querySelector(s);
  $$('[data-duration-min]').forEach(b=>b.addEventListener('click',()=>{const i=$('#minDuration');if(i){i.value=b.dataset.durationMin==='0'?'':b.dataset.durationMin;i.closest('form')?.submit();}}));
  const cs=$('[data-country-search]');if(cs)cs.addEventListener('input',()=>{const q=cs.value.toLowerCase();$$('.country-option').forEach(x=>x.hidden=!x.textContent.toLowerCase().includes(q));});
  const us=$('[data-user-search]');if(us)us.addEventListener('input',()=>{const q=us.value.toLowerCase();$$('.user-option').forEach(x=>x.hidden=!x.textContent.toLowerCase().includes(q));});
  const data=$('#replayData'); if(data){let items=[];try{items=JSON.parse(data.textContent||'[]')}catch{};const frame=$('#replayFrame'),range=$('#replayRange'),time=$('#replayTime');let idx=0,timer=null;
    const show=i=>{if(!items.length)return;idx=Math.max(0,Math.min(items.length-1,i));const item=items[idx];if(frame)frame.srcdoc=item.html||'<p>No snapshot</p>';if(range)range.value=String(idx);if(time)time.textContent=`${item.time||''}${item.url?' · '+item.url:''}`;};
    show(0);range?.addEventListener('input',()=>{clearInterval(timer);timer=null;show(Number(range.value))});
    $('[data-replay-prev]')?.addEventListener('click',()=>show(idx-1));$('[data-replay-next]')?.addEventListener('click',()=>show(idx+1));
    $('[data-replay-play]')?.addEventListener('click',e=>{if(timer){clearInterval(timer);timer=null;e.currentTarget.textContent='▶';return;}e.currentTarget.textContent='Ⅱ';timer=setInterval(()=>{if(idx>=items.length-1){clearInterval(timer);timer=null;e.currentTarget.textContent='▶';return;}show(idx+1)},1800);});
  }
})();

// Stage 5 final interactions
(() => {
  const $=(s,r=document)=>r.querySelector(s), $$=(s,r=document)=>[...r.querySelectorAll(s)];
  $$('[data-sidebar-toggle]').forEach(b=>b.addEventListener('click',()=>$('#projectSidebar')?.classList.toggle('open')));
  $$('[data-account-sidebar-toggle]').forEach(b=>b.addEventListener('click',()=>$('#accountSidebar')?.classList.toggle('open')));
  $$('[data-confirm]').forEach(f=>f.addEventListener('submit',e=>{if(!confirm(f.dataset.confirm||'Continue?'))e.preventDefault()}));
  $$('[data-count-target]').forEach(el=>{const target=document.getElementById(el.dataset.countTarget);const update=()=>{if(target)target.textContent=`${el.value.length}/${el.maxLength||200}`};el.addEventListener('input',update);update();});
  const cycle=$('#billingCycle'), price=$('#planPrice');
  $$('[data-plan-cycle]').forEach(b=>b.addEventListener('click',()=>{ $$('[data-plan-cycle]').forEach(x=>x.classList.remove('active'));b.classList.add('active');if(cycle)cycle.value=b.dataset.planCycle;if(price)price.innerHTML=b.dataset.planCycle==='annual'?'$250 <small>/year</small>':'$25 <small>/month</small>'; }));
  const chart=$('[data-usage-chart]'); if(chart){let data={};try{data=JSON.parse(chart.dataset.usageChart||'{}')}catch{};const bars=$('#usageBars');const render=(key)=>{const d=data[key]||{};const vals=Object.values(d).map(Number),max=Math.max(1,...vals);if(bars)bars.innerHTML=Object.entries(d).map(([label,value])=>`<div class="usage-bar-item"><div class="usage-bar-value">${Number(value).toLocaleString()} tokens</div><div class="usage-bar" style="height:${Math.max(4,Number(value)/max*120)}px"></div><div class="usage-bar-label">${label}</div></div>`).join('');};render('scanFix');$$('[data-usage-tab]').forEach(b=>b.addEventListener('click',()=>{$$('[data-usage-tab]').forEach(x=>x.classList.remove('active'));b.classList.add('active');render(b.dataset.usageTab)}));}
})();

// Public Production Audit — evidence-first URL inspection.
(() => {
  const root=document.querySelector('[data-public-audit]');
  if(!root) return;
  const csrf=root.dataset.csrf||'';
  const state=root.querySelector('[data-audit-state]');
  const note=root.querySelector('[data-audit-note]');
  const errorBox=root.querySelector('[data-audit-error]');
  const rows=[...root.querySelectorAll('[data-audit-check]')];
  const setAllRunning=()=>rows.forEach((row,i)=>{row.classList.add('running');const em=row.querySelector('em');if(em && i===0) em.textContent='checking…';});
  const apply=(checks)=>{
    const map=new Map((checks||[]).map(c=>[c.key,c]));
    rows.forEach(row=>{
      const c=map.get(row.dataset.auditCheck); if(!c) return;
      row.classList.remove('running');row.classList.add(c.state||'unknown');
      const em=row.querySelector('em'); if(em) em.textContent=c.state==='verified'?'verified':c.state==='attention'?'needs attention':c.state==='not_applicable'?'not applicable':'needs more evidence';
    });
  };
  setAllRunning();
  const body=new URLSearchParams();body.set('_csrf',csrf);
  fetch('/audit/execute',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','Accept':'application/json'},body:body.toString(),credentials:'same-origin'})
    .then(async r=>{const j=await r.json().catch(()=>({ok:false,error:'Invalid server response'}));if(!r.ok||!j.ok)throw new Error(j.error||'Inspection failed');return j;})
    .then(j=>{apply(j.checks);if(state)state.textContent='COMPLETE';if(note)note.textContent='review is ready.';setTimeout(()=>location.assign('/audit/result'),650);})
    .catch(err=>{if(state)state.textContent='FAILED';if(note)note.textContent='inspection stopped.';if(errorBox){errorBox.hidden=false;errorBox.textContent=err.message||'The inspection could not be completed.';}});
})();
