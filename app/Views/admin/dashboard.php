<!doctype html>
<html lang="<?=h(\App\Core\I18n::locale())?>" dir="<?=h(\App\Core\I18n::dir())?>">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="<?=h(\App\Core\Csrf::token())?>">
<title>Platform Admin · SIUGOALS</title><link rel="stylesheet" href="/assets/css/app.css?v=11">
<style>
.admin-wrap{max-width:1240px;margin:0 auto;padding:34px 24px 60px}.admin-top{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;flex-wrap:wrap}.admin-actions{display:flex;gap:9px;align-items:center;flex-wrap:wrap}.admin-actions form{margin:0}.admin-title{font-size:44px;line-height:1;margin:8px 0 8px}.admin-sub{max-width:720px;color:#6d655e}.admin-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin:26px 0}.admin-stat{padding:20px}.admin-stat small{display:block;color:#746d67;margin-bottom:10px;text-transform:uppercase;letter-spacing:.08em}.admin-stat strong{font-size:34px}.admin-section{margin-top:26px}.admin-section-head{display:flex;justify-content:space-between;align-items:end;gap:12px;margin-bottom:10px}.admin-section h2{margin:0}.admin-table-wrap{overflow:auto;border:1px solid #e4ddd6;border-radius:14px;background:#fff}.admin-table{width:100%;border-collapse:collapse;min-width:820px}.admin-table th,.admin-table td{text-align:start;padding:13px 14px;border-bottom:1px solid #eee7e1;vertical-align:middle}.admin-table th{font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:#766e67;background:#fbf8f5}.admin-table tr:last-child td{border-bottom:0}.admin-pill{display:inline-flex;align-items:center;border:1px solid #ddd3ca;border-radius:999px;padding:4px 9px;font-size:12px}.admin-pill.strong{background:#211f20;color:#fff;border-color:#211f20}.admin-muted{color:#7a726b;font-size:13px}.admin-link{color:inherit;text-decoration:underline;text-underline-offset:3px}.admin-empty{padding:22px;color:#756e68}@media(max-width:850px){.admin-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.admin-title{font-size:36px}}@media(max-width:520px){.admin-grid{grid-template-columns:1fr}.admin-wrap{padding:24px 15px 50px}}
</style>
</head>
<body class="app-body <?=\App\Core\I18n::dir()==='rtl'?'rtl':'ltr'?>" style="background:#fbf7f3">
<main class="admin-wrap">
  <div class="admin-top">
    <div><div class="side-label" style="padding:0">SIUGOALS · PLATFORM ADMIN</div><h1 class="admin-title">Admin Console</h1><p class="admin-sub">Platform-wide operational view. Admin access can inspect and administer projects, while owner-only destructive actions remain restricted to each project owner.</p></div>
    <div class="admin-actions"><a class="btn" href="/builds">Products</a><a class="btn" href="/account/profile">Account</a><form method="post" action="/logout"><?=csrf()?><button class="btn dark" type="submit">Sign out</button></form></div>
  </div>

  <section class="admin-grid">
    <div class="card admin-stat"><small>Users</small><strong><?=number_format($adminStats['users'])?></strong></div>
    <div class="card admin-stat"><small>Products</small><strong><?=number_format($adminStats['projects'])?></strong></div>
    <div class="card admin-stat"><small>Active Pro</small><strong><?=number_format($adminStats['activeSubscriptions'])?></strong></div>
    <div class="card admin-stat"><small>Open incidents</small><strong><?=number_format($adminStats['openIncidents'])?></strong></div>
  </section>

  <section class="admin-section">
    <div class="admin-section-head"><div><div class="side-label" style="padding:0">ACCOUNTS</div><h2>Users</h2></div><span class="admin-muted">Latest 200</span></div>
    <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>User</th><th>Role</th><th>Tokens</th><th>Last login</th><th>Created</th></tr></thead><tbody>
      <?php foreach($adminUsers as $row):?><tr><td><strong><?=h($row['name'])?></strong><div class="admin-muted"><?=h($row['email'])?></div></td><td><?php if(!empty($row['is_platform_admin'])):?><span class="admin-pill strong">Platform admin</span><?php else:?><span class="admin-pill">User</span><?php endif?></td><td><?=number_format((int)$row['token_balance']+(int)$row['bonus_tokens'])?></td><td class="admin-muted"><?=h($row['last_login_at']?:'Never')?></td><td class="admin-muted"><?=h($row['created_at'])?></td></tr><?php endforeach?>
      <?php if(!$adminUsers):?><tr><td colspan="5" class="admin-empty">No users found.</td></tr><?php endif?>
    </tbody></table></div>
  </section>

  <section class="admin-section">
    <div class="admin-section-head"><div><div class="side-label" style="padding:0">PRODUCTS</div><h2>All products</h2></div><span class="admin-muted">Latest 200</span></div>
    <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Product</th><th>Owner</th><th>Readiness</th><th>Updated</th><th></th></tr></thead><tbody>
      <?php foreach($adminProjects as $row):?><tr><td><strong><?=h($row['name'])?></strong><div class="admin-muted"><?=h($row['url'])?></div></td><td><?=h($row['owner_name'])?><div class="admin-muted"><?=h($row['owner_email'])?></div></td><td><span class="admin-pill"><?=number_format((int)$row['readiness_score'])?>%</span></td><td class="admin-muted"><?=h($row['updated_at'])?></td><td><a class="admin-link" href="/builds/<?=h($row['slug'])?>/dashboard/readiness/overview">Open</a></td></tr><?php endforeach?>
      <?php if(!$adminProjects):?><tr><td colspan="5" class="admin-empty">No products found.</td></tr><?php endif?>
    </tbody></table></div>
  </section>

  <section class="admin-section">
    <div class="admin-section-head"><div><div class="side-label" style="padding:0">AUDIT</div><h2>Recent activity</h2></div><span class="admin-muted">Latest 60 events</span></div>
    <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Action</th><th>User</th><th>Product</th><th>Time</th></tr></thead><tbody>
      <?php foreach($adminAudit as $row):?><tr><td><code><?=h($row['action_key'])?></code></td><td class="admin-muted"><?=h($row['user_email']?:'System')?></td><td><?=h($row['project_name']?:'—')?></td><td class="admin-muted"><?=h($row['created_at'])?></td></tr><?php endforeach?>
      <?php if(!$adminAudit):?><tr><td colspan="4" class="admin-empty">No audit events found.</td></tr><?php endif?>
    </tbody></table></div>
  </section>
</main>
</body></html>
