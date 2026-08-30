<?php
use App\Services\SessionService;
$rangeLabels=['1h'=>'Last hour','12h'=>'Last 12 hours','24h'=>'Last 24 hours','7d'=>'Last 7 days','custom'=>'Custom'];
function qs4(array $over=[]): string { $q=$_GET; foreach($over as $k=>$v){ if($v===null)unset($q[$k]); else $q[$k]=$v; } return http_build_query($q); }
function dur4(int $s): string { if($s>=3600)return floor($s/3600).'h '.gmdate('i\m s\s',$s); if($s>=60)return floor($s/60).'m '.($s%60).'s'; return $s.'s'; }
function labelSignal4(string $s): string { return ucwords(str_replace('_',' ',$s)); }
$connection=\App\Services\RuntimeService::connectionState($p,180);
$hasRuntimeHistory=(bool)\App\Core\DB::one('SELECT id FROM runtime_sessions WHERE project_id=? LIMIT 1',[$p['id']]);
?>
<?php if($connection['state']==='never'&&!$hasRuntimeHistory):?>
<div class="empty-state"><div class="empty-inner"><div class="empty-icon">▦</div><div class="empty-title">CONNECT YOUR APP FIRST</div><p>Install the SDK to collect privacy-safe runtime sessions and signals.</p><a class="btn yellow" href="/builds/<?=h($p['slug'])?>/dashboard/connect">Connect your app</a></div></div>
<?php else:?>
<?php if($connection['state']==='stale'):?><div class="runtime-status-banner stale"><strong>Runtime connection is stale</strong><span>Last event <?=h($connection['last_seen_at'])?>. Historical sessions remain searchable.</span><a href="/builds/<?=h($p['slug'])?>/dashboard/connect">Reconnect</a></div><?php elseif($connection['state']==='live'):?><div class="runtime-status-banner live"><strong>Runtime is live</strong><span>New sessions, errors and supported behavior signals are arriving.</span></div><?php endif?>
<form method="get" id="sessionFilters" class="sessions-filter-form">
<div class="sessions-toolbar">
  <div class="popover-wrap">
    <button type="button" class="toolbar-btn toolbar-plain" data-popover="date-pop">▦ <?=h($rangeLabels[$filters['range']]??'Last 7 days')?>⌄</button>
    <div class="popover date-popover" id="date-pop" hidden>
      <div class="date-quick-grid">
        <?php foreach(['1h'=>'Last hour','12h'=>'Last 12 hours','24h'=>'Last 24 hours','7d'=>'Last 7 days'] as $rv=>$rl):?>
        <button type="submit" name="range" value="<?=$rv?>" class="date-quick <?=($filters['range']===$rv?'selected':'')?>"><?=$rl?></button>
        <?php endforeach?>
      </div>
      <div class="calendar-head"><strong><?=h($filters['from']->format('F Y'))?></strong><span>‹ &nbsp; ›</span></div>
      <div class="calendar-grid calendar-static">
        <?php foreach(['Su','Mo','Tu','We','Th','Fr','Sa'] as $d):?><strong><?=$d?></strong><?php endforeach;?>
        <?php $days=(int)$filters['from']->format('t');$first=(int)$filters['from']->modify('first day of this month')->format('w');for($i=0;$i<$first;$i++):?><span></span><?php endfor;for($i=1;$i<=$days;$i++):$sel=$i===(int)$filters['from']->format('j')||$i===(int)$filters['to']->format('j');?><span class="<?=$sel?'selected':''?>"><?=$i?></span><?php endfor;?>
      </div>
      <div class="custom-range-fields">
        
        <label>From <input type="date" name="from_date" value="<?=h($filters['from']->format('Y-m-d'))?>"><input type="time" name="from_time" value="<?=h($filters['from']->format('H:i'))?>"></label>
        <label>To <input type="date" name="to_date" value="<?=h($filters['to']->format('Y-m-d'))?>"><input type="time" name="to_time" value="<?=h($filters['to']->format('H:i'))?>"></label>
        <button class="btn coral sm" type="submit" name="range" value="custom">Apply range</button>
      </div>
    </div>
  </div>

  <input class="toolbar-search" name="q" placeholder="Search email, ID, device..." value="<?=h($filters['q'])?>">
  <button class="filter-icon-btn" type="submit" title="Apply search">⌕</button>

  <div class="popover-wrap">
    <button type="button" class="toolbar-btn" data-popover="more-pop">☷ More⌄</button>
    <div class="popover filter-popover" id="more-pop" hidden>
      <div class="filter-section"><div class="filter-section-head">OS <span>⌃</span></div><?php foreach($options['os'] as $v):?><label class="filter-check"><input type="checkbox" name="os[]" value="<?=h($v)?>" <?=in_array($v,$filters['os'],true)?'checked':''?>><?=h($v)?></label><?php endforeach?><div class="filter-empty" <?=!$options['os']?'':'hidden'?>>No OS data yet.</div></div>
      <div class="filter-section"><div class="filter-section-head">Browser <span>⌃</span></div><?php foreach($options['browser'] as $v):?><label class="filter-check"><input type="checkbox" name="browser[]" value="<?=h($v)?>" <?=in_array($v,$filters['browser'],true)?'checked':''?>><?=h($v)?></label><?php endforeach?></div>
      <div class="filter-section"><div class="filter-section-head">Country <span>⌃</span></div><input class="mini-search" type="search" placeholder="Search country" data-country-search><?php foreach($options['country'] as $v):?><label class="filter-check country-option"><input type="checkbox" name="country[]" value="<?=h($v)?>" <?=in_array($v,$filters['country'],true)?'checked':''?>><?=h(\App\Services\SessionService::flag($v))?> <?=h($v)?></label><?php endforeach?></div>
      <div class="filter-section"><div class="filter-section-head">Identity <span>⌃</span></div><label class="filter-check"><input type="radio" name="identity" value="identified" <?=$filters['identity']==='identified'?'checked':''?>>Identified</label><label class="filter-check"><input type="radio" name="identity" value="anonymous" <?=$filters['identity']==='anonymous'?'checked':''?>>Anonymous</label></div>
      <div class="popover-actions"><button class="btn coral sm" type="submit">Apply filters</button><a class="btn sm" href="?">Reset</a></div>
    </div>
  </div>

  <div class="popover-wrap">
    <button type="button" class="toolbar-btn <?=!empty($filters['signals'])?'active':''?>" data-popover="signals-pop">⌁ Signals⌄</button>
    <div class="popover signal-popover" id="signals-pop" hidden>
      <?php $signals=['rage_click'=>'Rage click','dead_click'=>'Dead click','error_cascade'=>'Error cascade','form_abandonment'=>'Form abandonment','error_then_exit'=>'Error then exit'];foreach($signals as $sv=>$sl):?>
      <label class="signal-check"><input type="checkbox" name="signals[]" value="<?=$sv?>" <?=in_array($sv,$filters['signals'],true)?'checked':''?>><span><?=h($sl)?></span></label>
      <?php endforeach?><div class="popover-actions"><button class="btn coral sm" type="submit">Apply</button></div>
    </div>
  </div>

  <div class="popover-wrap">
    <button type="button" class="toolbar-btn <?=($filters['min_duration']||$filters['max_duration'])?'active':''?>" data-popover="duration-pop">◷ <?=($filters['min_duration']||$filters['max_duration'])?'Duration':'All'?>⌄</button>
    <div class="popover right duration-popover" id="duration-pop" hidden>
      <button type="button" class="duration-all" data-duration-min="0">All durations</button>
      <div class="duration-quick"><button type="button" data-duration-min="10">≥ 10s</button><button type="button" data-duration-min="30">≥ 30s</button><button type="button" data-duration-min="60">≥ 1m</button><button type="button" data-duration-min="300">≥ 5m</button></div>
      <div class="duration-scale"><span class="scale-line"></span><i></i><i></i><i></i><i></i><i></i><i></i><div class="scale-labels"><span>Any</span><span>10s</span><span>30s</span><span>1m</span><span>5m</span><span>∞</span></div></div>
      <div class="duration-manual"><label>Min <input name="min_duration" id="minDuration" type="number" min="0" placeholder="Any" value="<?=$filters['min_duration']?:''?>"></label><label>Max <input name="max_duration" type="number" min="0" placeholder="Any" value="<?=$filters['max_duration']?:''?>"></label></div>
      <div class="popover-actions"><button class="btn coral sm" type="submit">Apply</button></div>
    </div>
  </div>

  <div class="popover-wrap">
    <button type="button" class="toolbar-btn <?=($filters['user']!==''?'active':'')?>" data-popover="user-pop">♙ User⌄</button>
    <div class="popover right user-popover" id="user-pop" hidden>
      <input class="mini-search" type="search" placeholder="Search by email or id..." data-user-search>
      <div class="user-option-list"><?php foreach($userOptions as $u):?><button type="submit" name="user" value="<?=h($u['user_key'])?>" class="user-option"><span><strong><?=h($u['email']?:$u['user_key'])?></strong><?php if($u['name']):?><small><?=h($u['name'])?></small><?php endif?></span><small><?=h($u['sessions'])?> session<?=((int)$u['sessions']===1?'':'s')?></small></button><?php endforeach?></div>
    </div>
  </div>

  <label class="toolbar-btn <?=($filters['errors']?'active':'')?>"><input type="checkbox" name="errors" value="1" <?=$filters['errors']?'checked':''?> data-filter-auto hidden>ⓘ Errors</label>
  <div class="popover-wrap"><button type="button" class="toolbar-btn identity-btn" data-popover="identity-pop">◉ Connect identity</button><div class="popover right identity-popover" id="identity-pop" hidden><strong>Connect user identity</strong><p class="muted">Call this after your app knows the signed-in user. SIUGOALS will group future sessions by this identity.</p><pre id="identitySnippet">SIUGOALS.identify({
  id: currentUser.id,
  email: currentUser.email,
  name: currentUser.name
});</pre><button type="button" class="btn sm" data-copy="identitySnippet">Copy code</button></div></div>

  <?php if(array_filter($_GET,fn($v)=>$v!==''&&$v!==null)):?><a class="clear-filters" href="?">Clear</a><?php endif?>
  <input type="hidden" name="per_page" value="<?=$filters['per_page']?>">
