<?php
namespace App\Services;

use App\Core\{DB,Env};

/**
 * Browser-driven progressive readiness scan.
 * Each HTTP request completes one bounded phase, commits only evidence that phase
 * actually proved, and returns a fresh snapshot so the Readiness UI can update live.
 */
final class ProgressiveScanService {
    public const ENGINE='5.5.0-progressive-evidence';
    private const PHASES=['url','pages','runtime','source','ai','finalize'];

    public static function initialMeta(array $project): array {
        return [
            'engine'=>self::ENGINE,
            'phase'=>'url',
            'phase_index'=>0,
            'progress'=>1,
            'message'=>'Preparing the evidence scan…',
            'coverage'=>[
                'url'=>['state'=>'queued','label'=>'URL'],
                'pages'=>['state'=>'queued','label'=>'Pages'],
                'source'=>['state'=>'queued','label'=>'Source'],
                'runtime'=>['state'=>'queued','label'=>'Runtime'],
                'ai'=>['state'=>'queued','label'=>'AI'],
            ],
            'events'=>[],
            'warnings'=>[],
            'before_summary'=>ReadinessService::summary((int)$project['id']),
            'started_at'=>date(DATE_ATOM),
        ];
    }

    public static function step(array $project,array $scan): array {
        $scanId=(int)$scan['id'];$pid=(int)$project['id'];
        $meta=self::decodeMeta($scan['meta_json']??null);
        if(($scan['status']??'')==='completed' || ($meta['phase']??'')==='done') return self::snapshot($project,$scanId,$meta,'completed');
        if(($scan['status']??'')!=='running') throw new \RuntimeException('This scan is no longer running.');

        $phase=(string)($meta['phase']??'url');
        if(!in_array($phase,self::PHASES,true))$phase='finalize';
        $meta['message']=self::phaseMessage($phase);
        self::saveMeta($scanId,$meta);

        try{
            switch($phase){
                case 'url': self::phaseUrl($project,$scanId,$meta); break;
                case 'pages': self::phasePages($project,$scanId,$meta); break;
                case 'runtime': self::phaseRuntime($project,$scanId,$meta); break;
                case 'source': self::phaseSource($project,$scanId,$meta); break;
                case 'ai': self::phaseAi($project,$scanId,$meta); break;
                default: self::phaseFinalize($project,$scanId,$meta); break;
            }
        }catch(\Throwable $e){
            self::cleanupTemp($scanId);
            $meta['phase']='failed';$meta['message']='Scan stopped safely.';
            $meta['warnings'][]='The '.$phase.' phase failed: '.self::safeMessage($e->getMessage());
            $meta['failed_phase']=$phase;$meta['failed_at']=date(DATE_ATOM);
            self::saveMeta($scanId,$meta);
            DB::exec('UPDATE scans SET status="failed",completed_at=CURRENT_TIMESTAMP,meta_json=? WHERE id=?',[self::json($meta),$scanId]);
            throw $e;
        }

        $row=DB::one('SELECT * FROM scans WHERE id=? AND project_id=?',[$scanId,$pid])?:['id'=>$scanId,'status'=>'running'];
        $meta=self::decodeMeta($row['meta_json']??self::json($meta));
        return self::snapshot($project,$scanId,$meta,(string)($row['status']??'running'));
    }

