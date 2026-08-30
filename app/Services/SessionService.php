<?php
namespace App\Services;
use App\Core\DB;

final class SessionService {
    public static function filters(array $q): array {
        $preset = in_array(($q['range'] ?? '7d'), ['1h','12h','24h','7d','custom'], true) ? ($q['range'] ?? '7d') : '7d';
        $now = new \DateTimeImmutable('now');
        $from = match($preset) {
            '1h' => $now->modify('-1 hour'),
            '12h' => $now->modify('-12 hours'),
            '24h' => $now->modify('-24 hours'),
            'custom' => self::customDate($q['from_date'] ?? null, $q['from_time'] ?? null, $now->modify('-7 days')),
            default => $now->modify('-7 days'),
        };
        $to = $preset === 'custom' ? self::customDate($q['to_date'] ?? null, $q['to_time'] ?? null, $now) : $now;
        if ($to <= $from) $to = $from->modify('+1 hour');
        $perRaw = (int)($q['per_page'] ?? 20);
        $per = in_array($perRaw, [10,20,50,100], true) ? $perRaw : 20;
        $page = max(1, (int)($q['page'] ?? 1));
        $arr = static fn($v): array => array_values(array_filter(array_map('strval', is_array($v) ? $v : ($v === null || $v === '' ? [] : [$v]))));
        return [
            'range'=>$preset,'from'=>$from,'to'=>$to,'q'=>trim((string)($q['q'] ?? '')),
            'os'=>$arr($q['os'] ?? []),'browser'=>$arr($q['browser'] ?? []),'country'=>$arr($q['country'] ?? []),
            'identity'=>in_array(($q['identity'] ?? ''),['identified','anonymous'],true)?$q['identity']:'',
            'signals'=>$arr($q['signals'] ?? []),'errors'=>!empty($q['errors']),'user'=>trim((string)($q['user'] ?? '')),
            'min_duration'=>max(0,(int)($q['min_duration'] ?? 0)),
            'max_duration'=>max(0,(int)($q['max_duration'] ?? 0)),
            'per_page'=>$per,'page'=>$page,
        ];
    }
    private static function customDate(?string $date, ?string $time, \DateTimeImmutable $fallback): \DateTimeImmutable {
        if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) return $fallback;
        $time = ($time && preg_match('/^\d{2}:\d{2}$/',$time)) ? $time : '00:00';
        $d = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $date.' '.$time);
        return $d ?: $fallback;
    }
    public static function query(int $projectId, array $f): array {
        $where=['rs.project_id=?','rs.started_at>=?','rs.started_at<=?'];
        $params=[$projectId,$f['from']->format('Y-m-d H:i:s'),$f['to']->format('Y-m-d H:i:s')];
        if($f['q']!==''){$where[]='(rs.identity_email LIKE ? OR rs.identity_id LIKE ? OR rs.session_key LIKE ? OR rs.device LIKE ? OR rs.browser LIKE ? OR rs.os LIKE ?)';$v='%'.$f['q'].'%';array_push($params,$v,$v,$v,$v,$v,$v);}
        self::in($where,$params,'rs.os',$f['os']);self::in($where,$params,'rs.browser',$f['browser']);self::in($where,$params,'rs.country_code',$f['country']);
        if($f['identity']==='identified')$where[]='(rs.identity_id IS NOT NULL OR rs.identity_email IS NOT NULL)';
        if($f['identity']==='anonymous')$where[]='rs.identity_id IS NULL AND rs.identity_email IS NULL';
        if($f['errors'])$where[]='rs.error_count>0';
        if($f['user']!==''){$where[]='(rs.identity_id=? OR rs.identity_email=? OR rs.anonymous_id=? OR rs.session_key=?)';array_push($params,$f['user'],$f['user'],$f['user'],$f['user']);}
        if($f['min_duration']>0){$where[]='rs.duration_seconds>=?';$params[]=$f['min_duration'];}
        if($f['max_duration']>0){$where[]='rs.duration_seconds<=?';$params[]=$f['max_duration'];}
        if($f['signals']){ $ph=implode(',',array_fill(0,count($f['signals']),'?'));$where[]="EXISTS (SELECT 1 FROM runtime_events sx WHERE sx.runtime_session_id=rs.id AND sx.event_type='signal' AND sx.signal_type IN ($ph))";array_push($params,...$f['signals']); }
        $sqlWhere=implode(' AND ',$where);
        $count=(int)(DB::one('SELECT COUNT(*) c FROM runtime_sessions rs WHERE '.$sqlWhere,$params)['c']??0);
        $pages=max(1,(int)ceil($count/$f['per_page']));$page=min($f['page'],$pages);$offset=($page-1)*$f['per_page'];
        $rows=DB::all("SELECT rs.*,(SELECT COUNT(*) FROM runtime_events pe WHERE pe.runtime_session_id=rs.id AND pe.event_type='pageview') page_count FROM runtime_sessions rs WHERE ".$sqlWhere.' ORDER BY rs.started_at DESC LIMIT '.(int)$f['per_page'].' OFFSET '.(int)$offset,$params);
        foreach($rows as &$r){$r['activity']=self::activity((int)$r['id'],(int)$r['duration_seconds']);$r['signal_types']=self::signalTypes((int)$r['id']);}
        return ['rows'=>$rows,'count'=>$count,'page'=>$page,'pages'=>$pages,'per_page'=>$f['per_page']];
    }
    private static function in(array &$where,array &$params,string $col,array $vals): void { if(!$vals)return;$ph=implode(',',array_fill(0,count($vals),'?'));$where[]="$col IN ($ph)";array_push($params,...$vals); }
    public static function options(int $projectId, \DateTimeImmutable $from): array {
        $since=$from->format('Y-m-d H:i:s');
        $pluck=function(string $col)use($projectId,$since){return array_values(array_filter(array_column(DB::all("SELECT DISTINCT $col v FROM runtime_sessions WHERE project_id=? AND started_at>=? AND $col IS NOT NULL AND $col<>'' ORDER BY $col",[$projectId,$since]),'v')));};
        return ['os'=>$pluck('os'),'browser'=>$pluck('browser'),'country'=>$pluck('country_code')];
    }
    public static function users(int $projectId, \DateTimeImmutable $from): array {
        return DB::all("SELECT COALESCE(NULLIF(identity_email,''),NULLIF(identity_id,''),NULLIF(anonymous_id,''),session_key) user_key, MAX(identity_email) email, MAX(identity_name) name, COUNT(*) sessions FROM runtime_sessions WHERE project_id=? AND started_at>=? GROUP BY user_key ORDER BY sessions DESC LIMIT 30",[$projectId,$from->format('Y-m-d H:i:s')]);
    }
    private static function activity(int $sessionId,int $duration): array {
        $events=DB::all('SELECT event_type,signal_type,occurred_at FROM runtime_events WHERE runtime_session_id=? ORDER BY occurred_at ASC',[$sessionId]);
        if(!$events)return array_fill(0,18,0);
        $start=strtotime($events[0]['occurred_at']);$span=max(1,$duration ?: strtotime(end($events)['occurred_at'])-$start);$bins=array_fill(0,18,0);
        foreach($events as $e){$i=min(17,max(0,(int)floor(((strtotime($e['occurred_at'])-$start)/$span)*17)));$important=in_array($e['event_type'],['pageview','error','network_error'],true)||($e['event_type']==='signal'&&($e['signal_type']??'')!=='performance_sample');$weight=$important?3:1;$bins[$i]=min(8,$bins[$i]+$weight);}
        return $bins;
    }
    private static function signalTypes(int $sessionId): array { return array_column(DB::all("SELECT DISTINCT signal_type v FROM runtime_events WHERE runtime_session_id=? AND event_type='signal' AND signal_type IS NOT NULL AND signal_type<>'performance_sample' ORDER BY signal_type",[$sessionId]),'v'); }
    public static function snapshots(int $sessionId): array {
        $rows=DB::all("SELECT id,occurred_at,page_url,meta_json FROM runtime_events WHERE runtime_session_id=? AND event_type='snapshot' ORDER BY occurred_at ASC",[$sessionId]);
        foreach($rows as &$r){$m=json_decode((string)$r['meta_json'],true)?:[];$r['html']=(string)($m['html']??'');unset($r['meta_json']);}
        return $rows;
    }
    public static function flag(string $code): string {
        $code=strtoupper($code); if(strlen($code)!==2)return '🌐'; return html_entity_decode('&#'.(127397+ord($code[0])).';&#'.(127397+ord($code[1])).';', ENT_NOQUOTES, 'UTF-8');
    }
}