</div>
</form>

<?php if(!$result['rows']):?>
<div class="empty-state"><div class="empty-inner"><div class="empty-icon">▣</div><div class="empty-title">NO SESSIONS MATCH</div><p><?=($filters['errors']?'No sessions with errors in this time range. ':'No sessions match these filters in this time range. ')?>Widen the time range or clear the filters.</p><a href="?">Clear filters</a></div></div>
<?php else:?>
<div class="table-wrap sessions-table-wrap"><table class="table sessions-table"><thead><tr><th><input type="checkbox"></th><th>User ↕ / Started ↕</th><th>Environment</th><th>Country ↕</th><th>Duration ↕</th><th>Activity ↕</th><th>Errors ↕</th><th>Signals ↕</th></tr></thead><tbody>
<?php foreach($result['rows'] as $s):?>
<tr><td><input type="checkbox"></td><td><div class="session-user"><span class="session-count-circle <?=((int)$s['error_count']===0?'teal':'')?>"><?=$s['error_count']?></span><span class="session-status-dot"></span><div class="session-meta"><strong><a href="/builds/<?=h($p['slug'])?>/dashboard/sessions/<?=$s['id']?>">#<?=h(substr($s['identity_id']?:$s['session_key'],0,8))?></a></strong><small><?=h($s['identity_email']?:'about '.max(1,(int)floor((time()-strtotime($s['started_at']))/3600)).' hours ago')?> · <?=h(date('M j, H:i',strtotime($s['started_at'])))?></small></div></div></td>
<td><strong><?=h($s['os']?:'Unknown')?></strong><div class="muted"><?=h($s['browser']?:'Unknown')?></div></td>
<td class="country-cell"><?=h(\App\Services\SessionService::flag((string)$s['country_code']))?></td>
<td><?=h(dur4((int)$s['duration_seconds']))?></td>
<td><div class="activity-spark" title="<?=h($s['page_count'])?> page views"><?php foreach($s['activity'] as $v):?><i style="height:<?=max(3,min(28,3+$v*3))?>px" class="<?=$v?'hot':''?>"></i><?php endforeach?></div></td>
<td class="number-cell"><?=((int)$s['error_count']?:'—')?></td><td class="number-cell" title="<?=h(implode(', ',array_map('labelSignal4',$s['signal_types'])))?>"><?=((int)$s['signal_count']?:'—')?></td></tr>
<?php endforeach?></tbody></table></div>
<div class="pagination sessions-pagination"><form method="get"><?php foreach($_GET as $k=>$v){ if(in_array($k,['page','per_page'],true)) continue; if(is_array($v)){ foreach($v as $vv){ echo '<input type="hidden" name="'.h($k).'[]" value="'.h($vv).'">'; } } else { echo '<input type="hidden" name="'.h($k).'" value="'.h($v).'">'; } } ?><label>Rows per page <select name="per_page" onchange="this.form.submit()"><?php foreach([10,20,50,100] as $n):?><option <?=$filters['per_page']===$n?'selected':''?>><?=$n?></option><?php endforeach?></select></label></form><span><?=($result['count']?($result['page']-1)*$result['per_page']+1:0)?>–<?=min($result['count'],$result['page']*$result['per_page'])?> of <?=$result['count']?> &nbsp; <a class="page-btn <?=$result['page']<=1?'disabled':''?>" href="?<?=h(qs4(['page'=>max(1,$result['page']-1)]))?>">‹</a> &nbsp; <?=$result['page']?> / <?=$result['pages']?> &nbsp; <a class="page-btn <?=$result['page']>=$result['pages']?'disabled':''?>" href="?<?=h(qs4(['page'=>min($result['pages'],$result['page']+1)]))?>">›</a></span></div>
<?php endif?>
<?php endif?>
