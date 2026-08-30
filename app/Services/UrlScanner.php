<?php
namespace App\Services;

use App\Core\DB;

final class UrlScanner {
    public static function scan(int $projectId,string $url): array {
        // Idempotently add any checks introduced by newer scanner versions.
        ReadinessService::seedChecks($projectId);
        $r=UrlInspectionService::inspect($url);
        $byKey=[];foreach($r['checks'] as $c)$byKey[$c['key']]=$c;

        $map=[
            'https'=>'url',
            'security_headers'=>'http',
            'mixed_content'=>'url',
            'cookie_security'=>'http',
            'spam_protection'=>'url',
            'reachability'=>'http',
            'privacy_policy'=>'url',
            'terms'=>'url',
            '404_recovery'=>'url',
        ];
        foreach($map as $key=>$source) self::apply($projectId,$byKey[$key]??null,$key,$source);
        if(isset($byKey['no_exposed_secrets'])){
            $c=$byKey['no_exposed_secrets'];
            if(($c['state']??'')==='attention') self::apply($projectId,$c,'no_exposed_secrets','url');
            else ReadinessService::addEvidence($projectId,'no_exposed_secrets','url',(string)($c['message']??''),(float)($c['confidence']??0.7),['scope'=>'public_html_only']);
        }
        if(isset($byKey['database_exposure'])){
            $c=$byKey['database_exposure'];
            if(($c['state']??'')==='attention') self::apply($projectId,$c,'database_exposure','url');
            else ReadinessService::addEvidence($projectId,'database_exposure','url',(string)($c['message']??''),(float)($c['confidence']??0.6),['scope'=>'public_html_only']);
        }
        if(isset($byKey['monitoring'])) self::apply($projectId,$byKey['monitoring'],'monitoring','url',true);

        $d=$r['detected'];
        DB::exec(
            'UPDATE projects SET toolchain=COALESCE(NULLIF(toolchain,""),?),hosting_platform=COALESCE(NULLIF(hosting_platform,""),?),database_platform=COALESCE(NULLIF(database_platform,""),?),ai_provider=COALESCE(NULLIF(ai_provider,""),?),updated_at=CURRENT_TIMESTAMP WHERE id=?',
            [$d['builder']?:($d['framework']?:'Not detected'),$d['hosting']?:'Not detected',$d['database']?:'Not detected',$d['ai_provider']?:'Not detected',$projectId]
        );
        ReadinessService::recalc($projectId);
        return [
            'status'=>$r['status'],'response_ms'=>$r['response_ms'],'effective_url'=>$r['effective_url'],
            'detected'=>$d,'checks'=>$r['checks'],'meta'=>$r['meta']
        ];
    }

    private static function apply(int $projectId,?array $c,string $checkKey,string $source,bool $doNotDowngradeUnknown=false):void{
        if(!$c)return;
        $state=(string)$c['state'];
        if($state==='verified')$status='ready';
        elseif($state==='attention')$status='need_attention';
        elseif($state==='not_applicable')$status='not_applicable';
        else $status='pending';
        if($status==='pending'){
            // Unknown public evidence must never erase stronger source/runtime/user evidence.
            ReadinessService::addEvidence($projectId,$checkKey,$source,(string)$c['message'],(float)($c['confidence']??1.0),['state'=>'unknown']);
            return;
        }
        ReadinessService::setStatus($projectId,$checkKey,$status,$source,(string)$c['message'],(float)($c['confidence']??1.0),['inspection_state'=>$state,'meta'=>$c['meta']??[]]);
    }
}