    public static function snapshot(array $project,int $scanId,?array $meta=null,?string $status=null): array {
        $pid=(int)$project['id'];
        $scan=DB::one('SELECT * FROM scans WHERE id=? AND project_id=?',[$scanId,$pid]);
        if(!$scan) throw new \RuntimeException('Scan not found.');
        $meta=$meta??self::decodeMeta($scan['meta_json']??null);$status=$status??(string)$scan['status'];
        $summary=ReadinessService::summary($pid);
        $started=(string)($scan['started_at']??date('Y-m-d H:i:s'));
        $total=(int)(DB::one('SELECT COUNT(*) c FROM checks WHERE project_id=? AND status<>"not_applicable"',[$pid])['c']??0);

        // Count checks that were actually touched during this scan. This uses
        // last_checked_at in addition to fresh evidence so evidence deduplication does
        // not make a repeated, valid re-check appear as if nothing was inspected.
        $isPristine=(int)($meta['phase_index']??0)===0 && empty($meta['events']);
        $reviewedRows=$isPristine?[]:DB::all('SELECT id,check_key,status,severity,title,description FROM checks WHERE project_id=? AND status<>"not_applicable" AND last_checked_at>=? ORDER BY last_checked_at,id',[$pid,$started]);
        $checked=[];$reviewedByKey=[];
        foreach($reviewedRows as $r){$checked[(int)$r['id']]=(string)$r['status'];$reviewedByKey[(string)$r['check_key']]=$r;}

        $freshRows=$isPristine?[]:DB::all('SELECT c.id,c.check_key,c.status,c.severity,c.title,c.description,e.source,e.summary evidence_summary,e.confidence,e.created_at FROM evidence e JOIN checks c ON c.id=e.check_id WHERE e.project_id=? AND e.created_at>=? ORDER BY e.id DESC LIMIT 80',[$pid,$started]);
        $seen=[];$changes=[];
        foreach($freshRows as $r){
            $cid=(int)$r['id'];$checked[$cid]=(string)$r['status'];
            $key=(string)$r['check_key'];if(isset($seen[$key]))continue;$seen[$key]=true;
            $changes[]=[
                'key'=>$key,'status'=>$r['status'],'severity'=>$r['severity'],
                'title'=>ReadinessService::presentTitle($r),'description'=>ReadinessService::presentDescription($r),
                'source'=>$r['source'],'evidence'=>$r['evidence_summary'],'confidence'=>(float)$r['confidence'],
            ];
            if(count($changes)>=12)break;
        }
        // When a repeated scan produced exactly the same evidence, the evidence row is
        // intentionally deduplicated. Still surface that check as a live re-check result.
        foreach($reviewedByKey as $key=>$r){
            if(isset($seen[$key])||count($changes)>=12)continue;
            $changes[]=[
                'key'=>$key,'status'=>$r['status'],'severity'=>$r['severity'],
                'title'=>ReadinessService::presentTitle($r),'description'=>ReadinessService::presentDescription($r),
                'source'=>'recheck','evidence'=>'Re-checked; the existing evidence is unchanged.','confidence'=>1.0,
            ];
        }

        $freshReady=0;$freshAttention=0;
        foreach($checked as $st){if($st==='ready')$freshReady++;elseif($st==='need_attention')$freshAttention++;}
        $checkedCount=count($checked);
        $livePending=max(0,$total-$freshReady-$freshAttention);
        $liveScore=$total>0?(int)round($freshReady/$total*100):0;
        $before=$meta['before_summary']??[];$delta=(int)$summary['score']-(int)($before['score']??$summary['score']);
        $scoreReason=[
            'ready_delta'=>(int)$summary['ready']-(int)($before['ready']??$summary['ready']),
            'attention_delta'=>(int)$summary['need_attention']-(int)($before['need_attention']??$summary['need_attention']),
            'pending_delta'=>(int)$summary['pending_total']-(int)($before['pending_total']??$summary['pending_total']),
            'applicable_delta'=>(int)$summary['applicable']-(int)($before['applicable']??$summary['applicable']),
        ];
        $liveSummary=$status==='completed'?$summary:[
            'score'=>$liveScore,'ready'=>$freshReady,'need_attention'=>$freshAttention,
            'pending_total'=>$livePending,'applicable'=>$total,
        ];
        return [
            'ok'=>true,'scanId'=>$scanId,'status'=>$status,'phase'=>$meta['phase']??'url','progress'=>(int)($meta['progress']??1),
            'message'=>$meta['message']??'Scanning…','coverage'=>$meta['coverage']??[],'warnings'=>array_values(array_unique($meta['warnings']??[])),
            'events'=>array_slice($meta['events']??[],-8),'summary'=>$summary,'liveSummary'=>$liveSummary,'changes'=>$changes,
            'scanProgress'=>[
                'checked'=>$checkedCount,'total'=>$total,'ready'=>$freshReady,'attention'=>$freshAttention,
                'percent'=>$total>0?(int)round($checkedCount/$total*100):0,
            ],
            'scoreDelta'=>$delta,'scoreReason'=>$scoreReason,
        ];
    }

