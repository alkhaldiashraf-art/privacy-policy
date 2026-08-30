<?php
$remaining=(int)$user['token_balance']+(int)($user['bonus_tokens']??0);
$allocation=(int)$user['token_cycle_allocation'];
$used=max(0,$allocation-(int)$user['token_balance']);
$days=$user['token_reset_at']?max(0,(int)ceil((strtotime($user['token_reset_at'])-time())/86400)):30;
$groups=['Scan'=>0,'Fix'=>0];$areas=['Readiness'=>0,'Source'=>0,'Agents'=>0,'Other'=>0];$runs=['Readiness'=>0,'Runtime'=>0];
foreach($activity as $a){$n=abs(min(0,(int)$a['tokens_delta']));$key=(string)$a['action_key'];if(str_contains($key,'agent'))$groups['Fix']+=$n;else if(str_contains($key,'scan'))$groups['Scan']+=$n;if(str_contains($key,'readiness')){$areas['Readiness']+=$n;$runs['Readiness']+=$n;}elseif(str_contains($key,'source')||str_contains($key,'github'))$areas['Source']+=$n;elseif(str_contains($key,'agent'))$areas['Agents']+=$n;else $areas['Other']+=$n;if(str_contains($key,'runtime'))$runs['Runtime']+=$n;}
$chart=['scanFix'=>$groups,'perArea'=>$areas,'readinessRun'=>$runs];
?>
<div class="account-title"><h1><?=h(t('Usage'))?></h1><p>Token usage for your current billing cycle.</p></div>
<div class="metric-grid"><div class="metric-card"><small>Tokens remaining</small><strong><?=number_format($remaining)?></strong></div><div class="metric-card"><small>Tokens used</small><strong><?=number_format($used)?></strong></div><div class="metric-card"><small>Cycle allocation</small><strong><?=number_format($allocation)?></strong></div><div class="metric-card"><small>Days until reset</small><strong><?=$days?></strong></div></div>
<div class="billing-card usage-chart-card" data-usage-chart="<?=h(json_encode($chart,JSON_UNESCAPED_SLASHES))?>"><div class="segmented usage-tabs"><button class="active" data-usage-tab="scanFix">Scan vs Fix</button><button data-usage-tab="perArea">Per area</button><button data-usage-tab="readinessRun">Readiness vs Run</button></div><div class="usage-bars" id="usageBars" aria-label="Usage chart"></div></div>
<h2 class="recent-title">Recent activity</h2><div class="card table-scroll"><table class="table"><thead><tr><th>Date</th><th>Description</th><th>Project</th><th>Tokens</th></tr></thead><tbody><?php if(!$activity):?><tr><td colspan="4" class="muted">No token activity in this cycle.</td></tr><?php else:foreach($activity as $a):?><tr><td><?=h($a['created_at'])?></td><td><?=h($a['description'])?></td><td><?=h($a['project_name']?:'—')?></td><td class="token-delta <?=((int)$a['tokens_delta'])<0?'negative':'positive'?>"><?=((int)$a['tokens_delta']>0?'+':'').(int)$a['tokens_delta']?></td></tr><?php endforeach;endif?></tbody></table></div>
