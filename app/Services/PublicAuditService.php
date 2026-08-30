<?php
namespace App\Services;

final class PublicAuditService {
    public static function discover(string $url): array {
        return UrlInspectionService::discover($url);
    }

    public static function run(string $url,array $answers=[]): array {
        $inspection=UrlInspectionService::inspect($url);
        $checks=$inspection['checks'];
        $add=static function(string $key,string $label,string $state,string $message,int $weight,string $area,string $severity='medium',float $confidence=1.0,array $meta=[]) use (&$checks):void{
            $checks[]=compact('key','label','state','message','weight','area','severity','confidence','meta');
        };

        self::answerCheck($add,'backups','Automatic backups',$answers['backups']??'unknown',[
            'yes'=>['verified','Owner confirmed automatic backups are included and enabled.'],
            'no'=>['attention','Owner reported that automatic backups are not enabled.'],
        ],10,'Operations','critical');

        $role=(string)($answers['roles']??'unknown');
        if($role==='configured') $add('role_separation','Permission levels','verified','Owner confirmed that separate user roles are configured.',10,'Security','critical',0.85);
        elseif($role==='needed') $add('role_separation','Permission levels','attention','The app needs different roles, but role separation is not yet configured.',10,'Security','critical',0.85);
        elseif($role==='same') $add('role_separation','Permission levels','not_applicable','Owner confirmed that everyone is intentionally meant to have the same access.',10,'Security','critical',0.8);
        else $add('role_separation','Permission levels','unknown','The app permission model is still unknown.',10,'Security','critical',0.3);

        self::answerCheck($add,'ai_spending_cap','AI spending cap',$answers['ai_spending_cap']??'unknown',[
            'yes'=>['verified','Owner confirmed an AI spending cap is configured.'],
            'no'=>['attention','Owner reported that AI usage has no spending cap.'],
            'na'=>['not_applicable','The product does not use paid AI services.'],
        ],8,'Operations','high');

        self::answerCheck($add,'per_user_limits','Per-user usage limits',$answers['per_user_limits']??'unknown',[
            'yes'=>['verified','Owner confirmed per-user usage limits are configured.'],
            'no'=>['attention','A single user may be able to consume unlimited shared resources.'],
            'na'=>['not_applicable','Per-user metered resource limits are not applicable to this product.'],
        ],8,'Operations','high');

        self::answerCheck($add,'source_control','Source control',$answers['source']??'unknown',[
            'github'=>['verified','Owner confirmed the code is stored in GitHub or equivalent source control.'],
            'other'=>['verified','Owner confirmed the code is stored in another version-control service.'],
            'none'=>['attention','Owner reported that the production code is not stored in source control.'],
        ],9,'Operations','high');

        self::answerCheck($add,'ai_ownership','AI-generated code ownership',$answers['ownership']??'unknown',[
            'yes'=>['verified','Owner confirmed that the relevant tool terms provide the required commercial ownership rights.'],
            'no'=>['attention','Owner reported restrictions or uncertainty in commercial ownership rights.'],
            'na'=>['not_applicable','AI-generated code ownership is not applicable to this product.'],
        ],7,'Compliance','medium');

        self::answerCheck($add,'hosting_cost_cap','Hosting / infrastructure cost controls',$answers['hosting_cost_cap']??'unknown',[
            'yes'=>['verified','Owner confirmed hosting or infrastructure cost controls are configured.'],
            'no'=>['attention','Hosting or infrastructure spend may have no configured ceiling.'],
        ],7,'Operations','high');

        // A user answer can strengthen monitoring evidence, but never erase positive URL evidence.
        $monitoringAnswer=(string)($answers['monitoring']??'unknown');
        if($monitoringAnswer==='yes' && !self::hasVerified($checks,'monitoring')){
            $add('monitoring_owner','Monitoring confirmation','verified','Owner confirmed production monitoring is enabled.',6,'Operations','high',0.8);
        } elseif($monitoringAnswer==='no' && !self::hasVerified($checks,'monitoring')){
            $add('monitoring_owner','Monitoring confirmation','attention','Owner reported that production monitoring is not enabled.',6,'Operations','high',0.8);
        }

        [$score,$areas,$counts]=self::score($checks);
        $blocked=!empty($inspection['meta']['blocked']);
        if($blocked){$score=null;foreach($areas as $k=>$v)$areas[$k]=null;}
        $detected=$inspection['detected'];
        foreach(['builder','hosting','database','ai_provider'] as $k){
            $answer=trim((string)($answers[$k]??''));
            if($answer!=='' && strcasecmp($answer,'I am not sure')!==0) $detected[$k]=$answer;
        }
        return [
            'url'=>$url,
            'effective_url'=>$inspection['effective_url'],
            'status'=>$inspection['status'],
            'response_ms'=>$inspection['response_ms'],
            'score'=>$score,
            'areas'=>$areas,
            'counts'=>$counts,
            'checks'=>$checks,
            'detected'=>$detected,
            'meta'=>$inspection['meta'],
            'blocked'=>$blocked,
        ];
    }


