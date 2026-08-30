<?php $current=$project??null; $user=$user??\App\Core\Auth::user(); ?>
<!doctype html><html lang="<?=h(\App\Core\I18n::locale())?>" dir="<?=h(\App\Core\I18n::dir())?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="<?=h(\App\Core\Csrf::token())?>"><title><?=h($title)?> · SIUGOALS</title><link rel="stylesheet" href="/assets/css/app.css?v=13"><link rel="stylesheet" href="/assets/css/tools-5-5.css?v=1"></head><body class="app-body <?=\App\Core\I18n::dir()==='rtl'?'rtl':'ltr'?>">
<div class="app-shell">
<aside class="project-sidebar" id="projectSidebar">
  <div class="project-switcher-wrap">
    <button class="project-switcher" data-menu="project-menu" aria-expanded="false">
      <span class="project-mark"><?php if($current):?><?=strtoupper(substr(h($current['name']),0,1))?><?php else:?>S<?php endif?></span>
      <span class="project-switcher-name"><?=h($current['name']??'SIUGOALS')?></span><span class="chev">⌃⌄</span>
    </button>
    <div class="menu project-menu" id="project-menu" hidden>
      <?php foreach($projects as $pp):?><a href="/builds/<?=h($pp['slug'])?>/dashboard/readiness/overview" class="menu-project"><span class="project-mark mini"><?=strtoupper(substr(h($pp['name']),0,1))?></span><span><?=h($pp['name'])?></span></a><?php endforeach?>
      <a href="/import" class="menu-project add"><span class="plus">＋</span><span><?=h(t('Add a product'))?></span></a>
    </div>
  </div>
  <nav class="side-nav">
    <div class="side-group"><div class="side-label"><?=h(t('LAUNCH'))?></div>
      <a class="side-item <?=$active==='readiness'?'active':''?>" href="<?=$current?'/builds/'.h($current['slug']).'/dashboard/readiness/overview':'/builds'?>"><span class="i">◎</span><?=h(t('Readiness'))?></a>
      <a class="side-item <?=$active==='website'?'active':''?>" href="<?=$current?'/builds/'.h($current['slug']).'/dashboard/website-scan':'/builds'?>"><span class="i">⌕</span><?=h(t('Website Scan'))?></a>
      <a class="side-item <?=$active==='source'?'active':''?>" href="<?=$current?'/builds/'.h($current['slug']).'/dashboard/source':'/builds'?>"><span class="i">▣</span><?=h(t('Code Scan'))?></a>
      <a class="side-item <?=$active==='fixes'?'active':''?>" href="<?=$current?'/builds/'.h($current['slug']).'/dashboard/fixes':'/builds'?>"><span class="i">✦</span><?=h(t('Fix Center'))?></a>
      <a class="side-item <?=$active==='trust'?'active':''?>" href="<?=$current?'/builds/'.h($current['slug']).'/dashboard/trust':'/builds'?>"><span class="i">⬡</span><?=h(t('Trust center'))?></a>
    </div>
    <div class="side-group"><div class="side-label"><?=h(t('RUN'))?></div>
      <a class="side-item <?=$active==='runtime'?'active':''?>" href="<?=$current?'/builds/'.h($current['slug']).'/dashboard/connect':'/builds'?>"><span class="i">⌁</span><?=h(t('Runtime SDK'))?></a>
      <a class="side-item <?=$active==='uptime'?'active':''?>" href="<?=$current?'/builds/'.h($current['slug']).'/dashboard/uptime':'/builds'?>"><span class="i">⌁</span><?=h(t('Uptime'))?></a>
      <a class="side-item <?=$active==='errors'?'active':''?>" href="<?=$current?'/builds/'.h($current['slug']).'/dashboard/errors':'/builds'?>"><span class="i">☼</span><?=h(t('Errors'))?></a>
      <a class="side-item <?=$active==='sessions'?'active':''?>" href="<?=$current?'/builds/'.h($current['slug']).'/dashboard/sessions':'/builds'?>"><span class="i">▦</span><?=h(t('Sessions'))?></a>
    </div>
    <div class="side-group"><div class="side-label"><?=h(t('PRODUCT'))?></div>
      <a class="side-item <?=$active==='clients'?'active':''?>" href="<?=$current?'/builds/'.h($current['slug']).'/dashboard/clients':'/builds'?>"><span class="i">♧</span><?=h(t('Clients'))?></a>
      <a class="side-item <?=$active==='settings'?'active':''?>" href="<?=$current?'/builds/'.h($current['slug']).'/dashboard/settings':'/builds'?>"><span class="i">⚙</span><?=h(t('Settings'))?></a>
    </div>
  </nav>
  <div class="sidebar-bottom">
    <a class="invite-card" href="/account/referrals"><span class="gift-ring">⌘</span><span><strong><?=h(t('Invite builders'))?></strong><small>1,000 <?=h(t('tokens per referral'))?></small></span><span class="invite-x">×</span></a>
    <a class="plain-side" href="mailto:support@siugoals.com"><span>⊚</span> <?=h(t('Get help'))?></a>
    <a class="token-block" href="/account/usage"><div><span class="token-icon">▣</span> <strong><?=number_format((int)($user['token_balance']??0)+(int)($user['bonus_tokens']??0))?> / <?=number_format((int)($user['token_cycle_allocation']??1000))?></strong> <small>tokens</small></div><div class="token-bar"><span style="width:<?=min(100,((int)($user['token_balance']??0)+(int)($user['bonus_tokens']??0))/max(1,(int)($user['token_cycle_allocation']??1000))*100)?>%"></span></div></a>
    <div class="user-menu-wrap"><button class="user-button" data-menu="user-menu"><span class="avatar"><?=h(strtoupper(substr((string)($user['name']??'S'),0,1)))?></span><strong><?=h($user['name']??'Account')?></strong><span class="chev">⌃⌄</span></button>
      <div class="menu user-menu" id="user-menu" hidden><?php if(\App\Core\Auth::isPlatformAdmin((int)($user['id']??0))):?><a href="/admin">◆ <span>Admin Console</span></a><?php endif?><a href="/account/profile">▣ <span><?=h(t('Profile'))?></span></a><a href="/account/billing">▤ <span><?=h(t('Billing'))?></span></a><a href="/account/usage">▥ <span><?=h(t('Usage'))?></span></a><a href="/account/notifications">♧ <span><?=h(t('Notifications'))?></span></a><a href="/language/en">🌐 <span>English</span></a><a href="/language/ar">🌐 <span>العربية</span></a><form method="post" action="/logout"><?=csrf()?><button>↪ <span><?=h(t('Sign out'))?></span></button></form></div>
    </div>
  </div>
