<?php
$connection=\App\Services\RuntimeService::connectionState($p,180);
$hasRuntimeHistory=(bool)\App\Core\DB::one('SELECT id FROM runtime_sessions WHERE project_id=? LIMIT 1',[$p['id']]);
?>
<?php if($connection['state']==='never'&&!$hasRuntimeHistory):?>
<div class="empty-state"><div class="empty-inner"><div class="empty-icon">△</div><div class="empty-title">CONNECT YOUR APP FIRST</div><p>Install the SDK to collect browser failures from real user sessions and generate evidence-backed repair prompts.</p><a class="btn yellow" href="/builds/<?=h($p['slug'])?>/dashboard/connect">Connect Runtime SDK</a></div></div>
<?php else:?>
<div class="runtime-errors-page">
  <?php if($connection['state']==='stale'):?><div class="runtime-status-banner stale"><strong>Runtime connection is stale</strong><span>Last event <?=h($connection['last_seen_at'])?>. Historical errors remain available; open the live app to resume collection.</span><a href="/builds/<?=h($p['slug'])?>/dashboard/connect">Reconnect</a></div><?php elseif($connection['state']==='live'):?><div class="runtime-status-banner live"><strong>Runtime is live</strong><span>New errors and sessions are being collected.</span></div><?php endif?>

  <section class="runtime-error-head"><div><div class="side-label" style="padding:0">LIVE PRODUCTION EVIDENCE</div><h1 class="display-title">RUNTIME ERRORS</h1><p class="muted">Errors are grouped by root signature and ranked by affected sessions. Copy one repair prompt or send all current Runtime problems to your AI coding tool.</p></div><?php if($issues):?><div class="prompt-actions"><button class="btn dark" type="button" data-copy="runtimeMasterPrompt">Copy all Runtime fixes</button><a class="btn" href="/builds/<?=h($p['slug'])?>/dashboard/fixes">Open Fix Center</a></div><?php endif?></section>
  <pre id="runtimeMasterPrompt" hidden><?=h($fixData['runtime_prompt'])?></pre>

  <?php if(!$issues):?>
    <div class="empty-state embedded"><div class="empty-inner"><div class="empty-icon">♢</div><div class="empty-title">NO OPEN ERRORS</div><p>No unresolved client error is currently stored. Historical sessions remain available, and future failures will appear here with evidence and a repair prompt.</p><a href="/builds/<?=h($p['slug'])?>/dashboard/sessions">View recent sessions</a></div></div>
  <?php else:?>
  <div class="runtime-error-list">
    <?php foreach($issues as $i):$promptId='errorPrompt'.(int)$i['id'];$ev=$i['evidence'][0]??[];?>
    <article class="runtime-error-card">
      <div class="runtime-error-impact"><strong><?=(int)$i['affected_sessions']?></strong><span>affected<br>sessions</span></div>
      <div class="runtime-error-main">
        <div class="fix-title"><strong><?=h($i['title'])?></strong><span><?=h(strtoupper($i['error_type']?:'error'))?></span></div>
        <p><?=h($ev['message']??'Captured from a real user session.')?></p>
        <div class="runtime-error-meta"><span><?=(int)$i['occurrences']?> occurrence(s)</span><span>First <?=h($i['first_seen_at'])?></span><span>Last <?=h($i['last_seen_at'])?></span><?php if(!empty($ev['page_url'])):?><span><?=h($ev['page_url'])?></span><?php endif?></div>
        <?php if(!empty($i['sessions'])):?><div class="affected-session-links"><strong>Affected sessions</strong><?php foreach($i['sessions'] as $s):?><a href="/builds/<?=h($p['slug'])?>/dashboard/sessions/<?=(int)$s['id']?>">#<?=h(substr($s['identity_id']?:$s['session_key'],0,8))?> · <?=h($s['identity_email']?:trim(($s['os']?:'Unknown').' '.($s['browser']?:'')))?></a><?php endforeach?></div><?php endif?>
        <?php if(!empty($i['related_source'])):?><div class="related-source"><strong>Correlated source evidence</strong><?php foreach($i['related_source'] as $source):?><span><?=h($source['file_path']?:'Source file')?><?=$source['line_number']?':'.(int)$source['line_number']:''?> · <?=h($source['title'])?></span><?php endforeach?></div><?php endif?>
        <?php if(!empty($ev['stack_text'])):?><details><summary>Technical evidence</summary><pre><?=h(substr((string)$ev['stack_text'],0,6000))?></pre></details><?php endif?>
        <details><summary>Individual repair prompt</summary><pre id="<?=$promptId?>"><?=h($i['prompt'])?></pre></details>
      </div>
      <div class="runtime-error-actions"><button class="btn sm coral" type="button" data-copy="<?=$promptId?>">Copy fix prompt</button><form method="post" action="/builds/<?=h($p['slug'])?>/dashboard/errors/<?=$i['id']?>/resolve"><?php echo \App\Core\Csrf::input();?><button class="btn sm" type="submit">Mark resolved</button></form></div>
    </article>
    <?php endforeach?>
  </div>
  <?php endif?>
</div>
<?php endif?>
