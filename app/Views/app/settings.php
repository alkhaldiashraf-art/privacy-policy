<?php
$tabs=['general'=>t('General'),'agents'=>t('Agents'),'connect'=>t('Connect'),'danger'=>t('Danger')];
$maintConnected=!empty($github['write_enabled'])&&!empty($github['maintenance_installation_id']);
$runtimeFresh=\App\Services\RuntimeService::connectedRecently($p,180);
?>
<div class="settings-wrap">
  <div class="tabs"><?php foreach($tabs as $k=>$l):?><a class="tab <?=$tab===$k?'active':''?>" href="?tab=<?=$k?>"><?=h($l)?></a><?php endforeach?></div>

<?php if($tab==='general'):?>
<form method="post" action="/builds/<?=h($p['slug'])?>/dashboard/settings/general"><?=csrf()?>
<section class="settings-section">
  <h2>PRODUCT DETAILS</h2><p class="muted">Update your product's name, URL, tagline, and detected stack. The slug (<?=h($p['slug'])?>) cannot be changed.</p>
  <div class="field-line"><label>Name</label><input name="name" value="<?=h($p['name'])?>" required maxlength="190"></div>
  <div class="field-line"><label>URL</label><input name="url" type="url" value="<?=h($p['url'])?>" required maxlength="500"></div>
  <div class="field-line"><label>Tagline</label><textarea name="tagline" maxlength="200" data-count-target="taglineCount"><?=h($p['tagline'])?></textarea><small id="taglineCount" class="muted"><?=strlen((string)$p['tagline'])?>/200</small></div>
  <?php foreach(['toolchain'=>'Toolchain','ai_provider'=>'AI Provider','hosting_platform'=>'Hosting Platform','database_platform'=>'Database Platform'] as $k=>$l):?><div class="field-line"><label><?=$l?></label><input name="<?=$k?>" value="<?=h($p[$k])?>" placeholder="Not detected" maxlength="100"></div><?php endforeach?>
  <button class="btn yellow">SAVE CHANGES</button>
</section>
<section class="settings-section">
  <h2>RE-ANALYZE URL</h2><p class="muted">Re-run URL analysis to refresh public evidence and stack detection. This uses the normal readiness re-scan cost.</p>
  <button type="submit" formaction="/builds/<?=h($p['slug'])?>/dashboard/scan" class="btn">↻ Re-analyze URL · <?=h(tokenCostLabel('TOKEN_COST_READINESS_RESCAN',300,(int)$p['id']))?></button>
</section>
<section class="settings-section">
  <h3>Monthly token cap</h3><p class="muted">Limit how many tokens this product can consume per billing cycle. Leave empty for no limit.</p>
  <div class="inline-save"><div class="field-card"><input name="monthly_token_cap" type="number" min="0" value="<?=h($p['monthly_token_cap'])?>" placeholder="No limit"></div><button class="btn">SAVE</button></div>
</section></form>

<?php elseif($tab==='agents'):?>
<section class="settings-section">
  <h2>YOUR SIUGOALS AGENTS</h2><p class="muted">Agents inspect current evidence and generate verifiable remediation plans. They never claim code was changed unless a repository action actually occurred.</p>
  <?php $labels=['security_auditor'=>['Security','No one gets in who shouldn’t.','Security auditor'],'ops_inspector'=>['Operations','You’ll know before your clients do.','Ops inspector'],'code_reviewer'=>['Code Quality','Your code stays workable as it grows.','Code reviewer'],'compliance_checker'=>['Legal & Compliance','The basics are covered.','Compliance checker']];foreach($agents as $a):[$name,$desc,$btn]=$labels[$a['agent_key']];?>
  <div class="agent-row"><div><strong><?=h($name)?></strong><small><?=h($desc)?></small></div><div class="agent-actions">
    <?php if($a['enabled']):?><form method="post" action="/builds/<?=h($p['slug'])?>/dashboard/agents/<?=h($a['agent_key'])?>/run"><?=csrf()?><button class="agent-add">Run · <?=h(tokenCostLabel('TOKEN_COST_AGENT_RUN',120,(int)$p['id']))?></button></form><?php endif?>
    <form method="post" action="/builds/<?=h($p['slug'])?>/dashboard/settings/agent"><?=csrf()?><input type="hidden" name="agent_key" value="<?=h($a['agent_key'])?>"><input type="hidden" name="enabled" value="<?=$a['enabled']?0:1?>"><button class="agent-add"><?=$a['enabled']?'Disable':'+ '.$btn?></button></form>
  </div></div><?php endforeach?>
  <?php if(!empty($_SESSION['agent_run'])):$ar=$_SESSION['agent_run'];unset($_SESSION['agent_run']);?><div class="agent-result"><strong>Latest verified analysis</strong><p class="muted"><?=h($ar['output']['summary']??'')?></p><?php foreach($ar['output']['actions']??[] as $act):?><details><summary><?=h($act['title']??'Action')?></summary><p><?=h($act['rationale']??'')?></p><ol><?php foreach($act['steps']??[] as $step):?><li><?=h($step)?></li><?php endforeach?></ol><small>Verification: <?=h($act['verification']??'')?></small></details><?php endforeach?><?php if(!empty($ar['output']['pull_request_url'])):?><p><a class="btn teal" target="_blank" rel="noopener" href="<?=h($ar['output']['pull_request_url'])?>">Open generated pull request ↗</a></p><?php endif?></div><?php endif?>
