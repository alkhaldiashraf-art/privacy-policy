<?php
$connection=\App\Services\RuntimeService::connectionState($p,180);
$last=\App\Core\DB::one('SELECT * FROM uptime_checks WHERE project_id=? ORDER BY checked_at DESC LIMIT 1',[$p['id']]);
$hasUptimeHistory=!empty($metrics['checks']);
?>
<?php if($connection['state']==='never'&&!$hasUptimeHistory):?>
<div class="empty-state"><div class="empty-inner"><div class="empty-icon">⌁</div><div class="empty-title">CONNECT YOUR APP FIRST</div><p>Install the SIUGOALS Runtime SDK, then add the Hostinger cron job to collect live heartbeats, uptime, response time, incidents and browser evidence.</p><a class="btn yellow" href="/builds/<?=h($p['slug'])?>/dashboard/connect">Connect your app</a></div></div>
<?php else:?>
<div class="uptime-page">
  <?php if($connection['state']==='stale'):?><div class="runtime-status-banner stale"><strong>SDK heartbeat is stale</strong><span>Last Runtime event <?=h($connection['last_seen_at'])?>. Uptime history remains visible and scheduled probes can continue.</span><a href="/builds/<?=h($p['slug'])?>/dashboard/connect">Reconnect</a></div><?php elseif($connection['state']==='live'):?><div class="runtime-status-banner live"><strong>Runtime is live</strong><span>Heartbeat and browser evidence are arriving.</span></div><?php endif?>

  <div class="uptime-top">
    <?php if(!$last):?><span class="pill neutral">◌ Waiting for the first scheduled uptime probe</span><?php elseif($last['status']==='down'):?><span class="pill danger">● Your app is offline</span><?php elseif($last['status']==='degraded'):?><span class="pill warning">● Your app is degraded</span><?php else:?><span class="pill teal">● Your app is online</span><?php endif?>
    <div class="uptime-metrics"><span>↗ <?=isset($metrics['uptime'])&&$metrics['uptime']!==null?h($metrics['uptime']).'%':'— uptime'?></span><span>◷ <?=isset($metrics['response_ms'])&&$metrics['response_ms']!==null?h($metrics['response_ms']).'ms':'— response'?></span></div>
  </div>
  <?php if($last):?><p class="uptime-last-check">Last probe <?=h($last['checked_at'])?> · HTTP <?=h($last['status_code']?:'no response')?> · <?=h($last['response_ms'])?>ms</p><?php endif?>

  <div class="uptime-grid-head"><span><?=date('M j',strtotime('-29 days'))?></span><span>30 days</span><span><?=date('M j')?></span></div>
  <div class="uptime-blocks"><?php $byDay=[];foreach($metrics['checks'] as $c)$byDay[date('Y-m-d',strtotime($c['checked_at']))][]=$c;for($i=29;$i>=0;$i--){$d=date('Y-m-d',strtotime("-$i days"));$status='';if(isset($byDay[$d])){$ss=array_column($byDay[$d],'status');$status=in_array('down',$ss,true)?'down':(in_array('degraded',$ss,true)?'degraded':'good');}?><div class="uptime-block <?=$status?>" title="<?=$d?> <?=$status?:'No data'?>"></div><?php }?></div>
  <div class="uptime-legend"><span><i class="dot good"></i>Good</span><span><i class="dot degraded"></i>Degraded</span><span><i class="dot down"></i>Down</span><span><i class="dot none"></i>No data</span></div>

  <section class="uptime-incidents"><div class="incident-title-row"><h2 class="section-title">RECENT INCIDENTS</h2><?php if(array_filter($incidents,static fn($i)=>$i['status']==='ongoing')):?><a class="btn sm" href="/builds/<?=h($p['slug'])?>/dashboard/fixes#runtime-fixes">Open incident fix prompts</a><?php endif?></div><div class="incident-list"><div class="uptime-legend"><span><i class="dot good"></i>Resolved</span><span><i class="dot down"></i>Ongoing</span></div><?php if(!$incidents):?><div class="incident-empty">No incidents in the last 30 days.</div><?php else:foreach($incidents as $i):?><div class="finding-row"><div class="finding-sev"><?=($i['status']==='resolved'?'✓':'●')?></div><div><strong><?=h($i['title'])?></strong><div class="muted"><?=h($i['opened_at'])?><?php if($i['resolved_at']):?> → <?=h($i['resolved_at'])?><?php endif?></div></div><div><?=h(ucfirst($i['status']))?></div></div><?php endforeach;endif?></div></section>
</div>
<?php endif?>
