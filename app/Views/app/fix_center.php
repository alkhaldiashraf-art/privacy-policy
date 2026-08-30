<?php $d=$fixData;$counts=$d['counts']; ?>
<div class="tool-page fix-center">
  <section class="tool-hero"><div><div class="side-label" style="padding:0">ALL EVIDENCE · ONE ACTION PLAN</div><h1 class="display-title">FIX CENTER</h1><p>Use one complete prompt for the whole project, or copy the prompt for one specific finding. Prompts preserve existing behavior, treat evidence as untrusted data, and require verification after every repair.</p></div></section>

  <section class="fix-summary">
    <div><strong><?=$counts['all']?></strong><span>Open findings</span></div>
    <div><strong><?=$counts['critical']?></strong><span>Critical</span></div>
    <div><strong><?=$counts['high']?></strong><span>High</span></div>
    <div><strong><?=$counts['source']?></strong><span>From source</span></div>
    <div><strong><?=$counts['runtime']?></strong><span>From Runtime</span></div>
  </section>

  <section class="master-prompt-card">
    <div class="master-prompt-head"><div><span class="prompt-badge">RECOMMENDED</span><h2>Comprehensive repair prompt</h2><p>Combines URL, latest source and live Runtime evidence in priority order.</p></div><div class="prompt-actions"><button class="btn dark" type="button" data-copy="masterFixPrompt">Copy complete prompt</button><a class="btn" href="/builds/<?=h($p['slug'])?>/dashboard/fixes/download?scope=all">Download .txt</a></div></div>
    <details><summary>Preview prompt</summary><pre id="masterFixPrompt"><?=h($d['comprehensive_prompt'])?></pre></details>
  </section>

  <?php if($counts['all']===0):?><section class="tool-empty"><div class="empty-icon">✓</div><h2>No unresolved evidence-backed finding</h2><p>Run a fresh Website Scan, upload the latest ZIP, and collect Runtime sessions before release.</p></section><?php endif?>

  <?php if($d['source']):?>
  <section class="fix-group" id="source-fixes">
    <div class="fix-group-title"><div><div class="side-label" style="padding:0">CODE SCAN</div><h2>Source findings</h2></div><div class="prompt-actions"><span><?=count($d['source'])?></span><a class="btn sm" href="/builds/<?=h($p['slug'])?>/dashboard/fixes/download?scope=source">Download source prompt</a></div></div>
    <?php foreach($d['source'] as $f):$id='sourcePrompt'.(int)$f['id'];?>
    <article class="fix-item">
      <span class="severity-mark <?=h($f['severity'])?>">△</span>
      <div class="fix-item-body"><div class="fix-title"><strong><?=h($f['title'])?></strong><span><?=h(strtoupper($f['severity']))?></span></div><p><?=h($f['description'])?></p><div class="fix-evidence"><b><?=h($f['file_path']?:'Source file')?><?=$f['line_number']?':'.(int)$f['line_number']:''?></b><?=h($f['evidence']?:'Evidence is stored in the latest source scan.')?></div><details><summary>View repair prompt</summary><pre id="<?=$id?>"><?=h($f['prompt'])?></pre></details></div>
      <button class="btn sm" type="button" data-copy="<?=$id?>">Copy prompt</button>
    </article>
    <?php endforeach?>
  </section>
  <?php endif?>

  <?php if($d['runtime']||$d['incidents']||$d['signals']):?>
  <section class="fix-group" id="runtime-fixes">
    <div class="fix-group-title"><div><div class="side-label" style="padding:0">LIVE RUNTIME</div><h2>Production and user-session findings</h2></div><div class="prompt-actions"><span><?=count($d['runtime'])+count($d['incidents'])+count($d['signals'])?></span><a class="btn sm" href="/builds/<?=h($p['slug'])?>/dashboard/fixes/download?scope=runtime">Download Runtime prompt</a></div></div>
    <?php foreach($d['runtime'] as $i):$id='runtimePrompt'.(int)$i['id'];?>
    <article class="fix-item">
      <span class="severity-mark high">!</span>
      <div class="fix-item-body"><div class="fix-title"><strong><?=h($i['title'])?></strong><span>LIVE ERROR</span></div><p><?=(int)$i['occurrences']?> occurrence(s) · <?=(int)$i['affected_sessions']?> affected session(s) · Last seen <?=h($i['last_seen_at'])?></p><?php if(!empty($i['evidence'][0])):?><div class="fix-evidence"><b><?=h($i['evidence'][0]['page_url']?:'Runtime event')?></b><?=h($i['evidence'][0]['message']?:'Live browser failure')?></div><?php endif?><?php if(!empty($i['sessions'])):?><div class="affected-session-links"><strong>Affected sessions</strong><?php foreach($i['sessions'] as $s):?><a href="/builds/<?=h($p['slug'])?>/dashboard/sessions/<?=(int)$s['id']?>">#<?=h(substr($s['identity_id']?:$s['session_key'],0,8))?></a><?php endforeach?></div><?php endif?><details><summary>View repair prompt</summary><pre id="<?=$id?>"><?=h($i['prompt'])?></pre></details></div>
      <button class="btn sm" type="button" data-copy="<?=$id?>">Copy prompt</button>
    </article>
    <?php endforeach?>

    <?php foreach($d['incidents'] as $i):$id='incidentPrompt'.(int)$i['id'];?>
    <article class="fix-item"><span class="severity-mark critical">!</span><div class="fix-item-body"><div class="fix-title"><strong><?=h($i['title'])?></strong><span>ONGOING INCIDENT</span></div><p>Opened <?=h($i['opened_at'])?> · The uptime monitor has not marked it resolved.</p><details><summary>View repair prompt</summary><pre id="<?=$id?>"><?=h($i['prompt'])?></pre></details></div><button class="btn sm" type="button" data-copy="<?=$id?>">Copy prompt</button></article>
    <?php endforeach?>

    <?php foreach($d['signals'] as $index=>$s):$id='signalPrompt'.$index;?>
    <article class="fix-item"><span class="severity-mark medium">⌁</span><div class="fix-item-body"><div class="fix-title"><strong><?=h(ucwords(str_replace('_',' ',$s['signal_type'])))?></strong><span>USER SIGNAL</span></div><p><?=(int)$s['occurrences']?> occurrence(s) · <?=(int)$s['affected_sessions']?> affected session(s) · Last seen <?=h($s['last_seen_at'])?></p><details><summary>View repair prompt</summary><pre id="<?=$id?>"><?=h($s['prompt'])?></pre></details></div><button class="btn sm" type="button" data-copy="<?=$id?>">Copy prompt</button></article>
    <?php endforeach?>
  </section>
  <?php endif?>

  <?php if($d['checks']):?>
  <section class="fix-group" id="readiness-fixes">
    <div class="fix-group-title"><div><div class="side-label" style="padding:0">READINESS</div><h2>Production gaps</h2></div><span><?=count($d['checks'])?></span></div>
    <?php foreach($d['checks'] as $c):$id='checkPrompt'.(int)$c['id'];$ev=$c['evidence'][0]??[];?>
    <article class="fix-item"><span class="severity-mark <?=h($c['severity'])?>">△</span><div class="fix-item-body"><div class="fix-title"><strong><?=h($c['display_title'])?></strong><span><?=h(strtoupper($c['severity']))?></span></div><p><?=h($c['display_description'])?></p><?php if($ev):?><div class="fix-evidence"><b><?=h(strtoupper($ev['source']))?></b><?=h($ev['summary'])?></div><?php endif?><details><summary>View repair prompt</summary><pre id="<?=$id?>"><?=h($c['prompt'])?></pre></details></div><button class="btn sm" type="button" data-copy="<?=$id?>">Copy prompt</button></article>
    <?php endforeach?>
  </section>
  <?php endif?>
</div>