</section>
<section class="settings-section">
  <h2>MAINTENANCE MODE</h2><p class="muted">Write permission is isolated from read-only source scanning. Connect the separate Maintenance GitHub App only when you intentionally want SIUGOALS to be able to prepare repository changes.</p>
  <?php if(!$p['github_repo']):?><div class="setup-note">Connect a read-only GitHub repository first.</div><a class="btn" href="/builds/<?=h($p['slug'])?>/dashboard/source">Connect code</a>
  <?php elseif($maintConnected):?><div class="maintenance-state on"><span>●</span><div><strong>Maintenance write access connected</strong><small><?=h($p['github_repo'])?></small></div></div><form method="post" action="/builds/<?=h($p['slug'])?>/dashboard/settings/maintenance-disconnect"><?=csrf()?><button class="btn danger-outline">Revoke write access</button></form>
  <?php elseif(!\App\Services\GitHubService::configured(true)):?><div class="setup-note">Maintenance write access is not available on this SIUGOALS installation yet. Read-only source scanning continues to work.</div>
  <?php else:?><a class="btn yellow" href="/builds/<?=h($p['slug'])?>/github/maintenance-connect">Enable maintenance mode</a><?php endif?>
</section>

<?php elseif($tab==='connect'):?>
<section class="settings-section"><h2>LIVE APP</h2><p class="muted">Link SIUGOALS to your live app to unlock uptime alerts, error reports, session evidence, and the trust badge.</p><div class="connect-status-card"><?php if($runtimeFresh):?><span class="pill teal">● SDK connected</span><span class="muted">Last heartbeat <?=h($p['runtime_last_seen_at']?:'—')?></span><?php else:?><p>Takes about a minute — we'll walk you through each step.</p><a href="/builds/<?=h($p['slug'])?>/dashboard/connect">Connect your app</a><?php endif?></div></section>
<section class="settings-section"><h2>ACTIVE FEATURES</h2><p class="muted">Choose which SIUGOALS features run on your live app. Error capture can run without session replay.</p>
<?php $features=[['trust_badge','Display trust badge on your app','When on, the SIUGOALS trust badge appears in the bottom-right of your live app and links to your trust page.','trust_badge_enabled'],['error_capture','Client error capture','Collect browser errors and failed requests without visual session replay.','error_capture_enabled'],['session_replay','Session replay','Collect privacy-safe snapshots and interaction events. Sensitive inputs are masked.','session_replay_enabled'],['paid_access','Paid access','Let the SDK surface access state for identified clients. Server-side access checks remain authoritative.','paid_access_enabled']];foreach($features as [$key,$name,$desc,$col]):?><div class="feature-row"><div><strong><?=h($name)?></strong><p><?=h($desc)?></p></div><form method="post" action="/builds/<?=h($p['slug'])?>/dashboard/settings/feature"><?=csrf()?><input type="hidden" name="feature" value="<?=$key?>"><label class="toggle"><input name="enabled" value="1" type="checkbox" <?=$p[$col]?'checked':''?> data-toggle-submit><span></span></label></form></div><?php endforeach?></section>

<?php else:?>
<section class="settings-section"><h2>DANGER ZONE</h2><p class="muted">Permanently delete this product and all associated scans, runtime sessions, trust reports, clients, and integrations. This cannot be undone.</p><form method="post" action="/builds/<?=h($p['slug'])?>/dashboard/settings/delete" class="danger-form"><?=csrf()?><div class="field-line"><label>Type <strong><?=h($p['name'])?></strong> to confirm</label><input name="confirm" required autocomplete="off"></div><button class="btn danger-outline">⌫ Delete this product</button></form></section>
<?php endif?></div>