    public static function recoverStale(int $projectId,int $olderThanSeconds=600): void {
        $cut=date('Y-m-d H:i:s',time()-max(180,$olderThanSeconds));
        $rows=DB::all('SELECT id,meta_json FROM scans WHERE project_id=? AND type="deep" AND status="running" AND started_at<?',[$projectId,$cut]);
        foreach($rows as $row){
            $meta=self::decodeMeta($row['meta_json']??null);$meta['phase']='failed';$meta['message']='Previous scan was interrupted.';$meta['warnings'][]='The previous scan stopped before completion and was marked interrupted.';$meta['failed_at']=date(DATE_ATOM);
            DB::exec('UPDATE scans SET status="failed",completed_at=CURRENT_TIMESTAMP,meta_json=? WHERE id=?',[self::json($meta),(int)$row['id']]);
            self::cleanupTemp((int)$row['id']);
        }
    }

    private static function phaseUrl(array $project,int $scanId,array &$meta): void {
        $pid=(int)$project['id'];$meta['coverage']['url']['state']='running';self::saveMeta($scanId,$meta);
        try{
            $url=UrlScanner::scan($pid,(string)$project['url']);self::mergeTemp($scanId,['url'=>$url]);
            $blocked=!empty($url['meta']['blocked']);
            $meta['coverage']['url']=['state'=>$blocked?'blocked':'complete','label'=>'URL','detail'=>$blocked?'Blocked by WAF/CDN challenge':'Public URL checked'];
            if($blocked)$meta['warnings'][]='The public URL was blocked/challenged by the target WAF; blocked checks remain unscored.';
            self::event($meta,$blocked?'warning':'complete',$blocked?'URL scan was blocked by the target WAF.':'Public URL evidence checked.','url');
        }catch(\Throwable $e){
            $meta['coverage']['url']=['state'=>'failed','label'=>'URL','detail'=>'Probe failed'];$meta['warnings'][]='Fresh URL probe could not be completed.';self::mergeTemp($scanId,['url'=>[]]);
            ReadinessService::addEvidence($pid,'reachability','url','The fresh URL probe could not be completed; previous readiness evidence was preserved.',0.0,['error_class'=>get_class($e)]);
            self::event($meta,'warning','Fresh URL probe could not be completed.','url');
        }
        self::advance($scanId,$meta,'pages',18,'URL evidence collected. Scanning public pages…');
    }

    private static function phasePages(array $project,int $scanId,array &$meta): void {
        $tmp=self::readTemp($scanId);$url=is_array($tmp['url']??null)?$tmp['url']:[];$blocked=!empty($url['meta']['blocked']);
        $meta['coverage']['pages']['state']='running';self::saveMeta($scanId,$meta);
        if($blocked || !$url || (int)($url['status']??0)<200 || (int)($url['status']??0)>=400){
            $surface=['pages'=>[],'pages_scanned'=>0,'read_only'=>true,'status'=>'skipped'];
            $meta['coverage']['pages']=['state'=>'skipped','label'=>'Pages','detail'=>$blocked?'Skipped because URL was WAF-blocked':'No reachable public page'];
            self::event($meta,'pending','Public-page crawl skipped because a safe reachable page was not available.','pages');
        }else{
            try{
                $surface=PublicSurfaceService::crawl((string)($url['effective_url']??$project['url']),8,160000);
                $n=(int)($surface['pages_scanned']??0);
                $meta['coverage']['pages']=['state'=>$n>0?'complete':'none','label'=>'Pages','detail'=>$n.' public page'.($n===1?'':'s').' checked'];
                self::event($meta,$n>0?'complete':'pending',$n>0?$n.' public pages inspected safely.':'No additional public pages could be inspected.','pages');
            }catch(\Throwable $e){
                $surface=['pages'=>[],'pages_scanned'=>0,'read_only'=>true,'status'=>'failed'];$meta['coverage']['pages']=['state'=>'failed','label'=>'Pages','detail'=>'Crawl failed'];$meta['warnings'][]='The read-only public-page crawl could not be completed.';self::event($meta,'warning','Public-page crawl could not be completed.','pages');
            }
        }
        self::mergeTemp($scanId,['public_surface'=>$surface]);
        self::advance($scanId,$meta,'runtime',34,'Public surface checked. Refreshing runtime evidence…');
    }

