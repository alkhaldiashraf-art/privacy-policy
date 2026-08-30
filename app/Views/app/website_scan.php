<?php
$report=$websiteReport??null;
$states=['verified'=>['✓','Verified','pass'],'attention'=>['△','Needs attention','fail'],'unknown'=>['◌','Not verified','unknown'],'not_applicable'=>['—','Not applicable','na']];
$attention=$report?count(array_filter($report['checks']??[],static fn($c)=>($c['state']??'')==='attention')):0;
?>
<div class="tool-page">
  <section class="tool-hero">
    <div><div class="side-label" style="padding:0">TOOL 1 · LIVE WEBSITE</div><h1 class="display-title">WEBSITE SCAN</h1><p>Enter your production URL once. SIUGOALS checks everything that can be proven safely from the public site, then separates verified facts from checks that require source code or Runtime.</p></div>
    <form method="post" action="/builds/<?=h($p['slug'])?>/dashboard/website-scan/run" class="tool-primary-action"><?=csrf()?><label class="scan-target"><span>SCAN TARGET</span><input value="<?=h($p['url'])?>" readonly aria-label="Website scan target"><a href="/builds/<?=h($p['slug'])?>/dashboard/settings?tab=general">Change</a></label><button class="btn coral" data-scan-submit><?=empty($report)?'SCAN WEBSITE':'SCAN AGAIN'?></button><small><?=h(tokenCostLabel('TOKEN_COST_WEBSITE_SCAN',150,(int)$p['id']))?></small></form>
  </section>

  <div class="coverage-explain"><strong>Honest coverage</strong><span>Public pages are read-only. Signed-in areas, server logic and database rules are never guessed from a URL; upload source and connect Runtime for those checks.</span></div>

  <?php if(!$report):?>
    <section class="tool-empty"><div class="empty-icon">◎</div><h2>No website scan yet</h2><p>Run the first scan to check security, reachability, public performance, broken links, SEO, forms, accessibility and legal pages.</p></section>
  <?php else:?>
    <section class="tool-summary-grid">
      <div><small>READINESS</small><strong><?=(int)($report['summary']['score']??0)?>%</strong><span><?=h(($report['blocked']??false)?'Limited by target protection':'Evidence-backed score')?></span></div>
      <div><small>PUBLIC PAGES</small><strong><?=(int)($report['pages_scanned']??0)?></strong><span><?=(int)($report['links_checked']??0)?> responses checked</span></div>
      <div><small>RESPONSE</small><strong><?=isset($report['response_ms'])?(int)$report['response_ms'].'ms':'—'?></strong><span>Initial public response</span></div>
      <div><small>NEEDS ATTENTION</small><strong><?=$attention?></strong><span>Actionable website finding<?=($attention===1?'':'s')?></span></div>
    </section>

    <div class="coverage-strip">
      <?php foreach(($report['coverage']??[]) as $key=>$state):?><span class="coverage-state <?=h(in_array($state,['complete'],true)?'ok':'limited')?>"><b><?=h(ucwords(str_replace('_',' ',$key)))?></b><?=h(ucwords(str_replace('_',' ',$state)))?></span><?php endforeach?>
    </div>

    <section class="ai-review-note <?=h(($report['ai']['status']??'disabled')==='completed'?'complete':'limited')?>"><div><strong>AI evidence review · <?=h(ucwords(str_replace('_',' ',(string)($report['ai']['status']??'disabled'))))?></strong><span><?=h($report['ai']['summary']??'Deterministic website checks completed without an AI review.')?></span></div><?php if(!empty($report['ai_findings'])):?><a href="/builds/<?=h($p['slug'])?>/dashboard/fixes#readiness-fixes"><?=count($report['ai_findings'])?> AI-backed finding<?=count($report['ai_findings'])===1?'':'s'?> in Fix Center</a><?php endif?></section>

    <?php if(!empty($report['detected'])):?><section class="tool-card"><header><div><div class="side-label" style="padding:0">DETECTED</div><h2>Technology signals</h2></div></header><div class="detected-grid tool-detected"><?php foreach(['builder'=>'Builder','framework'=>'Framework','hosting'=>'Hosting','database'=>'Database','ai_provider'=>'AI'] as $key=>$label):$value=$report['detected'][$key]??'';if(!$value)continue;?><div><small><?=h($label)?></small><strong><?=h($value)?></strong></div><?php endforeach?></div></section><?php endif?>

    <section class="tool-card">
      <header><div><div class="side-label" style="padding:0">RESULTS</div><h2>What the public website proves</h2></div><div class="prompt-actions"><a class="btn sm" href="/builds/<?=h($p['slug'])?>/dashboard/website-scan/download">Download JSON</a><a class="btn sm dark" href="/builds/<?=h($p['slug'])?>/dashboard/fixes">Open Fix Center</a></div></header>
      <div class="website-findings">
        <?php foreach(($report['checks']??[]) as $index=>$c):$state=$states[$c['state']??'unknown']??$states['unknown'];?>
          <article class="website-finding <?=h($state[2])?>"><span class="finding-state-icon"><?=h($state[0])?></span><div><strong><?=h($c['label']??$c['key']??'Website check')?></strong><p><?=h($c['message']??'No explanation was returned.')?></p><small><?=h($c['area']??'Website')?> · <?=h(ucfirst($c['severity']??'medium'))?> confidence evidence</small></div><span class="state-chip"><?=h($state[1])?></span></article>
        <?php endforeach?>
      </div>
    </section>

    <?php if(!empty($report['pages'])):?><section class="tool-card"><header><div><div class="side-label" style="padding:0">PUBLIC SURFACE</div><h2>Inspected pages</h2></div></header><div class="table-scroll"><table class="table page-evidence-table"><thead><tr><th>Page</th><th>Status</th><th>Response</th><th>SEO</th><th>Accessibility</th></tr></thead><tbody><?php foreach($report['pages'] as $page):$seo=trim((string)($page['title']??''))!==''&&trim((string)($page['meta_description']??''))!==''&&(int)($page['h1_count']??0)===1;$a11y=(int)($page['images_missing_alt']??0)==0&&(int)($page['form_controls_missing_label']??0)==0&&!empty($page['has_viewport'])&&trim((string)($page['lang']??''))!=='';?><tr><td><strong><?=h($page['title']?:parse_url((string)$page['url'],PHP_URL_PATH)?:'/')?></strong><small><?=h($page['url'])?></small></td><td><?=(int)$page['status']?></td><td><?=(int)$page['response_ms']?>ms</td><td><span class="mini-result <?=$seo?'ok':'warn'?>"><?=$seo?'Ready':'Review'?></span></td><td><span class="mini-result <?=$a11y?'ok':'warn'?>"><?=$a11y?'Ready':'Review'?></span></td></tr><?php endforeach?></tbody></table></div></section><?php endif?>

    <?php if(!empty($report['failures'])):?><section class="tool-card"><header><div><div class="side-label" style="padding:0">CRAWL NOTES</div><h2>Pages that could not be inspected</h2></div></header><div class="simple-list"><?php foreach($report['failures'] as $failure):?><div><strong><?=h($failure['url']??'Unknown URL')?></strong><span><?=h((string)($failure['error']??'Request failed'))?></span></div><?php endforeach?></div></section><?php endif?>

    <section class="next-tools"><div><span>2</span><h3>Upload project ZIP</h3><p>Verify server logic, code security and configuration that a public URL cannot show.</p><a class="btn" href="/builds/<?=h($p['slug'])?>/dashboard/source">Open Code Scan</a></div><div><span>3</span><h3>Connect Runtime SDK</h3><p>See real errors, sessions, signals, uptime and failures after users start using the app.</p><a class="btn" href="/builds/<?=h($p['slug'])?>/dashboard/connect">Connect Runtime</a></div></section>
    <p class="scan-timestamp">Last website scan: <?=h($report['completed_at']??$report['_scan']['completed_at']??'Unknown')?></p>
  <?php endif?>
</div>
