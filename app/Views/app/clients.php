<div class="clients-wrap">
<section class="client-stack-card"><header><span class="client-section-icon">$</span><h2>STRIPE CONNECT</h2></header><div class="client-card-body">
<?php if(!$stripe||$stripe['onboarding_status']==='not_connected'):?>
  <p class="client-status-line">Accept payments and control client access from your app.</p>
  <p class="muted">SIUGOALS uses Stripe Connect. Your customers pay you directly through your connected Stripe account.</p>
  <form method="post" action="/builds/<?=h($p['slug'])?>/dashboard/clients/stripe"><?=csrf()?><button class="btn yellow client-primary">CONNECT STRIPE</button></form>
<?php elseif($stripe['onboarding_status']==='incomplete'):?>
  <p class="client-status-line"><span class="status-warning">ⓘ</span> Connected but onboarding incomplete</p>
  <p class="muted">Express dashboard (SIUGOALS-managed)</p>
  <div class="client-actions"><form method="post" action="/builds/<?=h($p['slug'])?>/dashboard/clients/stripe"><?=csrf()?><button class="btn yellow client-primary">↗ COMPLETE ONBOARDING</button></form><form method="post" action="/builds/<?=h($p['slug'])?>/dashboard/clients/stripe/reset" onsubmit="return confirm('Disconnect this Stripe account and start onboarding again?')"><?=csrf()?><button class="link-button" type="submit">Start over</button></form></div>
<?php else:?>
  <div class="stripe-ready-row"><div><p class="client-status-line"><span class="ready-check">✓</span> Connected and ready to accept payments</p><p class="muted">Charges and payouts are enabled.</p></div><?php if(!empty($stripeDashboardUrl)):?><a class="btn" href="<?=h($stripeDashboardUrl)?>" target="_blank" rel="noopener">↗ Open Stripe dashboard</a><?php endif?></div>
<?php endif?>
</div></section>

<section class="client-stack-card"><header><span class="client-section-icon">$</span><h2>PRICING</h2></header><div class="client-card-body">
<form method="post" action="/builds/<?=h($p['slug'])?>/dashboard/clients/pricing" class="pricing-form"><?=csrf()?>
<label>Monthly price (USD)<div class="money-input"><span>$</span><input name="price" type="number" step="0.01" min="0.50" max="9999" required value="<?=number_format(($pricing['monthly_price_cents']??999)/100,2,'.','')?>"></div></label>
<label>How long does client access last?<select name="access_days"><?php foreach([7=>'7 days',30=>'30 days',90=>'90 days',180=>'180 days',365=>'365 days'] as $d=>$txt):?><option value="<?=$d?>" <?=((int)($pricing['access_days']??30)===$d?'selected':'')?>><?=$txt?></option><?php endforeach?></select></label>
<button class="btn yellow pricing-save">SAVE PRICING</button>
</form>
<?php if(($stripe['onboarding_status']??'not_connected')==='complete'):?><div class="pricing-help"><strong>Payment page</strong><span><?=h(rtrim((string)\App\Core\Env::get('APP_URL'),'/' ).'/pay/'.$p['slug'])?></span><button type="button" class="btn sm" data-copy-text="<?=h(rtrim((string)\App\Core\Env::get('APP_URL'),'/' ).'/pay/'.$p['slug'])?>">Copy</button></div><?php endif?>
</div></section>

<section class="client-stack-card clients-list-card"><header><span class="client-section-icon">♧</span><h2>CLIENTS <span>(<?=count($clients)?>)</span></h2></header>
<?php if(!$clients):?><div class="client-empty"><div class="client-empty-icon">♧</div><h3>NO CLIENTS YET</h3><p>Clients who purchase access to your app will appear here.</p></div>
<?php else:?><div class="table-wrap"><table class="table clients-table"><thead><tr><th>Client</th><th>Status</th><th>Access started</th><th>Access expires</th><th>Customer</th></tr></thead><tbody><?php foreach($clients as $c):$expired=$c['access_expires_at']&&strtotime($c['access_expires_at'])<time();$status=$expired?'expired':$c['status'];?><tr><td><strong><?=h($c['email'])?></strong></td><td><span class="client-status <?=h($status)?>"><?=h(ucfirst($status))?></span></td><td><?=h($c['access_started_at']?date('M j, Y H:i',strtotime($c['access_started_at'])):'—')?></td><td><?=h($c['access_expires_at']?date('M j, Y H:i',strtotime($c['access_expires_at'])):'—')?></td><td class="muted"><?=h($c['external_customer_id']?:'—')?></td></tr><?php endforeach?></tbody></table></div><?php endif?>
</section>
</div>