    private static function phaseRuntime(array $project,int $scanId,array &$meta): void {
        $meta['coverage']['runtime']['state']='running';self::saveMeta($scanId,$meta);
        $project=DB::one('SELECT * FROM projects WHERE id=?',[(int)$project['id']])?:$project;
        try{
            $r=RuntimeEvidenceService::refresh($project);
            if(!empty($r['fresh_heartbeat'])){$state='connected';$detail='Fresh SDK heartbeat received';}
            elseif((int)($r['runtime_events_30d']??0)>0 || (int)($r['uptime_samples_30d']??0)>0){$state='stale';$detail='Runtime evidence exists but is not fresh';}
            else{$state='disconnected';$detail='No live SDK heartbeat';}
            $meta['coverage']['runtime']=['state'=>$state,'label'=>'Runtime','detail'=>$detail];
            self::event($meta,$state==='connected'?'complete':'pending',$detail.'.','runtime');
        }catch(\Throwable $e){
            $meta['coverage']['runtime']=['state'=>'failed','label'=>'Runtime','detail'=>'Runtime refresh failed'];$meta['warnings'][]='Stored runtime evidence could not be refreshed.';self::event($meta,'warning','Runtime evidence refresh failed safely.','runtime');
        }
        self::advance($scanId,$meta,'source',50,'Runtime evidence refreshed. Checking source evidence…');
    }

    private static function phaseSource(array $project,int $scanId,array &$meta): void {
        $pid=(int)$project['id'];$meta['coverage']['source']['state']='running';self::saveMeta($scanId,$meta);
        ZipScanner::recoverStale($pid);
        $gc=DB::one('SELECT * FROM github_connections WHERE project_id=?',[$pid]);
        $latest=DB::one('SELECT id,source_type,status,original_name,total_files,total_bytes,completed_at FROM source_scans WHERE project_id=? AND status="completed" ORDER BY id DESC LIMIT 1',[$pid]);
        if($gc && !empty($gc['repo_full_name']) && GitHubService::configured(false) && !empty($gc['installation_id'])){
            $dir=dirname(__DIR__,2).'/storage/temp';if(!is_dir($dir))@mkdir($dir,0775,true);$tmp=$dir.'/progressive-gh-'.bin2hex(random_bytes(7)).'.zip';
            try{
                GitHubService::downloadZip((int)$gc['installation_id'],(string)$gc['repo_full_name'],$tmp);
                $r=ZipScanner::scan($pid,$tmp,(string)$gc['repo_full_name'].'.zip','github');
                $meta['coverage']['source']=['state'=>'fresh','label'=>'Source','detail'=>'GitHub source re-scanned'];
                self::event($meta,'complete','Fresh GitHub source evidence checked: '.(int)($r['findings']??0).' finding(s).','source');
            }catch(\Throwable $e){
                $meta['coverage']['source']=['state'=>$latest?'stored':'failed','label'=>'Source','detail'=>$latest?'Fresh GitHub refresh failed; stored evidence preserved':'GitHub refresh failed'];
                $meta['warnings'][]='Connected GitHub source could not be refreshed; previously completed source evidence was preserved.';self::event($meta,'warning','GitHub source refresh failed safely.','source');
            }finally{@unlink($tmp);}
        }elseif($latest){
            $detail=(($latest['source_type']??'')==='zip')?'Completed ZIP evidence is stored; upload again for a fresh code pass.':'Completed source evidence is available.';
            $meta['coverage']['source']=['state'=>'stored','label'=>'Source','detail'=>$detail];self::event($meta,'pending',$detail,'source');
        }else{
            $meta['coverage']['source']=['state'=>'none','label'=>'Source','detail'=>'No source connected'];self::event($meta,'pending','No source package or GitHub repository is connected yet.','source');
        }
        self::advance($scanId,$meta,'ai',72,'Source evidence checked. AI is adjudicating proven evidence…');
    }

