<?php
namespace App\Services;
use App\Core\DB;
final class UptimeService {
    public static function check(array $project): array {
        $code=0;$err=null;$ms=0;
        try{$r=SafeHttp::request((string)$project['url'],true,10,4,'SIUGOALS-Uptime/1.2');if((int)($r['status']??0)===405)$r=SafeHttp::request((string)$project['url'],false,10,4,'SIUGOALS-Uptime/1.2');$code=(int)$r['status'];$ms=(int)$r['response_ms'];}
        catch(\Throwable $e){$err='Health check failed';}
        $status=($err||$code<200||$code>=500)?'down':($ms>2500||$code>=400?'degraded':'good');
        DB::exec('INSERT INTO uptime_checks(project_id,status,status_code,response_ms,checked_at,error_message) VALUES(?,?,?,?,CURRENT_TIMESTAMP,?)',[$project['id'],$status,$code,$ms,$err]);
        $open=DB::one('SELECT * FROM incidents WHERE project_id=? AND status="ongoing" ORDER BY opened_at DESC LIMIT 1',[$project['id']]);
        $recent=DB::all('SELECT status FROM uptime_checks WHERE project_id=? ORDER BY checked_at DESC LIMIT 3',[$project['id']]);
        $downStreak=count($recent)>=2&&($recent[0]['status']??'')==='down'&&($recent[1]['status']??'')==='down';
        $degradedStreak=count($recent)>=3&&count(array_filter(array_slice($recent,0,3),fn($x)=>($x['status']??'')==='degraded'))===3;
        if($status==='down'&&$downStreak&&!$open){
            DB::exec('INSERT INTO incidents(project_id,status,title,opened_at) VALUES(?,"ongoing","Application unreachable",CURRENT_TIMESTAMP)',[$project['id']]);
            NotificationService::record((int)$project['owner_user_id'],(int)$project['id'],'uptime_down','App is down',(string)$project['name'].' is unreachable.','/builds/'.$project['slug'].'/dashboard/uptime',true);
        }elseif($status==='degraded'&&$degradedStreak&&!$open){
            DB::exec('INSERT INTO incidents(project_id,status,title,opened_at) VALUES(?,"ongoing","Application response degraded",CURRENT_TIMESTAMP)',[$project['id']]);
            NotificationService::record((int)$project['owner_user_id'],(int)$project['id'],'uptime_degraded','App is degraded',(string)$project['name'].' is repeatedly slow or returning an error response.','/builds/'.$project['slug'].'/dashboard/uptime',true);
        }elseif($status==='down'&&$open&&$open['title']!=='Application unreachable'){
            DB::exec('UPDATE incidents SET title="Application unreachable" WHERE id=?',[$open['id']]);
        }
        // A degraded probe is still evidence of an active problem. Resolve only
        // after a genuinely healthy probe, rather than hiding a continuing incident.
        if($status==='good'&&$open){DB::exec('UPDATE incidents SET status="resolved",resolved_at=CURRENT_TIMESTAMP WHERE id=?',[$open['id']]);NotificationService::record((int)$project['owner_user_id'],(int)$project['id'],'uptime_recovered','App recovered',(string)$project['name'].' is healthy again.','/builds/'.$project['slug'].'/dashboard/uptime',true);}
        return compact('status','code','ms','err');
    }
    public static function metrics(int $pid): array {
        $driver=(string)DB::pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);if($driver==='sqlite'){$rows=DB::all('SELECT * FROM uptime_checks WHERE project_id=? AND checked_at>=datetime(CURRENT_TIMESTAMP,"-30 days") ORDER BY checked_at ASC',[$pid]);}else{$rows=DB::all('SELECT * FROM uptime_checks WHERE project_id=? AND checked_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) ORDER BY checked_at ASC',[$pid]);}
        $total=count($rows);$up=count(array_filter($rows,fn($r)=>$r['status']!=='down'));$timed=array_values(array_filter(array_map(fn($r)=>(int)$r['response_ms'],$rows),fn($ms)=>$ms>0));$avg=$timed?(int)round(array_sum($timed)/count($timed)):null;return ['uptime'=>$total?round($up/$total*100,2):null,'response_ms'=>$avg,'checks'=>$rows];
    }
}
