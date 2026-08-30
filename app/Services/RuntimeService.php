<?php
namespace App\Services;
use App\Core\DB; use App\Core\Util;
final class RuntimeService {
    public static function originAllowed(array $project): bool {
        $origin=(string)($_SERVER['HTTP_ORIGIN']??'');
        if($origin==='') return true;
        $actual=self::originParts($origin);$expected=self::originParts((string)$project['url']);
        return $actual!==null && $expected!==null && hash_equals(implode('|',$expected),implode('|',$actual));
    }
    public static function expectedOrigin(array $project): string {
        $parts=self::originParts((string)$project['url']);if($parts===null)return '';
        [$scheme,$host,$port]=$parts;$default=$scheme==='https'?443:80;
        return $scheme.'://'.$host.($port!==$default?':'.$port:'');
    }
    private static function normalizeHost(string $host): string {
        return strtolower(rtrim(trim($host),'.'));
    }
    private static function originParts(string $url): ?array {
        $u=parse_url($url);if(!$u)return null;
        $scheme=strtolower((string)($u['scheme']??''));$host=self::normalizeHost((string)($u['host']??''));
        if(!in_array($scheme,['http','https'],true)||$host==='')return null;
        $port=(int)($u['port']??($scheme==='https'?443:80));
        return [$scheme,$host,$port];
    }
    public static function projectBySlug(string $slug): ?array { return DB::one('SELECT * FROM projects WHERE slug=?',[$slug]); }
    public static function ingest(array $project,array $p): array {
        $sid=preg_replace('/[^A-Za-z0-9_-]/','',(string)($p['sessionId']??'')); if(strlen($sid)<8) throw new \InvalidArgumentException('Invalid session id');
        $now=date('Y-m-d H:i:s'); if(empty($p['countryCode']) && !empty($_SERVER['HTTP_CF_IPCOUNTRY']) && preg_match('/^[A-Z]{2}$/',$_SERVER['HTTP_CF_IPCOUNTRY'])){$p['countryCode']=$_SERVER['HTTP_CF_IPCOUNTRY'];}$p['countryCode']=strtoupper(substr((string)($p['countryCode']??''),0,2));if(!preg_match('/^[A-Z]{2}$/',$p['countryCode']))$p['countryCode']=''; $event=(string)($p['type']??'heartbeat'); $allowed=['heartbeat','pageview','click','scroll','snapshot','mutation','error','network_error','signal','identify','session_end']; if(!in_array($event,$allowed,true))$event='heartbeat';if(in_array($event,['snapshot','mutation'],true)&&empty($project['session_replay_enabled']))return ['ok'=>true,'ignored'=>true,'reason'=>'session_replay_disabled','flags'=>self::flags($project)];if(in_array($event,['error','network_error'],true)&&empty($project['error_capture_enabled']))return ['ok'=>true,'ignored'=>true,'reason'=>'error_capture_disabled','flags'=>self::flags($project)];
        $s=DB::one('SELECT * FROM runtime_sessions WHERE project_id=? AND session_key=?',[$project['id'],$sid]);
        if(!$s){ DB::exec('INSERT INTO runtime_sessions(project_id,session_key,anonymous_id,os,browser,device,country_code,country_name,ip_hash,user_agent,started_at,last_seen_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[$project['id'],$sid,substr((string)($p['anonymousId']??''),0,80),substr((string)($p['os']??''),0,100),substr((string)($p['browser']??''),0,100),substr((string)($p['device']??''),0,100),substr((string)($p['countryCode']??''),0,2),substr((string)($p['countryName']??''),0,100),Util::hashIp(Util::ip()),substr($_SERVER['HTTP_USER_AGENT']??'',0,500),$now,$now]); $s=DB::one('SELECT * FROM runtime_sessions WHERE project_id=? AND session_key=?',[$project['id'],$sid]); }
        $duration=max(0,time()-strtotime($s['started_at']));
        $identity=is_array($p['identity']??null)?$p['identity']:[];$identityId=$event==='identify'?substr((string)($identity['id']??''),0,190):$s['identity_id']; $identityEmail=$event==='identify'?strtolower(trim(substr((string)($identity['email']??''),0,190))):$s['identity_email'];if($event==='identify'&&$identityEmail!==''&&!filter_var($identityEmail,FILTER_VALIDATE_EMAIL))$identityEmail=''; $identityName=$event==='identify'?substr((string)($identity['name']??''),0,190):$s['identity_name'];
        $signal=$event==='signal'?substr((string)($p['signal']??''),0,80):null;
        $isErr=in_array($event,['error','network_error'],true); $isSig=$event==='signal'&&$signal!=='performance_sample';
        DB::exec('UPDATE runtime_sessions SET last_seen_at=?,duration_seconds=?,identity_id=?,identity_email=?,identity_name=?,error_count=error_count+?,signal_count=signal_count+?,ended_at=CASE WHEN ?=1 THEN ? ELSE NULL END WHERE id=?',[$now,$duration,$identityId?:null,$identityEmail?:null,$identityName?:null,$isErr?1:0,$isSig?1:0,$event==='session_end'?1:0,$event==='session_end'?$now:null,$s['id']]);
        $meta=is_array($p['meta']??null)?$p['meta']:[];if($event==='snapshot')$meta=self::snapshotMeta($meta);else self::redact($meta);$pageUrl=self::safePageUrl((string)($p['url']??''));$message=self::safeText((string)($p['message']??''),4000);$stack=self::safeText((string)($p['stack']??''),12000); if($event==='snapshot'){ $sc=(int)(DB::one("SELECT COUNT(*) c FROM runtime_events WHERE runtime_session_id=? AND event_type='snapshot'",[$s['id']])['c']??0); if($sc>=120) return ['ok'=>true,'sessionId'=>$sid,'flags'=>self::flags($project),'snapshotLimit'=>true]; } DB::exec('INSERT INTO runtime_events(project_id,runtime_session_id,event_type,signal_type,page_url,message,stack_text,meta_json,occurred_at) VALUES(?,?,?,?,?,?,?,?,?)',[$project['id'],$s['id'],$event,$signal,$pageUrl,$message,$stack,json_encode($meta,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE),$now]);
        if($isErr) self::issue((int)$project['id'],$s,$message?:'Runtime error',$event);
        DB::exec('UPDATE projects SET runtime_status="connected",runtime_last_seen_at=CURRENT_TIMESTAMP WHERE id=?',[$project['id']]);
        ReadinessService::setStatus((int)$project['id'],'monitoring','ready','runtime','Runtime SDK heartbeat/event received.',1.0); if($isErr||$event==='heartbeat') ReadinessService::setStatus((int)$project['id'],'error_capture',$project['error_capture_enabled']?'ready':'not_applicable','runtime',$project['error_capture_enabled']?'Client error capture is enabled.':'Client error capture is disabled.',1.0); ReadinessService::recalc((int)$project['id']);
        return ['ok'=>true,'sessionId'=>$sid,'flags'=>self::flags($project)];
    }
    public static function connectedRecently(array $project,int $seconds=180): bool {
        if (($project['runtime_status'] ?? '') !== 'connected' || empty($project['runtime_last_seen_at'])) return false;
        $ts=strtotime((string)$project['runtime_last_seen_at']);
        return $ts!==false && $ts >= time()-max(30,$seconds);
    }
    public static function connectionState(array $project,int $seconds=180): array {
        if(self::connectedRecently($project,$seconds))return ['state'=>'live','label'=>'Live','last_seen_at'=>$project['runtime_last_seen_at']??null];
        if(($project['runtime_status']??'')==='connected'&&!empty($project['runtime_last_seen_at']))return ['state'=>'stale','label'=>'Connection stale','last_seen_at'=>$project['runtime_last_seen_at']];
        return ['state'=>'never','label'=>'Not connected','last_seen_at'=>null];
    }
    public static function flags(array $p): array { return ['trustBadge'=>(bool)$p['trust_badge_enabled'],'errorCapture'=>(bool)$p['error_capture_enabled'],'sessionReplay'=>(bool)$p['session_replay_enabled'],'paidAccess'=>(bool)$p['paid_access_enabled']]; }
    private static function redact(array &$a): void { foreach($a as $k=>&$v){if(preg_match('/password|token|cookie|authorization|credit|card|secret|request.?body|response.?body/i',(string)$k))$v='[redacted]'; elseif(is_array($v))self::redact($v); elseif(is_string($v))$v=self::safeText($v,1000);} }
    private static function snapshotMeta(array $meta): array {
        $html=(string)($meta['html']??'');
        $html=preg_replace('#<(script|style|noscript)\b[^>]*>.*?</\1>#is',' ',$html)??$html;
        $html=preg_replace('#</?(?:link|base|iframe|object|embed|video|audio|source|track|portal)\b[^>]*>#is',' ',$html)??$html;
        $html=preg_replace('/\s+on[a-z]+\s*=\s*(["\']).*?\1/is','',$html)??$html;
        $html=preg_replace('/\s+(?:href|src|srcset|action|poster|data|xlink:href)\s*=\s*(?:(["\']).*?\1|[^\s>]+)/is','',$html)??$html;
        $html=preg_replace_callback(
            '/\s+([A-Za-z_:][-A-Za-z0-9_:.]*?(?:value|password|token|secret|authorization|cookie|credit|card|nonce|srcdoc)[-A-Za-z0-9_:.]*)\s*=\s*(?:(["\']).*?\2|[^\s>]+)/is',
            static fn(array $m): string=>' '.substr((string)$m[1],0,100).'="[masked]"',
            $html
        )??$html;
        $html=preg_replace('/<input\b([^>]*?)\bvalue\s*=\s*(["\']).*?\2([^>]*)>/is','<input$1 value="[masked]"$3>',$html)??$html;
        $html=preg_replace('/<textarea\b([^>]*)>.*?<\/textarea>/is','<textarea$1>[masked]</textarea>',$html)??$html;
        $html=self::safeText($html,90000);
        $html=preg_replace('/\b(?:\+?\d[\d ()-]{7,}\d)\b/','[masked-number]',$html)??$html;
        return ['html'=>$html,'reason'=>self::safeText((string)($meta['reason']??'snapshot'),80),'title'=>self::safeText((string)($meta['title']??''),200)];
    }
    private static function safePageUrl(string $value): string {if($value==='')return '';$u=parse_url($value);if(!$u||empty($u['scheme'])||empty($u['host']))return substr(self::safeText(preg_split('/[?#]/',$value,2)[0]??'',1000),0,1000);$url=strtolower((string)$u['scheme']).'://'.$u['host'].(isset($u['port'])?':'.$u['port']:'').($u['path']??'/');return substr($url,0,1000);}
    private static function safeText(string $value,int $max): string {
        $value=preg_replace('/\bsk-[A-Za-z0-9_-]{16,}\b/','[redacted-key]',$value)??$value;
        $value=preg_replace('/\bAKIA[0-9A-Z]{16}\b/','[redacted-key]',$value)??$value;
        $value=preg_replace('/AIza[0-9A-Za-z\-_]{30,}/','[redacted-key]',$value)??$value;
        $value=preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/-]{12,}/i','Bearer [redacted]',$value)??$value;
        $value=preg_replace('/\beyJ[A-Za-z0-9_-]{12,}\.[A-Za-z0-9_-]{8,}(?:\.[A-Za-z0-9_-]{8,})?\b/','[redacted-token]',$value)??$value;
        $value=preg_replace('/((?:postgres(?:ql)?|mysql|mongodb(?:\+srv)?)\:\/\/[^:\s\/]+:)[^@\s\/]+@/i','$1[redacted]@',$value)??$value;
        $value=preg_replace('/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----.*?-----END (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/is','[redacted-private-key]',$value)??$value;
        $value=preg_replace('/([?&](?:token|key|code|secret|password|pass|auth|session|signature)=)[^\s&#]+/i','$1[redacted]',$value)??$value;
        $value=preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i','[redacted-email]',$value)??$value;
        return substr($value,0,$max);
    }
    private static function issue(int $pid,array $s,string $message,string $type): void {
        $title=trim(preg_replace('/\s+/',' ',substr($message,0,255)));$fp=hash('sha256',$type.'|'.$title);
        $i=DB::one('SELECT * FROM error_issues WHERE project_id=? AND fingerprint=?',[$pid,$fp]);
        if(!$i){$id=(int)DB::insert('INSERT INTO error_issues(project_id,fingerprint,title,error_type,first_seen_at,last_seen_at,occurrences,affected_sessions) VALUES(?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,1,1)',[$pid,$fp,$title,$type]);DB::exec('INSERT INTO error_issue_sessions(issue_id,runtime_session_id,first_seen_at) VALUES(?,?,CURRENT_TIMESTAMP)',[$id,$s['id']]);$p=DB::one('SELECT owner_user_id,slug FROM projects WHERE id=?',[$pid]);if($p)NotificationService::record((int)$p['owner_user_id'],$pid,'runtime_error','New client error',$title,'/builds/'.$p['slug'].'/dashboard/errors',true);return;}
        $seen=DB::one('SELECT issue_id FROM error_issue_sessions WHERE issue_id=? AND runtime_session_id=?',[$i['id'],$s['id']]);
        $wasResolved=!empty($i['resolved_at']);
        if(!$seen){
            DB::exec('INSERT INTO error_issue_sessions(issue_id,runtime_session_id,first_seen_at) VALUES(?,?,CURRENT_TIMESTAMP)',[$i['id'],$s['id']]);
            DB::exec('UPDATE error_issues SET last_seen_at=CURRENT_TIMESTAMP,occurrences=occurrences+1,affected_sessions=affected_sessions+1,resolved_at=NULL WHERE id=?',[$i['id']]);
        } else {
            DB::exec('UPDATE error_issues SET last_seen_at=CURRENT_TIMESTAMP,occurrences=occurrences+1,resolved_at=NULL WHERE id=?',[$i['id']]);
        }
        if($wasResolved){
            $p=DB::one('SELECT owner_user_id,slug FROM projects WHERE id=?',[$pid]);
            if($p) NotificationService::record((int)$p['owner_user_id'],$pid,'runtime_error','Client error returned',$title,'/builds/'.$p['slug'].'/dashboard/errors',true);
        }
    }
}