</aside>
<?php
$topbarPublicLabel='Trust page'; $topbarPublicIcon='◉';
if(($active??'')==='uptime'){ $topbarPublicLabel='Status page'; $topbarPublicIcon='⌁'; }
elseif(($active??'')==='settings'){ $topbarPublicLabel='View Public Page'; $topbarPublicIcon='◉'; }
?>
<main class="app-main"><header class="topbar"><button class="mobile-nav-toggle" type="button" data-sidebar-toggle aria-label="Open navigation">☰</button><div class="topbar-title"><span class="topbar-icon">▯</span><span><?=strtoupper(h($title))?></span></div><?php if($current):?><a class="topbar-link" href="/builds/<?=h($current['slug'])?>/trust"><?=h($topbarPublicIcon)?> <?=h(t($topbarPublicLabel))?></a><?php endif?></header><div class="main-content"><?php if($current && ownerTestMode() && isProjectOwner((int)$current['id'])):?><div class="acceptance-warning"><strong>Owner acceptance mode is active.</strong> Validation scans and agent runs execute normally but do not consume tokens. Turn it off before commercial launch.</div><?php endif?>
<?php if(!empty($_SESSION['flash_error'])):?><div class="flash error"><?=h($_SESSION['flash_error']);unset($_SESSION['flash_error'])?></div><?php endif?>
<?php if(!empty($_SESSION['flash_success'])):?><div class="flash success"><?=h($_SESSION['flash_success']);unset($_SESSION['flash_success'])?></div><?php endif?>
