<?php $token=\App\Core\Csrf::token(); ?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Inspection running · SIUGOALS</title><link rel="stylesheet" href="/assets/css/app.css?v=8"></head><body class="public-body"><main class="public-shell audit-running-shell" data-public-audit data-csrf="<?=h($token)?>">
<section class="audit-running-card"><div class="side-label audit-running-label">INSPECTION · <span data-audit-state>RUNNING</span></div>
<div class="audit-running-list">
<?php foreach([
 ['https','HTTPS certificate','checking transport…'],
 ['no_exposed_secrets','Exposed secrets','scanning public source…'],
 ['database_exposure','Database access','checking public exposure…'],
 ['spam_protection','Spam protection','inspecting public forms…'],
 ['404_recovery','Error handling','checking invalid paths…'],
 ['privacy_policy','Privacy policy','scanning public page…'],
 ['monitoring','Uptime monitoring','looking for evidence…'],
] as [$key,$label,$verb]):?>
<div class="audit-running-row" data-audit-check="<?=$key?>"><span class="audit-run-dot"></span><span><strong><?=h($label)?></strong> · <em><?=h($verb)?></em></span></div>
<?php endforeach?>
</div>
<div class="inspector-note audit-review-note" data-audit-note>review is coming together.</div>
<div class="audit-running-error" data-audit-error hidden></div>
</section></main><script src="/assets/js/app.js?v=8" defer></script></body></html>
