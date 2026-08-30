<?php
namespace App\Services;
use App\Core\{DB,Env};

final class AgentService {
    private const AGENT_CATEGORY=[
        'security_auditor'=>'security',
        'ops_inspector'=>'operations',
        'code_reviewer'=>'code_quality',
        'compliance_checker'=>'legal_compliance',
    ];

    public static function run(int $projectId,string $agentKey): array {
        $enabled=DB::one('SELECT enabled FROM project_agents WHERE project_id=? AND agent_key=?',[$projectId,$agentKey]);
        if(!$enabled || !(int)$enabled['enabled']) throw new \RuntimeException('Agent is not enabled.');
        $cat=self::AGENT_CATEGORY[$agentKey]??null;if(!$cat)throw new \InvalidArgumentException('Unknown agent.');
        $findings=DB::all('SELECT * FROM checks WHERE project_id=? AND category=? AND status="need_attention" ORDER BY CASE severity WHEN "critical" THEN 1 WHEN "high" THEN 2 WHEN "medium" THEN 3 WHEN "low" THEN 4 ELSE 5 END,id LIMIT 12',[$projectId,$cat]);
        $source=DB::all('SELECT sf.* FROM source_findings sf JOIN source_scans ss ON ss.id=sf.source_scan_id WHERE ss.project_id=? AND sf.category=? ORDER BY sf.id DESC LIMIT 12',[$projectId,$cat]);
        $connection=DB::one('SELECT * FROM github_connections WHERE project_id=?',[$projectId]);
        $project=DB::one('SELECT * FROM projects WHERE id=?',[$projectId]);
        $runId=(int)DB::insert('INSERT INTO agent_runs(project_id,agent_key,status,input_json) VALUES(?,? ,"running",?)',[$projectId,$agentKey,json_encode(['checks'=>$findings,'source_findings'=>$source],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
        try{
            $output=self::deterministicPlan($findings,$source);
            if(Env::get('OPENAI_API_KEY') && ($findings||$source)){
                $output=self::aiPlan($agentKey,$findings,$source,$connection,$project);
            }
            // A code change is only prepared when the owner explicitly connected the separate
            // Maintenance GitHub App. SIUGOALS opens a PR; it never merges it automatically.
            if(!empty($output['patch']) && $connection && !empty($connection['write_enabled']) && !empty($connection['maintenance_installation_id']) && !empty($connection['installation_id']) && !empty($connection['repo_full_name']) && GitHubService::configured(true)){
                $patch=$output['patch'];$path=(string)($patch['path']??'');$content=(string)($patch['content']??'');
                $candidate=self::candidatePath($source);
                if($candidate && hash_equals($candidate,$path) && $content!=='' && strlen($content)<=180000){
                    $current=GitHubService::fileContent((int)$connection['installation_id'],(string)$connection['repo_full_name'],$path);
                    if(!hash_equals(hash('sha256',(string)$current['content']),hash('sha256',$content))){
                        $title='SIUGOALS: '.substr((string)($patch['title']??'verified remediation'),0,100);
                        $pr=GitHubService::createTextFilePR((int)$connection['maintenance_installation_id'],(string)$connection['repo_full_name'],$path,$content,$title);
                        $output['pull_request_url']=$pr;$output['summary']=trim((string)($output['summary']??'')).' A reviewable GitHub pull request was created; nothing was merged automatically.';
                    }
                }
                unset($output['patch']['content']); // never persist full source in agent output JSON
            } else {
                unset($output['patch']);
            }
            DB::exec('UPDATE agent_runs SET status="completed",output_json=?,completed_at=CURRENT_TIMESTAMP WHERE id=?',[json_encode($output,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$runId]);
            return ['run_id'=>$runId,'output'=>$output];
        }catch(\Throwable $e){
            DB::exec('UPDATE agent_runs SET status="failed",output_json=?,completed_at=CURRENT_TIMESTAMP WHERE id=?',[json_encode(['error'=>'Agent run failed safely.']),$runId]);
            throw $e;
        }
    }

    private static function deterministicPlan(array $findings,array $source): array {
        $actions=[];
        foreach(array_slice($findings,0,6) as $f)$actions[]=['title'=>$f['title'],'rationale'=>$f['description'],'steps'=>['Review the stored evidence for this check.','Apply the documented remediation without weakening existing controls.','Run the affected functional/security test.'],'verification'=>$f['verification_method']?:'Re-run the SIUGOALS check and confirm new evidence.'];
        foreach(array_slice($source,0,max(0,6-count($actions))) as $f)$actions[]=['title'=>$f['title'],'rationale'=>$f['description'],'steps'=>[$f['fix_prompt']?:'Review the affected code and remove the risky pattern.'],'verification'=>'Re-scan corrected source and confirm the finding is gone.'];
        return ['summary'=>$actions?'Deterministic remediation plan generated from current SIUGOALS evidence. Deeper AI analysis is not enabled on this installation.':'No unresolved evidence for this agent.','actions'=>$actions];
    }

    private static function aiPlan(string $agentKey,array $findings,array $source,?array $connection,?array $project): array {
        $context=['agent'=>$agentKey,'checks'=>$findings,'source_findings'=>$source];
        $candidate=self::candidatePath($source);
        if($candidate && $connection && !empty($connection['installation_id']) && !empty($connection['repo_full_name'])){
            try{$file=GitHubService::fileContent((int)$connection['installation_id'],(string)$connection['repo_full_name'],$candidate);if(strlen((string)$file['content'])<=80000)$context['candidate_file']=['path'=>$candidate,'content'=>ZipScanner::redactSecrets((string)$file['content'])];}catch(\Throwable $e){}
        }
        $system='You are a production-readiness engineering agent. Return strict JSON with keys summary and actions. Each action must contain title, rationale, steps (array), verification. Never claim a code change was applied. If and only if candidate_file is present and a safe, localized repair is clear, you MAY also return patch with exact keys path, title, content. patch.content must be the FULL corrected file, preserve unrelated behavior, contain no secrets, and patch.path must exactly equal candidate_file.path. Otherwise omit patch.';
        $payload=['model'=>Env::get('OPENAI_MODEL','gpt-4.1-mini'),'input'=>[['role'=>'system','content'=>[['type'=>'input_text','text'=>$system]]],['role'=>'user','content'=>[['type'=>'input_text','text'=>json_encode($context,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]]]],'text'=>['format'=>['type'=>'json_object']]];
        $ch=curl_init('https://api.openai.com/v1/responses');curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_TIMEOUT=>60,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.Env::get('OPENAI_API_KEY'),'Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode($payload)]);$raw=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$err=curl_error($ch);curl_close($ch);if($raw===false||$err||$code<200||$code>=300)throw new \RuntimeException('AI provider request failed.');
        $j=json_decode((string)$raw,true);$txt='';foreach($j['output']??[] as $o)foreach($o['content']??[] as $c)if(($c['type']??'')==='output_text')$txt.=$c['text']??'';$parsed=json_decode($txt,true);if(!is_array($parsed)||!isset($parsed['summary'])||!isset($parsed['actions'])||!is_array($parsed['actions']))throw new \RuntimeException('AI provider returned an invalid structured response.');return $parsed;
    }

    private static function candidatePath(array $source): ?string {
        foreach($source as $f){$p=(string)($f['file_path']??'');if($p!==''&&!str_contains($p,'..')&&!str_starts_with($p,'/'))return $p;}return null;
    }
}
