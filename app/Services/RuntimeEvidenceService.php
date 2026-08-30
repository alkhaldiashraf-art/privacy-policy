<?php
namespace App\Services;

use App\Core\DB;

/** Refresh readiness checks from stored runtime/uptime evidence. */
final class RuntimeEvidenceService {
    public static function refresh(array $project): array {
        $pid=(int)$project['id'];
        $now=time();
        $freshHeartbeat=!empty($project['runtime_last_seen_at']) && (($ts=strtotime((string)$project['runtime_last_seen_at']))!==false) && $ts>=($now-300);
        $since10=date('Y-m-d H:i:s',$now-600);
        $since30d=date('Y-m-d H:i:s',$now-30*86400);
        $recentUptime=(int)(DB::one('SELECT COUNT(*) c FROM uptime_checks WHERE project_id=? AND checked_at>=?',[$pid,$since10])['c']??0);
        $uptimeSamples=(int)(DB::one('SELECT COUNT(*) c FROM uptime_checks WHERE project_id=? AND checked_at>=?',[$pid,$since30d])['c']??0);
        $runtimeEvents=(int)(DB::one('SELECT COUNT(*) c FROM runtime_events WHERE project_id=? AND occurred_at>=?',[$pid,$since30d])['c']??0);
        $identifies=(int)(DB::one('SELECT COUNT(*) c FROM runtime_events WHERE project_id=? AND event_type="identify" AND occurred_at>=?',[$pid,$since30d])['c']??0);
        $identifiedSessions=(int)(DB::one('SELECT COUNT(*) c FROM runtime_sessions WHERE project_id=? AND started_at>=? AND (identity_id IS NOT NULL OR identity_email IS NOT NULL)',[$pid,$since30d])['c']??0);
        $errors=(int)(DB::one('SELECT COUNT(*) c FROM error_issues WHERE project_id=? AND first_seen_at>=?',[$pid,$since30d])['c']??0);

        if($freshHeartbeat || $recentUptime>0){
            $why=$freshHeartbeat?'A live Runtime SDK heartbeat was received within the last 5 minutes.':'A fresh server-side uptime sample exists within the last 10 minutes.';
            ReadinessService::setStatus($pid,'monitoring','ready','runtime',$why,1.0,['fresh_heartbeat'=>$freshHeartbeat,'recent_uptime_samples'=>$recentUptime]);
        } elseif(!empty($project['runtime_last_seen_at']) || $uptimeSamples>0){
            ReadinessService::setStatus($pid,'monitoring','pending','runtime','Monitoring evidence exists, but it is stale; a current heartbeat/uptime sample is required.',0.95,['last_seen_at'=>$project['runtime_last_seen_at']??null,'uptime_samples_30d'=>$uptimeSamples]);
        } else {
            ReadinessService::setStatus($pid,'monitoring','pending','runtime','No live runtime heartbeat or recent uptime evidence has been collected yet.',1.0);
        }

        if(empty($project['error_capture_enabled'])){
            ReadinessService::setStatus($pid,'error_capture','not_applicable','runtime','Client error capture is intentionally disabled for this project.',1.0);
        } elseif($freshHeartbeat){
            ReadinessService::setStatus($pid,'error_capture','ready','runtime','Runtime SDK is live with client error capture enabled.'.($errors?' Captured error issues exist, proving the pipeline has received errors.':''),1.0,['error_issues_30d'=>$errors]);
        } else {
            ReadinessService::setStatus($pid,'error_capture','pending','runtime','Error capture is enabled in SIUGOALS, but a fresh runtime connection is required to verify it is active in production.',0.9);
        }

        if($identifies>0 || $identifiedSessions>0){
            ReadinessService::setStatus($pid,'runtime_identity','ready','runtime','Runtime identity evidence was received from the production app.',1.0,['identify_events_30d'=>$identifies,'identified_sessions_30d'=>$identifiedSessions]);
        } elseif($freshHeartbeat || $runtimeEvents>0){
            ReadinessService::setStatus($pid,'runtime_identity','need_attention','runtime','Runtime events are arriving, but no signed-in app identity has been linked with SIUGOALS.identify().',0.95,['runtime_events_30d'=>$runtimeEvents]);
        } else {
            ReadinessService::setStatus($pid,'runtime_identity','pending','runtime','Runtime identity cannot be verified until the production SDK sends live events.',1.0);
        }

        if(!empty($project['trust_badge_enabled'])){
            ReadinessService::setStatus($pid,'trust_page','ready','configuration','The SIUGOALS public trust badge/page is enabled for this project.',1.0);
        } else {
            ReadinessService::setStatus($pid,'trust_page','pending','configuration','The public trust badge is not enabled yet.',1.0);
        }

        $github=DB::one('SELECT repo_full_name FROM github_connections WHERE project_id=?',[$pid]);
        if(!empty($github['repo_full_name'])) ReadinessService::setStatus($pid,'source_control','ready','github','Read-only GitHub repository connected: '.$github['repo_full_name'],1.0);

        ReadinessService::recalc($pid);
        return [
            'fresh_heartbeat'=>$freshHeartbeat,
            'recent_uptime_samples'=>$recentUptime,
            'uptime_samples_30d'=>$uptimeSamples,
            'runtime_events_30d'=>$runtimeEvents,
            'identify_events_30d'=>$identifies,
            'identified_sessions_30d'=>$identifiedSessions,
            'error_issues_30d'=>$errors,
        ];
    }
}
