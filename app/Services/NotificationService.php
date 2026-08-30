<?php
namespace App\Services;
use App\Core\DB;
final class NotificationService {
    public static function record(int $userId,?int $projectId,string $type,string $title,string $message,?string $actionUrl=null,bool $email=false): void {
        DB::exec('INSERT INTO notifications(user_id,project_id,type,title,message,action_url) VALUES(?,?,?,?,?,?)',[$userId,$projectId,$type,$title,$message,$actionUrl]);
        if($email){$u=DB::one('SELECT email FROM users WHERE id=?',[$userId]);$pref=DB::one('SELECT alerts FROM notification_preferences WHERE user_id=?',[$userId]);if($u&&!empty($pref['alerts'])) MailService::send($u['email'],$title,'<p>'.htmlspecialchars($message,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</p>'.($actionUrl?'<p><a href="'.htmlspecialchars($actionUrl,ENT_QUOTES).'">Open SIUGOALS</a></p>':''));}
    }
    public static function onboardingWelcome(int $userId): void {
        $u=DB::one('SELECT email,name FROM users WHERE id=?',[$userId]);
        $pref=DB::one('SELECT onboarding FROM notification_preferences WHERE user_id=?',[$userId]);
        if(!$u || empty($pref['onboarding']) || !MailService::configured()) return;
        $key='onboarding:welcome';
        if(DB::one('SELECT id FROM notification_deliveries WHERE user_id=? AND delivery_key=?',[$userId,$key])) return;
        $name=htmlspecialchars((string)($u['name']?:'builder'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $link=htmlspecialchars(\App\Core\Util::baseUrl().'/import',ENT_QUOTES);
        if(MailService::send($u['email'],'Welcome to SIUGOALS','<p>Welcome '.$name.'.</p><p>Start by importing your product and running the evidence-based readiness flow.</p><p><a href="'.$link.'">Import your product</a></p>')){
            try{DB::exec('INSERT INTO notification_deliveries(user_id,delivery_key) VALUES(?,?)',[$userId,$key]);}catch(\Throwable $e){}
        }
    }

}