    private static function phaseAi(array $project,int $scanId,array &$meta): void {
        $meta['coverage']['ai']['state']='running';self::saveMeta($scanId,$meta);
        $tmp=self::readTemp($scanId);$url=is_array($tmp['url']??null)?$tmp['url']:[];$surface=is_array($tmp['public_surface']??null)?$tmp['public_surface']:[];
        if(!AiScanService::enabled()){
            $meta['coverage']['ai']=['state'=>'disabled','label'=>'AI','detail'=>'OPENAI_API_KEY is not configured'];$meta['warnings'][]='AI evidence review is disabled because OPENAI_API_KEY is not configured.';self::event($meta,'pending','AI adjudication skipped; deterministic evidence remains valid.','ai');
        }else{
            try{
                $r=AiScanService::reviewProject((int)$project['id'],$url,$surface);$meta['coverage']['ai']=['state'=>($r['status']??'')==='completed'?'complete':'failed','label'=>'AI','detail'=>($r['status']??'')==='completed'?'Evidence adjudication complete':'AI did not complete'];self::event($meta,($r['status']??'')==='completed'?'complete':'warning',(string)($r['summary']??'AI evidence review finished.'),'ai');
            }catch(\Throwable $e){
                $meta['coverage']['ai']=['state'=>'failed','label'=>'AI','detail'=>'AI review failed safely'];$meta['warnings'][]='AI evidence review failed safely; deterministic URL/source/runtime evidence is still valid.';self::event($meta,'warning','AI review failed safely; deterministic evidence was preserved.','ai');
            }
        }
        self::advance($scanId,$meta,'finalize',92,'AI evidence pass finished. Finalizing readiness…');
    }

    private static function phaseFinalize(array $project,int $scanId,array &$meta): void {
        $pid=(int)$project['id'];$score=ReadinessService::recalc($pid);$summary=ReadinessService::summary($pid);
        $meta['phase']='done';$meta['progress']=100;$meta['message']=self::limitedCoverage($meta)?'Scan complete with limited coverage.':'Scan complete.';$meta['after_summary']=$summary;$meta['score_delta']=(int)$summary['score']-(int)($meta['before_summary']['score']??$summary['score']);$meta['completed_at']=date(DATE_ATOM);
        self::event($meta,'complete',$meta['message'],'finalize');
        DB::exec('UPDATE scans SET status="completed",score=?,completed_at=CURRENT_TIMESTAMP,meta_json=? WHERE id=?',[$score,self::json($meta),$scanId]);
        self::cleanupTemp($scanId);
    }

    private static function limitedCoverage(array $meta): bool {
        foreach(['url','pages','source','runtime','ai'] as $k){$s=(string)($meta['coverage'][$k]['state']??'none');if(in_array($s,['blocked','failed','skipped','none','stored','stale','disconnected','disabled','queued'],true))return true;}return false;
    }

    private static function advance(int $scanId,array &$meta,string $next,int $progress,string $message): void {
        $meta['phase']=$next;$meta['phase_index']=array_search($next,self::PHASES,true);$meta['progress']=$progress;$meta['message']=$message;self::saveMeta($scanId,$meta);
    }
    private static function phaseMessage(string $phase): string {return match($phase){'url'=>'Checking public URL evidence…','pages'=>'Inspecting public pages…','runtime'=>'Refreshing live runtime evidence…','source'=>'Checking source evidence…','ai'=>'AI is adjudicating proven evidence…',default=>'Finalizing readiness…'};}
    private static function event(array &$meta,string $state,string $text,string $phase): void {$meta['events'][]=['state'=>$state,'phase'=>$phase,'text'=>$text,'at'=>date(DATE_ATOM)];if(count($meta['events'])>24)$meta['events']=array_slice($meta['events'],-24);}
    private static function saveMeta(int $scanId,array $meta): void {DB::exec('UPDATE scans SET meta_json=? WHERE id=?',[self::json($meta),$scanId]);}
    private static function decodeMeta($raw): array {if(is_array($raw))return $raw;$j=json_decode((string)$raw,true);return is_array($j)?$j:[];}
    private static function json(array $a): string {return json_encode($a,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);}
    private static function tempPath(int $scanId): string {$dir=dirname(__DIR__,2).'/storage/temp';if(!is_dir($dir))@mkdir($dir,0775,true);return $dir.'/progressive-scan-'.$scanId.'.json';}
    private static function readTemp(int $scanId): array {$path=self::tempPath($scanId);if(!is_file($path))return [];$j=json_decode((string)@file_get_contents($path),true);return is_array($j)?$j:[];}
    private static function mergeTemp(int $scanId,array $data): void {$all=array_merge(self::readTemp($scanId),$data);@file_put_contents(self::tempPath($scanId),self::json($all),LOCK_EX);}
    private static function cleanupTemp(int $scanId): void {@unlink(self::tempPath($scanId));}
    private static function safeMessage(string $m): string {$m=preg_replace('/[\r\n]+/',' ',trim($m));return substr((string)$m,0,220);}
}
