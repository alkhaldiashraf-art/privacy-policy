<?php
use App\Services\SessionService;
function evLabel4(string $t): string { return ucwords(str_replace('_',' ',$t)); }
function durDetail4(int $s): string { if($s>=3600)return floor($s/3600).'h '.gmdate('i\m s\s',$s);if($s>=60)return floor($s/60).'m '.($s%60).'s';return $s.'s'; }
$signalRows=array_values(array_filter($events,fn($e)=>$e['event_type']==='signal'&&$e['signal_type']!=='performance_sample'));
$errorRows=array_values(array_filter($events,fn($e)=>in_array($e['event_type'],['error','network_error'],true)));
?>
<div class="session-detail-wrap">
<a class="back-link" href="/builds/<?=h($p['slug'])?>/dashboard/sessions">← Back to sessions</a>
<div class="session-detail-head"><div><h1 class="display-title session-detail-title">SESSION #<?=h(substr($session['identity_id']?:$session['session_key'],0,8))?></h1><div class="muted"><?=h($session['identity_email']?:'Anonymous')?> · <?=h($session['os']?:'Unknown')?> / <?=h($session['browser']?:'Unknown')?> · <?=h(date('M j, Y H:i',strtotime($session['started_at'])))?></div></div><div class="session-detail-pills"><span class="pill"><?=h(durDetail4((int)$session['duration_seconds']))?></span><span class="pill coral"><?=$session['error_count']?> errors</span><span class="pill"><?=$session['signal_count']?> signals</span><?php if((int)$session['error_count']>0):?><a class="btn sm" href="/builds/<?=h($p['slug'])?>/dashboard/errors">Open repair prompts</a><?php endif?></div></div>
<div class="session-facts"><div><small>IDENTITY</small><strong><?=h($session['identity_email']?:$session['identity_id']?:'Anonymous')?></strong></div><div><small>ENVIRONMENT</small><strong><?=h($session['os']?:'Unknown')?> · <?=h($session['browser']?:'Unknown')?></strong></div><div><small>COUNTRY</small><strong><?=h(\App\Services\SessionService::flag((string)$session['country_code']))?> <?=h($session['country_code']?:'Unknown')?></strong></div><div><small>STARTED</small><strong><?=h(date('M j, H:i:s',strtotime($session['started_at'])))?></strong></div></div>

<div class="session-detail-grid">
<section class="replay-panel">
  <div class="replay-head"><div><h2>SESSION REPLAY</h2><p class="muted">Privacy-safe snapshots captured while replay was enabled.</p></div><span class="pill teal"><?=count($snapshots)?> snapshot<?=count($snapshots)===1?'':'s'?></span></div>
  <?php if(!$snapshots):?><div class="replay-empty"><div class="empty-icon">▣</div><strong>No replay snapshots</strong><p class="muted">Enable Session Replay in Settings → Connect. Sensitive input values are masked before collection.</p></div><?php else:?>
  <div class="replay-stage"><iframe id="replayFrame" sandbox="" title="Session replay"></iframe></div>
  <div class="replay-controls"><button type="button" class="replay-nav" data-replay-prev>‹</button><button type="button" class="replay-play" data-replay-play>▶</button><button type="button" class="replay-nav" data-replay-next>›</button><input type="range" min="0" max="<?=max(0,count($snapshots)-1)?>" value="0" id="replayRange"><span id="replayTime"></span></div>
  <script type="application/json" id="replayData"><?=json_encode(array_map(fn($s)=>['time'=>$s['occurred_at'],'url'=>$s['page_url'],'html'=>$s['html']],$snapshots),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?></script>
  <?php endif?>
</section>

<aside class="session-side-panel">
  <div class="side-summary"><h3>SIGNALS</h3><?php if(!$signalRows):?><p class="muted">No behavioral signals detected.</p><?php else:foreach($signalRows as $e):?><div class="signal-summary-row"><span><?=h(evLabel4((string)$e['signal_type']))?></span><small><?=h(date('H:i:s',strtotime($e['occurred_at'])))?></small></div><?php endforeach;endif?></div>
  <div class="side-summary"><h3>ERRORS</h3><?php if(!$errorRows):?><p class="muted">No errors in this session.</p><?php else:foreach($errorRows as $e):?><div class="error-summary-row"><strong><?=h($e['message']?:evLabel4($e['event_type']))?></strong><small><?=h(date('H:i:s',strtotime($e['occurred_at'])))?></small></div><?php endforeach;endif?></div>
</aside>
</div>

<section class="timeline-card"><div class="timeline-title"><h2>EVENT TIMELINE</h2><span><?=count($events)?> events</span></div><div class="timeline-list"><?php foreach($events as $e):$meta=json_decode((string)$e['meta_json'],true)?:[];?><div class="timeline-row"><time><?=h(date('H:i:s',strtotime($e['occurred_at'])))?></time><span class="event-type <?=h($e['event_type'])?>"><?=h(evLabel4($e['event_type']))?></span><div><strong><?=h($e['signal_type']?evLabel4($e['signal_type']):($e['message']?:''))?></strong><?php if($e['page_url']):?><small><?=h($e['page_url'])?></small><?php endif?></div></div><?php endforeach?></div></section>
</div>
