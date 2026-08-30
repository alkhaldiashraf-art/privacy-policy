<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\Core\DB;
use App\Services\MailService;

if(!MailService::configured()){echo "mail disabled\n";exit;}
$week=gmdate('o-\\WW');
$users=DB::all('SELECT u.id,u.email,u.name FROM users u JOIN notification_preferences np ON np.user_id=u.id WHERE np.weekly_digest=1');
$sent=0;
foreach($users as $u){
    $key='weekly_digest:'.$week;
    if(DB::one('SELECT id FROM notification_deliveries WHERE user_id=? AND delivery_key=?',[$u['id'],$key]))continue;
    $counts=DB::one('SELECT COUNT(*) total, SUM(CASE WHEN status="need_attention" THEN 1 ELSE 0 END) attention, SUM(CASE WHEN status="pending" OR status="question_required" THEN 1 ELSE 0 END) pending FROM checks WHERE project_id IN (SELECT project_id FROM project_members WHERE user_id=?)',[$u['id']])?:[];
    $body='<p>Hello '.htmlspecialchars((string)($u['name']?:'builder'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').',</p><p>Your SIUGOALS weekly digest:</p><ul><li>'.(int)($counts['attention']??0).' checks need attention</li><li>'.(int)($counts['pending']??0).' checks are pending</li></ul><p><a href="'.htmlspecialchars(\App\Core\Util::baseUrl().'/builds',ENT_QUOTES).'">Open SIUGOALS</a></p>';
    if(MailService::send($u['email'],'Your SIUGOALS weekly digest',$body)){
        try{DB::exec('INSERT INTO notification_deliveries(user_id,delivery_key) VALUES(?,?)',[$u['id'],$key]);$sent++;}catch(Throwable $e){}
    }
}
echo "sent={$sent}\n";
