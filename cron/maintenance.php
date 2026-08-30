<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\Core\DB;
use App\Core\Env;
$now=date('Y-m-d H:i:s');
// Expire client access that has passed its deadline.
DB::exec('UPDATE clients SET status="expired" WHERE status="active" AND access_expires_at IS NOT NULL AND access_expires_at<=CURRENT_TIMESTAMP');
// Apply bounded telemetry retention. Aggregated error issues/incidents remain for
// operational history, while raw Runtime events, replays and old probes expire.
$runtimeDays=max(7,min(365,(int)Env::get('RUNTIME_RETENTION_DAYS',30)));
$uptimeDays=max(30,min(730,(int)Env::get('UPTIME_RETENTION_DAYS',90)));
DB::exec('DELETE FROM runtime_events WHERE occurred_at<DATE_SUB(CURRENT_TIMESTAMP,INTERVAL '.$runtimeDays.' DAY)');
DB::exec('DELETE FROM runtime_sessions WHERE last_seen_at<DATE_SUB(CURRENT_TIMESTAMP,INTERVAL '.$runtimeDays.' DAY)');
DB::exec('DELETE FROM uptime_checks WHERE checked_at<DATE_SUB(CURRENT_TIMESTAMP,INTERVAL '.$uptimeDays.' DAY)');
DB::exec('DELETE FROM password_resets WHERE expires_at<CURRENT_TIMESTAMP OR used_at IS NOT NULL');
DB::exec('DELETE FROM rate_limits WHERE window_started_at<?',[time()-86400]);
// Reset the cycle allocation when a user's cycle expires. Bonus/top-up tokens are intentionally preserved.
$users=DB::all('SELECT id FROM users WHERE token_reset_at IS NULL OR token_reset_at<=CURRENT_TIMESTAMP');
foreach($users as $u){$sub=DB::one('SELECT p.token_allocation FROM subscriptions s JOIN plans p ON p.id=s.plan_id WHERE s.user_id=? AND s.status="active" ORDER BY s.id DESC LIMIT 1',[$u['id']]);$allocation=(int)($sub['token_allocation']??1000);DB::exec('UPDATE users SET token_cycle_allocation=?,token_balance=?,token_reset_at=DATE_ADD(CURRENT_TIMESTAMP,INTERVAL 30 DAY) WHERE id=?',[$allocation,$allocation,$u['id']]);}
echo 'maintenance '.count($users).' reset(s); runtime retention '.$runtimeDays.'d; uptime retention '.$uptimeDays.'d at '.$now."\n";
