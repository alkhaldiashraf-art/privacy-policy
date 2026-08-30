<?php
namespace App\Services;

use App\Core\DB;

/** A focused, read-only website scan for builders who only have a live URL. */
final class WebsiteScanService {
    public const ENGINE='5.5.0-website-evidence';

    public static function run(array $project,int $tokenCost=0): array {
        $pid=(int)$project['id'];ReadinessService::seedChecks($pid);
        $scanId=(int)DB::insert('INSERT INTO scans(project_id,type,status,tokens_cost,started_at,meta_json) VALUES(? ,"url","running",?,CURRENT_TIMESTAMP,?)',[$pid,max(0,$tokenCost),json_encode(['engine'=>self::ENGINE,'phase'=>'url'])]);
        try{
            $url=UrlScanner::scan($pid,(string)$project['url']);
            $blocked=!empty($url['meta']['blocked']);$surface=['pages'=>[],'pages_scanned'=>0,'failures'=>[],'read_only'=>true];
            if(!$blocked && (int)($url['status']??0)>=200 && (int)($url['status']??0)<400){
                try{
                    $surface=PublicSurfaceService::crawl((string)($url['effective_url']??$project['url']),12,220000);
                }catch(\Throwable $e){
                    // A secondary crawl failure must not discard the useful main-URL
                    // evidence that already completed successfully.
                    $surface=[
                        'pages'=>[],'pages_scanned'=>0,'links_checked'=>0,'read_only'=>true,
                        'failures'=>[['url'=>$url['effective_url']??$project['url'],'status'=>0,'error'=>'The extended public-page crawl could not be completed.']],
                    ];
                }
            }
            $derived=self::derivedChecks($pid,$url,$surface,$blocked);
            $all=array_merge($url['checks']??[],$derived);
            $ai=['status'=>'disabled','summary'=>'AI evidence review is not configured.'];
            if(AiScanService::enabled()){
                try{$ai=AiScanService::reviewProject($pid,$url,$surface);}catch(\Throwable $e){$ai=['status'=>'failed','summary'=>'AI review failed safely; deterministic website evidence was preserved.'];}
            }
            $aiFindings=[];
            if(($ai['status']??'')==='completed'){
                $aiFindings=DB::all('SELECT c.check_key,c.severity,c.title,c.description,e.summary,e.confidence,e.created_at FROM checks c JOIN evidence e ON e.id=(SELECT MAX(e2.id) FROM evidence e2 WHERE e2.check_id=c.id AND e2.source="ai") WHERE c.project_id=? AND c.status="need_attention" AND e.id IS NOT NULL ORDER BY CASE c.severity WHEN "critical" THEN 1 WHEN "high" THEN 2 WHEN "medium" THEN 3 ELSE 4 END,c.id LIMIT 30',[$pid]);
            }
            $summary=ReadinessService::summary($pid);
            $report=[
                'engine'=>self::ENGINE,'scan_id'=>$scanId,'url'=>$project['url'],'effective_url'=>$url['effective_url']??$project['url'],
                'status'=>$url['status']??0,'response_ms'=>$url['response_ms']??null,'blocked'=>$blocked,
                'detected'=>$url['detected']??[],'pages'=>self::compactPages($surface['pages']??[]),
                'pages_scanned'=>(int)($surface['pages_scanned']??0),'links_checked'=>(int)($surface['links_checked']??0),
                'failures'=>array_slice($surface['failures']??[],0,30),'checks'=>$all,'ai'=>$ai,'ai_findings'=>$aiFindings,'summary'=>$summary,
                'coverage'=>[
                    'public_url'=>$blocked?'blocked':'complete','public_pages'=>(int)($surface['pages_scanned']??0)>0?'complete':'limited',
                    'authenticated_pages'=>'not_scanned','server_source'=>'requires_zip','live_runtime'=>'requires_sdk',
                ],
                'completed_at'=>date(DATE_ATOM),
            ];
            DB::exec('UPDATE scans SET status="completed",score=?,completed_at=CURRENT_TIMESTAMP,meta_json=? WHERE id=?',[(int)$summary['score'],self::json($report),$scanId]);
            return $report;
        }catch(\Throwable $e){
            DB::exec('UPDATE scans SET status="failed",completed_at=CURRENT_TIMESTAMP,meta_json=? WHERE id=?',[self::json(['engine'=>self::ENGINE,'error'=>'Website scan stopped safely.','error_class'=>get_class($e)]),$scanId]);
            throw $e;
        }
    }

