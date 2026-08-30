<?php
$connection=\App\Services\RuntimeService::connectionState($p,180);
$sdk=\App\Core\Util::baseUrl().'/sdk/launchkit.js?v=5.5.0';
$prompt="Install the SIUGOALS Runtime SDK in my existing project without changing the current design, routes, authentication, or features.\n\n- SDK module: {$sdk}\n- SIUGOALS project key: {$p['slug']}\n- Expected production origin: ".(\App\Services\RuntimeService::expectedOrigin($p)?:$p['url'])."\n\nAdd this immediately before the closing </body> of the real HTML entry point:\n<script type=\"module\">\n  import('{$sdk}').then(({ init }) => init({ buildSlug: '{$p['slug']}' })).catch((error) => console.error('[SIUGOALS] SDK failed to start', error));\n</script>\n\nKeep the buildSlug exactly as written. Do not copy a key from another project. Do not send passwords, cookies, authorization headers, payment-card values, request bodies, or form-field values to SIUGOALS. After deploying, open the production site and confirm window.SIUGOALS_DIAGNOSTICS reaches CONNECTED. Report the exact file changed and how the production connection was verified.";
?>
<div class="connect-overlay">
  <a class="connect-close" href="/builds/<?=h($p['slug'])?>/dashboard/uptime" aria-label="Close">×</a>
  <div class="connect-inner">
    <div class="runtime-connect-state <?=h($connection['state'])?>"><strong><?php if($connection['state']==='live'):?>● Runtime is live<?php elseif($connection['state']==='stale'):?>● Runtime connection is stale<?php else:?>○ Runtime is not connected<?php endif?></strong><?php if($connection['last_seen_at']):?><span>Last event <?=h($connection['last_seen_at'])?></span><?php endif?></div>
    <h1 class="connect-title">CONNECT YOUR APP<br>TO SIUGOALS</h1>
    <div class="connect-subtitle">See production failures in context and turn the evidence into repair prompts.</div>

    <div class="connect-columns">
      <div class="connect-col"><h3>UPTIME & ERRORS</h3><p>Track availability, response time, incidents, JavaScript errors and failed requests.</p></div>
      <div class="connect-col"><h3>SESSIONS & SIGNALS</h3><p>Inspect privacy-safe timelines, affected users, rage clicks, dead clicks and abandoned flows.</p></div>
      <div class="connect-col"><h3>FIX PROMPTS</h3><p>Copy one evidence-backed repair prompt per issue or a single Runtime repair plan.</p></div>
    </div>

    <details class="sdk-collects"><summary>ⓘ What the SDK collects and masks</summary><p>Depending on the features you enable, SIUGOALS collects heartbeats, sanitized page URLs, browser/OS metadata, JavaScript failures, failed-request metadata and privacy-safe interaction evidence. Passwords, input values, payment-card values, cookies, authorization tokens and request bodies are excluded or redacted. Session Replay is off by default and can be disabled at any time.</p></details>

    <div class="connect-copy-section">
      <div class="sdk-project-key"><strong>Project key</strong><code><?=h($p['slug'])?></code><span>Expected origin: <?=h(\App\Services\RuntimeService::expectedOrigin($p)?:$p['url'])?></span><span>A different <code>buildSlug</code> will be rejected.</span></div>
      <p class="muted">The prepared instruction below is designed for AI coding tools. It tells the tool exactly what to preserve, install and verify.</p>

      <div class="step-row"><span class="step-num">1</span><div><div>Copy the prepared installation prompt:</div><button type="button" class="copy-accordion" data-snippet-toggle data-copy="snippetBox" style="margin-top:8px"><span>▣ Copy for your AI coding tool</span><span class="arrow">⌄</span></button></div></div>
      <pre class="snippet-box" id="snippetBox" hidden><?=h($prompt)?></pre>
      <div class="step-row"><span class="step-num">2</span><div>Apply the change in the real entry point, review it, and publish the app.</div></div>
      <div class="step-row"><span class="step-num">3</span><div>Open the production app so the first heartbeat can arrive, then verify below.</div></div>

      <?php if(!empty($canInstall)):?><form method="post" action="/builds/<?=h($p['slug'])?>/dashboard/connect/install" class="connect-pr-form"><?=\App\Core\Csrf::input()?><button class="btn secondary" type="submit">Create installation GitHub PR</button><p class="muted">SIUGOALS creates a reviewable pull request and never merges it automatically.</p></form><?php endif?>

      <div class="sdk-diagnostic-note"><strong>If verification fails</strong><p>Open the monitored app and inspect <code>window.SIUGOALS_DIAGNOSTICS</code>. Useful states include <code>BUILD_SLUG_NOT_FOUND</code>, <code>ORIGIN_NOT_ALLOWED</code>, <code>SDK_CONFIG_FETCH_FAILED</code>, <code>HEARTBEAT_NETWORK_FAILED</code> and <code>CONNECTED</code>.</p></div>
      <div class="connect-final-actions"><form method="post" action="/builds/<?=h($p['slug'])?>/dashboard/connect/verify"><?=\App\Core\Csrf::input()?><button class="btn coral" type="submit">Published it? Verify now</button></form><?php if($connection['state']!=='never'):?><a class="btn" href="/builds/<?=h($p['slug'])?>/dashboard/sessions">Open collected sessions</a><?php endif?><a class="muted" href="/builds/<?=h($p['slug'])?>/dashboard/uptime">Skip for now</a></div>
    </div>
  </div>
</div>
