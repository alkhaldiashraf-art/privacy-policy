<!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=h($p['name'])?> trust page · SIUGOALS</title><link rel="stylesheet" href="/assets/css/app.css?v=6"></head>
<body style="background:#fbf7f3"><main style="max-width:900px;margin:0 auto;padding:60px 24px">
<div class="side-label" style="padding:0">SIUGOALS VERIFIED TRUST PAGE</div>
<h1 class="display-title" style="font-size:54px;margin:10px 0 5px"><?=h($p['name'])?></h1>
<a href="<?=h($p['url'])?>" rel="nofollow"><?=h($p['url'])?></a>
<?php $lastVerified=null; foreach($checks as $vc){if(!empty($vc['last_checked_at']) && (!$lastVerified || strtotime($vc['last_checked_at'])>strtotime($lastVerified)))$lastVerified=$vc['last_checked_at'];} $runtimeFresh=\App\Services\RuntimeService::connectedRecently($p,180);?>
<div class="trust-public-meta"><span>Verified <?=h($lastVerified?gmdate('M j, Y H:i',strtotime($lastVerified)).' UTC':'not yet')?> · Report ID <?=h($reportId??'—')?></span><span class="pill <?=$runtimeFresh?'teal':''?>"><?=$runtimeFresh?'● Runtime connected':'○ Runtime not currently reporting'?></span></div>
<div class="card" style="padding:22px;margin-top:28px"><div style="font-size:42px;font-weight:700"><?=$summary['score']?>%</div><p><?=$summary['ready']?> of <?=$summary['applicable']?> applicable checks verified.</p><div class="progress-track"><span class="ready" style="width:<?=$summary['score']?>%"></span><span class="pending" style="flex:1"></span></div></div>
<div class="card" style="margin-top:18px;overflow:hidden">
<?php foreach(['security','operations','code_quality','legal_compliance'] as $cat): ?>
  <div style="padding:18px 20px;border-bottom:1px solid #eee6df"><strong><?=h(ucwords(str_replace('_',' ',$cat)))?></strong><div style="margin-top:8px;color:#766b66">
  <?php foreach($checks as $c): ?>
    <?php if($c['category']===$cat): ?><div style="padding:4px 0"><?=($c['status']==='ready'?'✓':'·')?> <?=h(\App\Services\ReadinessService::presentTitle($c))?></div><?php endif; ?>
  <?php endforeach; ?>
  </div></div>
<?php endforeach; ?>
</div>
<p class="muted" style="margin-top:20px">Verification reflects SIUGOALS evidence at the time shown. It is not a guarantee that an application is free of all defects or risks.</p>
</main></body></html>