    public static function latest(int $projectId): ?array {
        $row=DB::one('SELECT * FROM scans WHERE project_id=? AND type="url" AND status="completed" ORDER BY id DESC LIMIT 1',[$projectId]);
        if(!$row)return null;$report=json_decode((string)($row['meta_json']??''),true);if(!is_array($report))return null;$report['_scan']=['id'=>(int)$row['id'],'status'=>$row['status'],'score'=>$row['score'],'completed_at'=>$row['completed_at'],'created_at'=>$row['created_at']];return $report;
    }

    private static function derivedChecks(int $pid,array $url,array $surface,bool $blocked): array {
        $checks=[];$add=static function(string $key,string $label,string $state,string $message,string $area,string $severity,float $confidence=1.0,array $meta=[])use(&$checks):void{$checks[]=compact('key','label','state','message','area','severity','confidence','meta');};
        if($blocked){
            foreach([
                ['performance','Server response time','unknown','The target WAF blocked reliable performance evidence.','Performance','medium'],
                ['seo_foundation','Search-engine foundation','unknown','Public pages could not be inspected reliably.','SEO','medium'],
                ['broken_links','Broken internal links','unknown','Internal links could not be crawled safely.','Reliability','high'],
                ['accessibility','Basic accessibility','unknown','Public markup could not be inspected reliably.','Accessibility','medium'],
            ] as $x)$add($x[0],$x[1],$x[2],$x[3],$x[4],$x[5],0.0);
            return $checks;
        }

        $pages=$surface['pages']??[];$pageCount=count($pages);$times=array_values(array_filter(array_map(static fn($p)=>(int)($p['response_ms']??0),$pages),static fn($v)=>$v>0));
        $avg=$times?(int)round(array_sum($times)/count($times)):(int)($url['response_ms']??0);
        $perfState=$avg>3000?'attention':($avg>0?'verified':'unknown');
        $perfMessage=$avg>0?'Average inspected-page server response was '.$avg.' ms across '.max(1,count($times)).' response(s).':'No reliable response-time sample was available.';
        $add('performance','Server response time',$perfState,$perfMessage,'Performance',$avg>3000?'high':'medium',$avg>0?0.9:0.2,['average_response_ms'=>$avg]);
        self::apply($pid,'performance',$perfState,$perfMessage,'http',$avg>0?0.9:0.2);

        $missingSeo=[];$missingAlt=0;$images=0;$missingViewport=0;$missingLang=0;$missingLabels=0;$forms=0;
        foreach($pages as $page){
            $path=(string)(parse_url((string)($page['url']??''),PHP_URL_PATH)?:'/');
            if(trim((string)($page['title']??''))==='')$missingSeo[]=$path.' title';
            if(trim((string)($page['meta_description']??''))==='')$missingSeo[]=$path.' description';
            if((int)($page['h1_count']??0)!==1)$missingSeo[]=$path.' H1';
            $images+=(int)($page['images']??0);$missingAlt+=(int)($page['images_missing_alt']??0);
            if(empty($page['has_viewport']))$missingViewport++;
            if(trim((string)($page['lang']??''))==='')$missingLang++;
            $forms+=(int)($page['forms']??0);$missingLabels+=(int)($page['form_controls_missing_label']??0);
        }
        $seoState=$pageCount===0?'unknown':($missingSeo?'attention':'verified');
        $seoMessage=$pageCount===0?'No public page was available for SEO inspection.':($missingSeo?'SEO basics need attention: '.implode(', ',array_slice($missingSeo,0,8)).(count($missingSeo)>8?' and more.':'.'):'Every inspected page had a title, meta description, and one H1.');
        $add('seo_foundation','Search-engine foundation',$seoState,$seoMessage,'SEO','medium',$pageCount?0.86:0.2,['pages'=>$pageCount,'missing'=>array_slice($missingSeo,0,30)]);
        self::apply($pid,'seo_foundation',$seoState,$seoMessage,'url',$pageCount?0.86:0.2);

        $failures=$surface['failures']??[];$broken=array_values(array_filter($failures,static fn($f)=>(int)($f['status']??0)===0 || (int)($f['status']??0)>=400));
        $brokenState=$pageCount===0?'unknown':($broken?'attention':'verified');
        $brokenMessage=$pageCount===0?'No internal-link crawl was available.':($broken?count($broken).' linked page(s) returned an error during the safe crawl.':'No broken same-origin page was found in the links safely crawled.');
        $add('broken_links','Broken internal links',$brokenState,$brokenMessage,'Reliability','high',$pageCount?0.88:0.2,['failures'=>array_slice($broken,0,20)]);
        self::apply($pid,'broken_links',$brokenState,$brokenMessage,'url',$pageCount?0.88:0.2);

        $a11yProblems=$missingAlt+$missingViewport+$missingLang+$missingLabels;
        // A URL crawl can prove concrete markup failures, but it cannot prove full
        // keyboard behavior, focus order, contrast, screen-reader output, or private UI.
        $a11yState=$pageCount===0?'unknown':($a11yProblems>0?'attention':'unknown');
        $a11yMessage=$pageCount===0?'No public markup was available for accessibility inspection.':($a11yProblems?'Basic markup issues found: '.$missingAlt.' image(s) missing alt text, '.$missingViewport.' page(s) missing viewport, '.$missingLang.' page(s) missing language, and '.$missingLabels.' form control(s) without an observable label.':'No supported basic markup issue was found across '.$pageCount.' inspected page(s); keyboard, contrast, focus and screen-reader behavior still require deeper verification.');
        $add('accessibility','Basic accessibility',$a11yState,$a11yMessage,'Accessibility','medium',$pageCount?0.78:0.2,['images'=>$images,'missing_alt'=>$missingAlt,'missing_viewport'=>$missingViewport,'missing_lang'=>$missingLang,'forms'=>$forms,'controls_missing_label'=>$missingLabels]);
        self::apply($pid,'accessibility',$a11yState,$a11yMessage,'url',$pageCount?0.78:0.2);
        ReadinessService::recalc($pid);
        return $checks;
    }

    private static function apply(int $pid,string $key,string $state,string $message,string $source,float $confidence): void {
        if($state==='verified')ReadinessService::setStatus($pid,$key,'ready',$source,$message,$confidence);
        elseif($state==='attention')ReadinessService::setStatus($pid,$key,'need_attention',$source,$message,$confidence);
        else ReadinessService::addEvidence($pid,$key,$source,$message,$confidence,['state'=>'unknown']);
    }

    private static function compactPages(array $pages): array {
        $out=[];foreach(array_slice($pages,0,12) as $p)$out[]=[
            'url'=>$p['url']??'','status'=>$p['status']??0,'response_ms'=>$p['response_ms']??0,'title'=>$p['title']??'',
            'meta_description'=>$p['meta_description']??'','canonical'=>$p['canonical']??'','lang'=>$p['lang']??'',
            'h1_count'=>$p['h1_count']??0,'forms'=>$p['forms']??0,'images'=>$p['images']??0,
            'images_missing_alt'=>$p['images_missing_alt']??0,'form_controls_missing_label'=>$p['form_controls_missing_label']??0,
            'has_viewport'=>!empty($p['has_viewport']),
        ];return $out;
    }

    private static function json(array $data): string {return json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)?:'{}';}
}