    public static function applyToProject(int $projectId,array $audit): void {
        $detected=$audit['detected']??[];
        \App\Core\DB::exec(
            'UPDATE projects SET toolchain=COALESCE(NULLIF(toolchain,""),?),hosting_platform=COALESCE(NULLIF(hosting_platform,""),?),database_platform=COALESCE(NULLIF(database_platform,""),?),ai_provider=COALESCE(NULLIF(ai_provider,""),?) WHERE id=?',
            [(string)($detected['builder']?:($detected['framework']??'Not detected')),(string)($detected['hosting']??'Not detected'),(string)($detected['database']??'Not detected'),(string)($detected['ai_provider']??'Not detected'),$projectId]
        );
        ReadinessService::seedChecks($projectId);
        $map=[
            'https'=>'https','security_headers'=>'security_headers','no_exposed_secrets'=>'no_exposed_secrets',
            'mixed_content'=>'mixed_content','cookie_security'=>'cookie_security','database_exposure'=>'database_exposure','spam_protection'=>'spam_protection',
            'reachability'=>'reachability','privacy_policy'=>'privacy_policy','terms'=>'terms','404_recovery'=>'404_recovery','monitoring'=>'monitoring',
            'backups'=>'backups','role_separation'=>'role_separation','source_control'=>'source_control','ai_ownership'=>'ai_ownership'
        ];
        $by=[];foreach(($audit['checks']??[]) as $c)$by[(string)($c['key']??'')]=$c;
        foreach($map as $publicKey=>$readinessKey){
            if(empty($by[$publicKey]))continue;$c=$by[$publicKey];
            $state=(string)($c['state']??'');
            if($publicKey==='no_exposed_secrets' && $state==='verified'){
                ReadinessService::addEvidence($projectId,$readinessKey,'url',(string)($c['message']??''),(float)($c['confidence']??0.7),['scope'=>'public_html_only']);
                continue;
            }
            if($state==='unknown'){
                ReadinessService::addEvidence($projectId,$readinessKey,'url',(string)($c['message']??''),(float)($c['confidence']??0.0),['state'=>'unknown']);
                continue;
            }
            $status=match($state){'verified'=>'ready','attention'=>'need_attention','not_applicable'=>'not_applicable',default=>'pending'};
            ReadinessService::setStatus($projectId,$readinessKey,$status,'url',(string)($c['message']??''),(float)($c['confidence']??0.7));
        }
        $costParts=array_values(array_filter([
            $by['ai_spending_cap']??null,$by['per_user_limits']??null,$by['hosting_cost_cap']??null
        ]));
        if($costParts){
            $states=array_column($costParts,'state');
            if(in_array('attention',$states,true))$status='need_attention';
            elseif(count(array_filter($states,fn($v)=>in_array($v,['verified','not_applicable'],true)))===count($states))$status='ready';
            else $status='pending';
            ReadinessService::setStatus($projectId,'cost_cap',$status,'user_answer','Public audit combined AI, per-user and hosting cost-control evidence.',0.8);
        }
        ReadinessService::recalc($projectId);
    }

    private static function answerCheck(callable $add,string $key,string $label,string $answer,array $states,int $weight,string $area,string $severity):void{
        if(isset($states[$answer])){[$state,$message]=$states[$answer];$add($key,$label,$state,$message,$weight,$area,$severity,0.85);return;}
        $add($key,$label,'unknown','This cannot be verified reliably from the public URL alone.',$weight,$area,$severity,0.3);
    }

    private static function hasVerified(array $checks,string $key):bool{
        foreach($checks as $c) if(($c['key']??'')===$key && ($c['state']??'')==='verified') return true;
        return false;
    }

    private static function score(array $checks):array{
        $areas=[];$counts=['verified'=>0,'attention'=>0,'unknown'=>0,'not_applicable'=>0];
        foreach($checks as $c){$state=(string)$c['state'];$counts[$state]=($counts[$state]??0)+1;$area=(string)$c['area'];$areas[$area]??=['earned'=>0,'total'=>0];if(in_array($state,['verified','attention'],true)){$areas[$area]['total']+=(int)$c['weight'];if($state==='verified')$areas[$area]['earned']+=(int)$c['weight'];}}
        $earned=0;$total=0;$outAreas=[];
        foreach($areas as $name=>$a){$earned+=$a['earned'];$total+=$a['total'];$outAreas[$name]=$a['total']?(int)round($a['earned']/$a['total']*100):0;}
        $score=$total?(int)round($earned/$total*100):0;
        return [$score,$outAreas,$counts];
    }
}
