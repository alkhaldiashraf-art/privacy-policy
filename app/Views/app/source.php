<?php
$gc=\App\Core\DB::one('SELECT * FROM github_connections WHERE project_id=?',[$p['id']]);
$githubConfigured=\App\Services\GitHubService::configured(false);
$zipCost=tokenCostLabel('TOKEN_COST_SOURCE_SCAN',250,(int)$p['id']);
$ghCost=tokenCostLabel('TOKEN_COST_GITHUB_SCAN',250,(int)$p['id']);
$ownerTest=ownerTestMode()&&isProjectOwner((int)$p['id']);
$aiConfigured=\App\Services\AiScanService::enabled();$aiModel=\App\Services\OpenAIService::model();
?>
<div class="source-wrap">
  <div class="source-head">
    <div>
      <div class="side-label" style="padding:0">SOURCE EVIDENCE</div>
      <h1 class="display-title source-title">SCAN YOUR PROJECT CODE</h1>
      <p class="muted">Upload the ZIP produced by your AI coding tool. SIUGOALS checks the real project structure and code, shows evidence for every finding, and creates both a complete repair prompt and one prompt per issue.</p>
    </div>
    <?php if($ownerTest):?><span class="pill source-test-pill">Owner acceptance mode · validation scans are free</span><?php endif?><?php if($aiConfigured):?><span class="pill source-test-pill">AI scan · <?=h($aiModel)?></span><?php else:?><span class="pill source-test-pill">AI scan not configured</span><?php endif?>
  </div>

  <?php if(!empty($fixData['source'])):?><section class="master-prompt-card source-master-prompt"><div class="master-prompt-head"><div><span class="prompt-badge">LATEST CODE SCAN</span><h2>Complete source repair prompt</h2><p>All findings from the latest completed ZIP or GitHub scan, deduplicated and ordered for an AI coding tool.</p></div><div class="prompt-actions"><button type="button" class="btn dark" data-copy="sourceMasterPrompt">Copy complete prompt</button><a class="btn" href="/builds/<?=h($p['slug'])?>/dashboard/fixes/download?scope=source">Download .txt</a><a class="btn" href="/builds/<?=h($p['slug'])?>/dashboard/fixes">Open Fix Center</a></div></div><details><summary>Preview complete prompt</summary><pre id="sourceMasterPrompt"><?=h($fixData['source_prompt'])?></pre></details></section><?php endif?>

  <section class="source-card">
    <header><span class="source-icon">⌁</span><div><h2>GITHUB</h2><p>Continuous source verification from a repository you authorize.</p></div></header>
    <div class="source-card-body">
      <?php if($gc&&$gc['repo_full_name']):?>
        <div class="source-state ok"><strong>✓ Repository connected</strong><span><?=h($gc['repo_full_name'])?></span></div>
        <div class="source-actions">
          <form method="post" action="/builds/<?=h($p['slug'])?>/dashboard/source/github-scan"><?=csrf()?><button class="btn coral">Scan GitHub source</button></form>
          <small><?=h($ghCost)?></small>
        </div>
      <?php elseif($githubConfigured):?>
        <div class="source-state"><strong>Connect code when you want continuous verification.</strong><span>SIUGOALS requests read-only repository access for scanning.</span></div>
        <a class="btn yellow" href="/builds/<?=h($p['slug'])?>/github/connect">CONNECT CODE →</a>
      <?php else:?>
        <div class="source-state setup">
          <strong>GitHub scanning is temporarily unavailable.</strong>
          <span>ZIP project scanning is available now. When repository connections are enabled, this same page will offer read-only GitHub authorization.</span>
        </div>
        <a class="btn yellow" href="#zip-scan">SCAN A ZIP PROJECT →</a>
      <?php endif?>
    </div>
  </section>

  <section class="source-card" id="zip-scan">
    <header><span class="source-icon">▣</span><div><h2>ZIP PROJECT SCAN</h2><p>Upload the current project directly. Before any finding can affect Readiness, SIUGOALS checks whether the archive identity belongs to this project. ZIP entries are inspected without extracting them into the web root, then the upload is discarded.</p></div></header>
    <div class="source-card-body">
      <div class="source-safety-grid">
        <span>✓ Verifies project identity first</span><span>✓ Blocks path traversal</span><span>✓ Rejects unsafe symlinks</span><span>✓ Archive size limits</span><span>✓ Decompression-bomb protection</span><span>✓ Skips vendor/generated code</span><span>✓ Redacts secret values before AI</span>
      </div>
      <form class="zip-drop-form" method="post" enctype="multipart/form-data" action="/builds/<?=h($p['slug'])?>/dashboard/source/zip">
        <?=csrf()?>
        <label class="zip-drop"><input type="file" name="source_zip" accept=".zip,application/zip" required><span class="zip-drop-title">Choose a ZIP project</span><span class="muted"><?php if($aiConfigured):?>Up to 100 MB. The archive is not retained. A strong project mismatch is rejected before any Readiness status changes. Local deterministic results are committed before the optional AI pass, so an AI timeout cannot leave the scan stuck as RUNNING.<?php else:?>Up to 100 MB. The archive identity is checked first, scanned locally, and removed after the scan. Configure OPENAI_API_KEY to enable the second-stage AI evidence review.<?php endif?></span></label>
        <div class="source-actions"><button class="btn coral">SCAN SOURCE</button><small><?=h($zipCost)?></small></div>
      </form>
    </div>
  </section>

  <?php $ss=\App\Core\DB::all('SELECT * FROM source_scans WHERE project_id=? ORDER BY id DESC LIMIT 10',[$p['id']]);?>
  <?php if($ss):?><div class="source-history-title">RECENT SOURCE SCANS</div><?php endif?>
  <?php foreach($ss as $scan):?>
    <section class="source-card source-result">
      <header><div><h2>SCAN #<?=$scan['id']?> · <?=h(strtoupper($scan['status']))?></h2><p><?=h($scan['original_name'])?> · <?=$scan['total_files']?> files · <?=number_format((int)$scan['total_bytes'])?> bytes</p></div></header>
      <div class="source-card-body">
        <?php $fs=\App\Core\DB::all('SELECT * FROM source_findings WHERE source_scan_id=? ORDER BY CASE severity WHEN "critical" THEN 1 WHEN "high" THEN 2 WHEN "medium" THEN 3 ELSE 4 END,id',[$scan['id']]);?>
        <?php if(!$fs):?><div class="source-empty"><?php if($scan['status']==='failed'):?>This scan stopped safely before findings were attached to Readiness. Upload the correct project source and try again.<?php elseif($scan['status']==='running'):?>Local source analysis is still running. If this state is stale, a new scan will recover it safely.<?php else:?>No source findings were produced by this scan.<?php endif?></div><?php endif?>
        <?php foreach($fs as $f):$fp='sourceFindingPrompt'.(int)$f['id'];$prepared=$f['fix_prompt'];foreach(($fixData['source']??[]) as $stored){if((int)$stored['id']===(int)$f['id']){$prepared=$stored['prompt'];break;}}?><div class="source-finding"><span class="source-finding-sev <?=h($f['severity'])?>">△</span><div><strong><?=h($f['title'])?></strong><p><?=h($f['file_path'])?><?php if($f['line_number']):?>:<?=$f['line_number']?><?php endif?> — <?=h($f['description'])?></p><details><summary>Individual repair prompt</summary><pre id="<?=$fp?>"><?=h($prepared)?></pre></details><button type="button" class="btn sm prompt-copy-inline" data-copy="<?=$fp?>">Copy this prompt</button></div><b><?=h(strtoupper($f['severity']))?></b></div><?php endforeach?>
      </div>
    </section>
  <?php endforeach?>
</div>
