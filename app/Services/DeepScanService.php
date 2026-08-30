<?php
namespace App\Services;

use App\Core\{DB,Env};

/** Orchestrates one evidence-first readiness scan across URL, source, runtime and AI. */
final class DeepScanService {
    public static function scan(array $project): array {
        $pid=(int)$project['id'];
        ReadinessService::seedChecks($pid);
        $warnings=[];
        $result=[
            'engine'=>'5.4.1-legacy-deep-scan',
            'started_at'=>date(DATE_ATOM),
            'coverage'=>['url'=>false,'public_pages'=>false,'source'=>false,'source_fresh'=>false,'runtime'=>false,'ai'=>false],
        ];

        try{
            $result['url']=UrlScanner::scan($pid,(string)$project['url']);
            $result['coverage']['url']=true;
            if(!empty($result['url']['meta']['blocked'])) $warnings[]='The public URL scan was blocked/challenged by the target WAF; blocked checks were left unscored.';
        }catch(\Throwable $e){
            error_log('Deep scan URL: '.$e->getMessage());
            ReadinessService::addEvidence($pid,'reachability','url','The fresh URL probe could not be completed; previous readiness evidence was preserved.',0.0,['error_class'=>get_class($e)]);
            $result['url']=['status'=>'failed','message'=>'Fresh URL probe could not be completed.'];
            $warnings[]='Fresh URL probe could not be completed.';
        }

        try{
            if(!empty($result['coverage']['url']) && empty($result['url']['meta']['blocked']) && (int)($result['url']['status']??0)>=200 && (int)($result['url']['status']??0)<400){
                $result['public_surface']=PublicSurfaceService::crawl((string)($result['url']['effective_url']??$project['url']),8,160000);
                $result['coverage']['public_pages']=((int)($result['public_surface']['pages_scanned']??0))>0;
            }else{
                $result['public_surface']=['pages'=>[],'pages_scanned'=>0,'read_only'=>true];
            }
        }catch(\Throwable $e){
            error_log('Deep scan public surface: '.$e->getMessage());
            $result['public_surface']=['pages'=>[],'pages_scanned'=>0,'status'=>'failed','read_only'=>true];
            $warnings[]='The read-only public-page crawl could not be completed.';
        }

        // Refresh the project row because URL/runtime work can change it during the scan.
        $project=DB::one('SELECT * FROM projects WHERE id=?',[$pid])?:$project;
        try{
            $result['runtime']=RuntimeEvidenceService::refresh($project);
            $result['coverage']['runtime']=true;
        }catch(\Throwable $e){
            error_log('Deep scan runtime evidence: '.$e->getMessage());
            $result['runtime']=['status'=>'failed'];
            $warnings[]='Stored runtime evidence could not be refreshed.';
        }

        $gc=DB::one('SELECT * FROM github_connections WHERE project_id=?',[$pid]);
        $latestSource=DB::one('SELECT id,source_type,status,original_name,total_files,total_bytes,completed_at FROM source_scans WHERE project_id=? AND status="completed" ORDER BY id DESC LIMIT 1',[$pid]);
        if($latestSource){
            $result['coverage']['source']=true;
            $result['source']=['mode'=>'stored_evidence','latest'=>$latestSource];
        }else{
            $result['source']=['mode'=>'none'];
        }

        $refreshGithub=Env::bool('DEEP_SCAN_REFRESH_GITHUB',true);
        if($refreshGithub && $gc && !empty($gc['repo_full_name'])){
            if(GitHubService::configured(false) && !empty($gc['installation_id'])){
                $dir=dirname(__DIR__,2).'/storage/temp';if(!is_dir($dir))@mkdir($dir,0775,true);
                $tmp=$dir.'/deep-gh-'.bin2hex(random_bytes(7)).'.zip';
                try{
                    GitHubService::downloadZip((int)$gc['installation_id'],(string)$gc['repo_full_name'],$tmp);
                    $sourceResult=ZipScanner::scan($pid,$tmp,(string)$gc['repo_full_name'].'.zip','github');
                    $result['source']=['mode'=>'github_fresh','result'=>$sourceResult];
                    $result['coverage']['source']=true;$result['coverage']['source_fresh']=true;
                }catch(\Throwable $e){
                    error_log('Deep scan GitHub source: '.$e->getMessage());
                    $warnings[]='Connected GitHub source could not be refreshed; the scan used previously stored source evidence instead.';
                    $result['source']['refresh_status']='failed';
                }finally{@unlink($tmp);}
            }else{
                $warnings[]='A GitHub repository is recorded for this project, but the read-only GitHub App is not currently configured on this SIUGOALS installation.';
            }
        }elseif($latestSource && ($latestSource['source_type']??'')==='zip'){
            $warnings[]='The previous ZIP is not retained for privacy. Upload the ZIP again when you need a fresh full-code AI scan.';
        }

        try{
            $result['ai']=AiScanService::reviewProject($pid,is_array($result['url']??null)?$result['url']:[],is_array($result['public_surface']??null)?$result['public_surface']:[]);
            $result['coverage']['ai']=($result['ai']['status']??'')==='completed';
            if(($result['ai']['status']??'')==='disabled') $warnings[]='AI evidence review is disabled because OPENAI_API_KEY is not configured.';
        }catch(\Throwable $e){
            error_log('Deep scan AI review: '.$e->getMessage());
            $result['ai']=['status'=>'failed','summary'=>'AI evidence review failed safely; deterministic evidence was preserved.'];
            $warnings[]='AI evidence review failed safely; deterministic URL/source/runtime evidence is still valid.';
        }

        // Do not retain crawled page text in scan history. The AI receives sanitized
        // summaries for this request only; history stores coverage counts, not page content.
        if(isset($result['public_surface']) && is_array($result['public_surface'])){
            $result['public_surface']=[
                'pages_scanned'=>(int)($result['public_surface']['pages_scanned']??0),
                'characters'=>(int)($result['public_surface']['characters']??0),
                'read_only'=>true,
                'status'=>$result['public_surface']['status']??'completed',
            ];
        }

        $result['score']=ReadinessService::recalc($pid);
        $result['summary']=ReadinessService::summary($pid);
        $result['warnings']=array_values(array_unique($warnings));
        $result['completed_at']=date(DATE_ATOM);
        return $result;
    }
}
