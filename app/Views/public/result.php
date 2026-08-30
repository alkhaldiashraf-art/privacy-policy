<?php
$audit=$_SESSION['public_audit']??null;$err=$_SESSION['public_audit_error']??null;unset($_SESSION['public_audit_error']);
$stateLabel=static fn(string $s)=>match($s){'verified'=>'Verified','attention'=>'Needs attention','not_applicable'=>'Not applicable',default=>'Needs more evidence'};
$stateClass=static fn(string $s)=>match($s){'verified'=>'pass','attention'=>'fail','not_applicable'=>'na',default=>'unknown'};
$blocked=!empty($audit['blocked']);
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Your inspection · SIUGOALS</title><link rel="stylesheet" href="/assets/css/app.css?v=8"></head><body class="public-body"><main class="public-shell audit-result-shell"><section class="audit-report">
<div class="side-label audit-report-label">INSPECTION · <?= $err?'FAILED':($blocked?'BLOCKED':'COMPLETE') ?></div>
<?php if($err):?><h1>WE COULDN'T COMPLETE THE INSPECTION</h1><div class="flash error"><?=h($err)?></div><a class="btn" href="/">Try another URL</a>
<?php else:
$score=$blocked?null:(int)$audit['score'];
$headline=$blocked?'AUTOMATED INSPECTION WAS BLOCKED':($score>=85?'Ready for the next step.':($score>=65?'Almost there. Just a few finishing touches.':($score>=40?'There are a few production gaps to close.':'Your app needs production hardening.')));
?>
<div class="audit-score-hero"><div class="audit-score-number"><?=$blocked?'—':$score?><small><?=$blocked?' / NOT SCORED':'/100'?></small></div><div><h1><?=h($headline)?></h1><p>Read-only production snapshot for <strong><?=h($audit['effective_url'])?></strong>.</p><?php if($blocked):?><p class="muted">The target edge/WAF returned a challenge response, so SIUGOALS did not grade that challenge page as if it were your application.</p><?php endif?></div></div>
<?php if(!$blocked):?><div class="audit-area-grid"><?php foreach($audit['areas'] as $k=>$v):?><div class="audit-area-card"><span><?=h($k)?></span><strong><?=$v?></strong><div class="audit-mini-progress"><i style="width:<?=$v?>%"></i></div></div><?php endforeach?></div><?php endif?>
<?php if(!empty($audit['detected'])):?><section class="audit-detected"><div class="side-label">WHAT WE DETECTED</div><div class="detected-grid"><?php foreach(['builder'=>'Builder','framework'=>'Framework','hosting'=>'Hosting','database'=>'Database','ai_provider'=>'AI'] as $k=>$label):$v=$audit['detected'][$k]??'';if(!$v)continue;?><div><small><?=h($label)?></small><strong><?=h($v)?></strong></div><?php endforeach?></div></section><?php endif?>
<p class="inspector-note">outside evidence first. deeper code and runtime checks come after you connect the product.</p>
<?php foreach(['Security','Operations','Compliance'] as $area):$rows=array_values(array_filter($audit['checks'],fn($c)=>$c['area']===$area));if(!$rows)continue;?><section class="audit-report-section"><div class="side-label"><?=strtoupper(h($area))?></div><?php foreach($rows as $c):?><div class="audit-finding-row <?=$stateClass($c['state'])?>"><div class="audit-finding-icon"><?=$c['state']==='verified'?'✓':($c['state']==='attention'?'!':'·')?></div><div><strong><?=h($c['label'])?></strong><p><?=h($c['message'])?></p><?php if($c['state']==='attention'):?><div class="audit-why"><b>Why it matters</b><span><?=h(match($c['key']){
'https'=>'Users and credentials can be exposed without encrypted transport.',
'security_headers'=>'Browser-level protections reduce common injection and framing risks.',
'no_exposed_secrets','database_exposure'=>'Public credentials can lead to unauthorized access or unexpected cost.',
'404_recovery'=>'Broken links should help visitors recover instead of silently showing the wrong page.',
'spam_protection'=>'Unprotected public forms can be abused by bots and create operational cost.',
'privacy_policy'=>'Users need a clear explanation of how their data is handled.',
'terms'=>'Commercial products should define the terms under which users access the service.',
'backups'=>'Without recoverable backups, an incident can permanently destroy customer data.',
'role_separation'=>'Users should only have access to the actions and data required for their role.',
'ai_spending_cap','per_user_limits','hosting_cost_cap'=>'Unbounded resource usage can turn abuse or bugs into an unexpected bill.',
'source_control'=>'Version control makes releases reviewable and reversible.',
'ai_ownership'=>'Tool terms can affect whether generated code can be commercially used as intended.',
default=>'This gap reduces production confidence until it is verified or fixed.'})?></span></div><?php endif?></div><span class="audit-state-pill"><?=$stateLabel($c['state'])?></span></div><?php endforeach?></section><?php endforeach?>
<div class="audit-report-cta"><div><h2>Fix and verify the production gaps.</h2><p>The free inspection only uses public evidence. Add the product to unlock source, runtime and re-check evidence.</p></div><a class="btn dark" href="/signup">Continue with SIUGOALS →</a></div>
<?php endif?></section></main></body></html>
