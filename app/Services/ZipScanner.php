<?php
namespace App\Services;

use App\Core\{DB,Env};

/**
 * Safe source-package scanner.
 *
 * 5.4.1 correctness rules:
 * - Verify source identity before attaching findings to Readiness.
 * - Commit deterministic local scan completion before optional AI so a slow AI
 *   request can never leave a valid local scan stuck in RUNNING.
 * - Prefer narrow, context-aware static checks over noisy regex matches.
 * - AI may add evidence/findings only after deterministic evidence is safely committed.
 */
final class ZipScanner {
    public const MAX_ARCHIVE=100_000_000;
    public const MAX_EXTRACTED=350_000_000;
    public const MAX_FILES=10000;

    public static function scan(int $projectId,string $tmp,string $original,string $sourceType='zip'): array {
        if(!class_exists('ZipArchive')) throw new \RuntimeException('PHP Zip extension is required.');
        if(!is_uploaded_file($tmp) && !is_file($tmp)) throw new \RuntimeException('Uploaded archive is missing.');
        $size=@filesize($tmp); if($size===false || $size<=0 || $size>self::MAX_ARCHIVE) throw new \RuntimeException('Archive must be between 1 byte and 100 MB.');
        $sourceType=in_array($sourceType,['zip','github'],true)?$sourceType:'zip';
        self::recoverStale($projectId);
        $active=DB::one('SELECT id FROM source_scans WHERE project_id=? AND status="running" ORDER BY id DESC LIMIT 1',[$projectId]);
        if($active) throw new \RuntimeException('A source scan is already running for this project. Wait for it to finish before starting another scan.');
        $project=DB::one('SELECT id,name,slug,url,github_repo FROM projects WHERE id=?',[$projectId]);
        if(!$project) throw new \RuntimeException('Project not found.');
        $sha=hash_file('sha256',$tmp);
        $scanId=(int)DB::insert('INSERT INTO source_scans(project_id,source_type,status,original_name,sha256) VALUES(?,?,?,?,?)',[$projectId,$sourceType,'running',substr($original,0,255),$sha]);
        $zip=new \ZipArchive();$opened=false;$localCompleted=false;$files=0;$bytes=0;$scannedTextFiles=0;$unreadableFiles=0;$oversizedTextFiles=0;
        try{
            if($zip->open($tmp)!==true) throw new \RuntimeException('Invalid ZIP archive.');$opened=true;
            if($zip->numFiles>self::MAX_FILES) throw new \RuntimeException('Archive contains too many files.');

            $findings=[];$manifest=[];$aiCandidates=[];$identitySignals=['domains'=>[],'identity_domains'=>[],'tokens'=>[]];
            for($i=0;$i<$zip->numFiles;$i++){
                $st=$zip->statIndex($i);if(!$st)continue;
                $name=(string)($st['name']??'');$entrySize=max(0,(int)($st['size']??0));$bytes+=$entrySize;
                if($bytes>self::MAX_EXTRACTED) throw new \RuntimeException('Uncompressed archive content would exceed the 350 MB safety limit.');
                if($name===''||str_starts_with($name,'/')||preg_match('#(^|/)\.\.(/|$)#',$name)||str_contains($name,"\0")) throw new \RuntimeException('Unsafe archive path detected.');
                $attr=(int)($st['external_attributes']??0);$opsys=0;
                if(method_exists($zip,'getExternalAttributesIndex')){$external=0;if($zip->getExternalAttributesIndex($i,$opsys,$external))$attr=(int)$external;}
                $mode=($attr>>16)&0xF000;if($mode===0xA000) throw new \RuntimeException('Symbolic links are not allowed in uploaded archives.');
                if(str_ends_with($name,'/'))continue;
                $files++;
                $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
                $manifest[]=['path'=>$name,'size'=>$entrySize,'ext'=>$ext];
                if(self::ignoredPath($name) || !self::scannableFile($name,$ext))continue;
                if($entrySize>2_000_000){$oversizedTextFiles++;continue;}
                $content=$zip->getFromIndex($i);if($content===false){$unreadableFiles++;continue;}
                // A supported extension containing binary/encrypted data was not
                // actually inspected. Count it as incomplete coverage so a clean
                // credential verdict cannot be produced from unreadable evidence.
                $content=(string)$content;if(self::looksBinary($content)){$unreadableFiles++;continue;}
                $scannedTextFiles++;
                self::collectIdentitySignals($identitySignals,$name,$content,$project);
                self::inspect($findings,$name,$content,$ext);
                $priority=self::aiPriority($name,$ext,$content);
                if($priority>0)$aiCandidates[]=['index'=>$i,'path'=>$name,'size'=>$entrySize,'ext'=>$ext,'priority'=>$priority];
            }

            if($unreadableFiles>0||$oversizedTextFiles>0){
                $findings[]=['severity'=>'medium','category'=>'code_quality','title'=>'Source coverage is limited','description'=>'Some supported first-party text files could not be inspected completely.','evidence'=>$unreadableFiles.' unreadable/encrypted file(s); '.$oversizedTextFiles.' supported text file(s) exceeded the 2 MB per-file inspection limit.','file'=>null,'line'=>null,'fix'=>'Upload an unencrypted project ZIP with first-party source files below 2 MB each. Split generated or unusually large modules without changing behavior, then run a fresh Code Scan before relying on a clean result.'];
            }
            if($scannedTextFiles===0){
                $findings[]=['severity'=>'info','category'=>'code_quality','title'=>'No scannable first-party source','description'=>'The archive did not provide readable supported first-party text source for deterministic inspection.','evidence'=>'Archive entries: '.$files.'; readable supported text files inspected: 0.','file'=>null,'line'=>null,'fix'=>'Upload the actual project source as an unencrypted ZIP. Include application source and manifests, exclude generated/vendor folders, and run Code Scan again.'];
            }

            // Source identity is evaluated BEFORE any finding/check mutates Readiness.
            $identity=self::assessIdentity($project,$identitySignals,$sourceType,$original);
            if(($identity['status']??'unknown')==='mismatch'){
                self::insertFinding($scanId,[
                    'severity'=>'info','category'=>'code_quality','title'=>'Source project mismatch',
                    'description'=>'This archive appears to belong to a different application, so SIUGOALS did not attach its findings to this project readiness score.',
                    'evidence'=>'Expected '.($identity['expected_host']?:'the current project').'; dominant source identity: '.($identity['detected_host']?:'another application').'.',
                    'file'=>null,'line'=>null,
                    'fix'=>'Upload the ZIP/repository that belongs to this SIUGOALS project, then run Source Scan again. If the archive intentionally has no production-domain references, verify the project package/name before attaching it.'
                ]);
                DB::exec('UPDATE source_scans SET status="failed",total_files=?,total_bytes=?,completed_at=CURRENT_TIMESTAMP WHERE id=?',[$files,$bytes,$scanId]);
                throw new \RuntimeException('Source mismatch detected: this archive appears to belong to '.($identity['detected_host']?:'another application').', not '.($identity['expected_host']?:$project['name']).'. No readiness evidence was changed.');
            }

            // Deterministic findings + readiness mutations + local scan completion are
            // one DB transaction. A PHP/DB failure cannot leave half of a ZIP influencing
            // Readiness while the source scan itself is failed/running.
            DB::tx(function() use ($findings,$scanId,$projectId,$sourceType,$aiCandidates,$identity,$files,$bytes,$scannedTextFiles,$unreadableFiles,$oversizedTextFiles): void {
                foreach($findings as $f){self::insertFinding($scanId,$f);}

                if(self::hasTitle($findings,'Production debug')){
                    ReadinessService::setStatus($projectId,'debug_off','need_attention',$sourceType,'Debug configuration appears enabled in production-sensitive code.',0.9);
                }else{
                    ReadinessService::addEvidence($projectId,'debug_off',$sourceType,'Local static scan found no obvious debug-enable pattern; this alone is not proof that production debug is disabled.',0.55);
                }
                if(self::hasTitle($findings,'Incomplete production code')){
                    ReadinessService::setStatus($projectId,'dead_code','need_attention',$sourceType,'TODO/FIXME/stub patterns were found in first-party source.',0.78);
                }else{
                    ReadinessService::addEvidence($projectId,'dead_code',$sourceType,'Local static scan found no obvious TODO/FIXME/stub marker in scanned first-party files.',0.58);
                }
                if(self::hasTitle($findings,'Hard-coded credential') || self::hasTitle($findings,'Provider credential material') || self::hasTitle($findings,'Database credential URI') || self::hasTitle($findings,'Private key material')){
                    ReadinessService::setStatus($projectId,'no_exposed_secrets','need_attention',$sourceType,'Credential-like or private-key material was detected in application source. Review the exact source finding before release.',0.96);
                }elseif($scannedTextFiles>0&&$unreadableFiles===0&&$oversizedTextFiles===0){
                    ReadinessService::setStatus($projectId,'no_exposed_secrets','ready',$sourceType,'No supported credential/private-key pattern was detected across the readable scanned first-party source files. This is scoped evidence, not a guarantee that unknown secret formats do not exist.',0.80,['scanned_source_files'=>$scannedTextFiles]);
                }else{
                    $secretCheck=DB::one('SELECT status FROM checks WHERE project_id=? AND check_key="no_exposed_secrets"',[$projectId]);$summary='Secret scanning coverage was incomplete. Readable source files: '.$scannedTextFiles.'; unreadable/encrypted: '.$unreadableFiles.'; oversized supported text: '.$oversizedTextFiles.'.';if(($secretCheck['status']??'')==='need_attention')ReadinessService::addEvidence($projectId,'no_exposed_secrets',$sourceType,$summary.' The existing observed finding remains open.',0.35,['scanned_source_files'=>$scannedTextFiles,'unreadable_files'=>$unreadableFiles,'oversized_text_files'=>$oversizedTextFiles]);else ReadinessService::setStatus($projectId,'no_exposed_secrets','pending',$sourceType,$summary.' A complete fresh scan is required before this check can be ready.',0.35,['scanned_source_files'=>$scannedTextFiles,'unreadable_files'=>$unreadableFiles,'oversized_text_files'=>$oversizedTextFiles]);
                }
                if($sourceType==='github'){
                    ReadinessService::setStatus($projectId,'source_control','ready','github','A connected GitHub repository was scanned directly by SIUGOALS.',1.0);
                }else{
                    $current=DB::one('SELECT status FROM checks WHERE project_id=? AND check_key="source_control"',[$projectId]);
                    if(($current['status']??'')!=='ready') ReadinessService::addEvidence($projectId,'source_control','zip','Source was uploaded as a ZIP; a ZIP does not prove that production code is version-controlled.',0.55);
                }
                if(($identity['status']??'')==='match'){
                    ReadinessService::addEvidence($projectId,'source_control',$sourceType,'Source identity matched the current project before findings were attached.',0.72,['identity'=>$identity]);
                }

                // IMPORTANT: local static scan is complete before the remote AI pass.
                DB::exec('UPDATE source_scans SET status="completed",total_files=?,total_bytes=?,completed_at=CURRENT_TIMESTAMP WHERE id=?',[$files,$bytes,$scanId]);
                ReadinessService::recalc($projectId);
            });
            $localCompleted=true;

            $corpus=self::buildAiCorpus($zip,$aiCandidates);
            $ai=['status'=>'disabled','summary'=>'AI source analysis is not configured.'];
            if(AiScanService::enabled() && !$corpus){
                $ai=['status'=>'insufficient_source','summary'=>'No supported first-party text source was available for AI analysis.'];
            }elseif(AiScanService::enabled()){
                try{$ai=AiScanService::analyzeSource($projectId,$scanId,$sourceType,$manifest,$corpus,$findings);}
                catch(\Throwable $e){
                    error_log('SIUGOALS AI source scan: '.$e->getMessage());
                    $ai=['status'=>'failed','summary'=>'AI source analysis failed safely; completed deterministic findings were preserved.'];
                }
            }
            $allCount=(int)(DB::one('SELECT COUNT(*) c FROM source_findings WHERE source_scan_id=?',[$scanId])['c']??0);
            $crit=(int)(DB::one('SELECT COUNT(*) c FROM source_findings WHERE source_scan_id=? AND severity IN ("critical","high")',[$scanId])['c']??0);
            return [
                'scan_id'=>$scanId,'source_type'=>$sourceType,'files'=>$files,'bytes'=>$bytes,'findings'=>$allCount,'high_or_critical'=>$crit,
                'identity'=>$identity,'ai'=>$ai,'scanned_text_files'=>$scannedTextFiles,'unreadable_files'=>$unreadableFiles,'oversized_text_files'=>$oversizedTextFiles,'ai_files'=>count($corpus),'ai_chars'=>array_sum(array_map(static fn($x)=>strlen((string)$x['content']),$corpus))
            ];
        }catch(\Throwable $e){
            // Do not turn a successfully completed deterministic scan into FAILED just
            // because a later optional step failed.
            if(!$localCompleted){
                $current=DB::one('SELECT status FROM source_scans WHERE id=?',[$scanId]);
                if(($current['status']??'running')==='running') DB::exec('UPDATE source_scans SET status="failed",total_files=?,total_bytes=?,completed_at=CURRENT_TIMESTAMP WHERE id=?',[$files,$bytes,$scanId]);
            }
            throw $e;
        }finally{if($opened)$zip->close();}
    }

    public static function recoverStale(int $projectId,int $olderThanSeconds=900): int {
        $cut=date('Y-m-d H:i:s',time()-max(300,$olderThanSeconds));
        $rows=DB::all('SELECT id,source_type,created_at FROM source_scans WHERE project_id=? AND status="running" AND created_at<? ORDER BY id',[$projectId,$cut]);
        foreach($rows as $row){
            $scanId=(int)$row['id'];$start=(string)$row['created_at'];
            $next=DB::one('SELECT created_at FROM source_scans WHERE project_id=? AND id>? ORDER BY id ASC LIMIT 1',[$projectId,$scanId]);
            $end=(string)($next['created_at']??date('Y-m-d H:i:s'));
            DB::tx(function() use ($projectId,$row,$scanId,$start,$end): void {
                // 5.4 and earlier could write ZIP/GitHub/source-AI evidence before a
                // source scan reached COMPLETED. Invalidate only evidence attributable
                // to this stale scan window; never delete URL/runtime/user evidence.
                $candidate=DB::all('SELECT id,check_id,source,meta_json,created_at FROM evidence WHERE project_id=? AND created_at>=? AND created_at<? AND (source=? OR source="ai") ORDER BY id',[$projectId,$start,$end,(string)$row['source_type']]);
                $deleteIds=[];$affected=[];$latestRemoved=[];
                foreach($candidate as $ev){
                    $isSource=(string)$ev['source']===(string)$row['source_type'];
                    $meta=json_decode((string)($ev['meta_json']??''),true)?:[];
                    $isSourceAi=(string)$ev['source']==='ai' && (string)($meta['scope']??'')==='source_ai';
                    if(!$isSource&&!$isSourceAi)continue;
                    $id=(int)$ev['id'];$cid=(int)$ev['check_id'];$deleteIds[]=$id;$affected[$cid]=true;$latestRemoved[$cid]=max((int)($latestRemoved[$cid]??0),$id);
                }
                foreach($deleteIds as $id)DB::exec('DELETE FROM evidence WHERE id=?',[$id]);
                DB::exec('UPDATE source_scans SET status="failed",completed_at=CURRENT_TIMESTAMP WHERE id=? AND status="running"',[$scanId]);

                foreach(array_keys($affected) as $cid){
                    $check=DB::one('SELECT check_key,last_checked_at FROM checks WHERE id=? AND project_id=?',[$cid,$projectId]);if(!$check)continue;
                    $newer=DB::one('SELECT id FROM evidence WHERE check_id=? ORDER BY id DESC LIMIT 1',[$cid]);
                    // If a later valid evidence row exists after the removed source rows,
                    // leave the current verdict alone. Otherwise reset conservatively.
                    if($newer && (int)$newer['id']>(int)($latestRemoved[$cid]??0))continue;
                    DB::exec('UPDATE checks SET status="pending",resolved_at=NULL,last_checked_at=CURRENT_TIMESTAMP WHERE id=?',[$cid]);
                    ReadinessService::addEvidence($projectId,(string)$check['check_key'],'historical','Evidence from an interrupted source scan was invalidated. Re-scan the correct source before relying on this check.',0.0,['invalidated_source_scan_id'=>$scanId]);
                }
                ReadinessService::recalc($projectId);
            });
        }
        return count($rows);
    }

    private static function buildAiCorpus(\ZipArchive $zip,array $candidates): array {
        if(!AiScanService::enabled())return [];
        usort($candidates,static fn($a,$b)=>($b['priority']<=>$a['priority']) ?: strcmp($a['path'],$b['path']));
        $maxChars=max(60_000,min(350_000,(int)Env::get('AI_SCAN_MAX_CHARS',300_000)));
        $maxFiles=max(10,min(100,(int)Env::get('AI_SCAN_MAX_FILES',100)));
        $used=0;$out=[];
        foreach($candidates as $c){
            if(count($out)>=$maxFiles || $used>=$maxChars)break;
            $raw=$zip->getFromIndex((int)$c['index']);if($raw===false)continue;
            $raw=(string)$raw;if(self::looksBinary($raw))continue;
            $remaining=$maxChars-$used;if($remaining<1500)break;
            $budget=min(24_000,$remaining);
            $compact=self::compactSource($raw,$budget);
            $compact=self::redactSecrets($compact);
            if(trim($compact)==='')continue;
            $out[]=['path'=>$c['path'],'content'=>$compact];$used+=strlen($compact);
        }
        return $out;
    }

    private static function compactSource(string $content,int $budget): string {
        $lines=preg_split('/\R/u',$content)?:[];
        if(strlen($content)<=$budget) return self::numberLines($lines,1,$budget);
        $wanted=[];
        $keywords='/\b(auth|authorize|permission|role|admin|middleware|rate.?limit|throttl|csrf|cors|cookie|session|backup|restore|rollback|deploy|production|debug|error|monitor|sentry|logrocket|secret|token|password|api.?key|billing|limit|quota|accessibility|aria|consent|analytics|tracking)\b/i';
        $total=count($lines);
        foreach($lines as $i=>$line){
            if($i<80 || preg_match($keywords,$line)){
                for($j=max(0,$i-4);$j<=min($total-1,$i+6);$j++)$wanted[$j]=true;
            }
        }
        ksort($wanted);$out='';$last=-2;
        foreach(array_keys($wanted) as $i){
            if($i>$last+1)$out.="\n… omitted lines …\n";
            $piece='L'.($i+1).': '.$lines[$i]."\n";
            if(strlen($out)+strlen($piece)>$budget)break;
            $out.=$piece;$last=$i;
        }
        return substr($out,0,$budget);
    }

    private static function numberLines(array $lines,int $start,int $budget): string {
        $out='';foreach($lines as $i=>$line){$piece='L'.($start+$i).': '.$line."\n";if(strlen($out)+strlen($piece)>$budget)break;$out.=$piece;}return $out;
    }

    public static function redactSecrets(string $text): string {
        $patterns=[
            '/\bsk-[A-Za-z0-9_-]{16,}\b/' => '[REDACTED_OPENAI_KEY]',
            '/\bAKIA[0-9A-Z]{16}\b/' => '[REDACTED_AWS_KEY]',
            '/AIza[0-9A-Za-z\-_]{30,}/' => '[REDACTED_GOOGLE_KEY]',
            '/((?:postgres(?:ql)?|mysql|mongodb(?:\+srv)?)\:\/\/[^:\s\/]+:)[^@\s\/]+@/i' => '$1[REDACTED]@',
            '/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----.*?-----END (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/s' => '[REDACTED_PRIVATE_KEY]',
            '/(?im)^([A-Z0-9_]*(?:SECRET|TOKEN|PASSWORD|PASS|API_KEY|APP_KEY|ENCRYPTION_KEY|PRIVATE_KEY|CLIENT_SECRET)[A-Z0-9_]*\s*=\s*)(.+)$/' => '$1[REDACTED]',
            '/((?:password|api[_-]?key|app[_-]?key|encryption[_-]?key|secret|token|client[_-]?secret)\s*[:=]\s*["\'])([^"\']{4,})(["\'])/i' => '$1[REDACTED]$3',
            '/\bBearer\s+[A-Za-z0-9._~+\/-]{12,}/i' => 'Bearer [REDACTED]',
            '/\beyJ[A-Za-z0-9_-]{12,}\.[A-Za-z0-9_-]{8,}(?:\.[A-Za-z0-9_-]{8,})?\b/' => '[REDACTED_TOKEN]',
        ];
        foreach($patterns as $re=>$rep)$text=preg_replace($re,$rep,$text)??$text;
        return $text;
    }

    private static function aiPriority(string $path,string $ext,string $content): int {
        $p=strtolower($path);$score=10;
        $high=['auth','middleware','route','api','security','permission','role','rbac','backup','deploy','docker','nginx','config','setting','session','cookie','billing','limit','monitor','error','privacy','terms','.github/workflows','package.json','composer.json','requirements.txt','vite.config','next.config','.htaccess'];
        foreach($high as $needle)if(str_contains($p,$needle))$score+=12;
        if(in_array($ext,['php','ts','tsx','js','jsx','py','rb','go','java','rs','kt','kts','swift','cs','c','cpp','h','hpp','sh','ps1'],true))$score+=5;
        if(preg_match('/auth|permission|rate.?limit|backup|rollback|debug|csrf|cors|cookie|session|sentry|monitor/i',substr($content,0,120000)))$score+=18;
        if(str_ends_with($p,'.min.js')||str_ends_with($p,'.min.css'))$score=0;
        return $score;
    }

    private static function ignoredPath(string $path): bool {
        $p='/'.strtolower(str_replace('\\','/',$path));
        foreach(['/node_modules/','/vendor/','/.git/','/dist/','/build/','/coverage/','/storage/logs/','/cache/','/.next/','/.venv/','/venv/','/target/','/bin/debug/','/bin/release/','/obj/','/public/assets/','/tests/','/test/','/spec/','/__tests__/'] as $needle){if(str_contains($p,$needle))return true;}
        return false;
    }

    private static function scannableExtension(string $ext): bool {
        return in_array($ext,['php','js','mjs','cjs','ts','tsx','jsx','vue','svelte','json','lock','env','example','yml','yaml','sql','html','htm','css','scss','sass','less','py','rb','go','java','kt','kts','rs','swift','dart','lua','pl','pm','ex','exs','erl','hrl','sol','tf','hcl','graphql','gql','cs','csproj','props','properties','gradle','xml','conf','cfg','ini','md','txt','toml','sh','ps1','c','cpp','h','hpp'],true);
    }

    private static function scannableFile(string $path,string $ext): bool {
        if(self::scannableExtension($ext))return true;$base=strtolower(basename(str_replace('\\','/',$path)));if($base==='.env'||str_starts_with($base,'.env.')||str_starts_with($base,'dockerfile.')||str_starts_with($base,'containerfile.'))return true;return in_array($base,['dockerfile','containerfile','procfile','makefile','.htaccess','.npmrc','.yarnrc','.pypirc','.netrc','requirements.txt','pipfile','gemfile'],true);
    }

    private static function looksBinary(string $s): bool { return str_contains(substr($s,0,4096),"\0"); }
    private static function hasTitle(array $f,string $needle): bool { foreach($f as $x)if(str_starts_with($x['title'],$needle))return true;return false; }

    private static function insertFinding(int $scanId,array $f): void {
        DB::exec('INSERT INTO source_findings(source_scan_id,severity,category,title,description,evidence,file_path,line_number,fix_prompt) VALUES(?,?,?,?,?,?,?,?,?)',[$scanId,$f['severity'],$f['category'],$f['title'],$f['description'],$f['evidence'],$f['file']??null,$f['line']??null,$f['fix']]);
    }

    private static function inspect(array &$out,string $file,string $c,string $ext): void {
        $patterns=[
          ['critical','security','Hard-coded credential','A credential-like secret appears assigned directly in first-party source.','/(?:(?<![?&])\b(?:[A-Za-z0-9_]*(?:api[_-]?key|app[_-]?key|encryption[_-]?key|client[_-]?secret|access[_-]?token|auth[_-]?token|password|passwd|secret[_-]?key))\b\s*(?:=>|=|:)\s*["\'][^"\'\r\n]{12,}["\']|^\s*(?:export\s+)?[A-Z0-9_]*(?:API_KEY|APP_KEY|ENCRYPTION_KEY|CLIENT_SECRET|ACCESS_TOKEN|AUTH_TOKEN|PASSWORD|PASSWD|SECRET_KEY)\s*=\s*[A-Za-z0-9_.+\/=\-]{12,}\s*(?:\#.*)?$)/im'],
          ['critical','security','Provider credential material','A recognizable provider credential appears in first-party source.','/(?:\bsk-[A-Za-z0-9_-]{20,}\b|\bAKIA[0-9A-Z]{16}\b|AIza[0-9A-Za-z\-_]{30,})/'],
          ['critical','security','Database credential URI','A database connection URI appears to contain an embedded username and password.','/(?:postgres(?:ql)?|mysql|mongodb(?:\+srv)?)\:\/\/[^:\s\/]+:[^@\s\/]+@/i'],
          ['critical','security','Private key material','Private key material must not be shipped in application source.','/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/'],
          ['high','security','Potential SQL injection','SQL appears to concatenate request-controlled input.','/(SELECT|UPDATE|DELETE|INSERT)[^;\n]*(\.\s*\$_(?:GET|POST|REQUEST)|\{\$_(?:GET|POST|REQUEST))/i'],
          ['high','security','Unsafe command execution','A direct OS command-execution API is used and requires strict allow-listing.','/(?<!->)(?<!::)\b(exec|shell_exec|system|passthru|popen|proc_open)\s*\(/i'],
          ['high','operations','Production debug configuration','Debug mode or error display appears enabled.','/(APP_DEBUG\s*=\s*true|display_errors\s*[,=]\s*["\']?1|DEBUG\s*=\s*true)/i'],
          ['medium','code_quality','Incomplete production code','TODO/FIXME/stub markers may indicate unfinished behavior.','/\b(TODO|FIXME|NOT IMPLEMENTED|throw new Error\(["\']Not implemented)/i'],
          ['high','security','Credentialed wildcard CORS','A wildcard CORS origin is combined with credentialed cross-origin access.','/(?is)(?:Access-Control-Allow-Origin\s*[:=]\s*["\']?\*.*?Access-Control-Allow-Credentials\s*[:=]\s*["\']?true|Access-Control-Allow-Credentials\s*[:=]\s*["\']?true.*?Access-Control-Allow-Origin\s*[:=]\s*["\']?\*)/'],
          ['high','security','Potential SSRF surface','Remote URL fetching appears to use request input without visible allow-listing.','/(file_get_contents|curl_init|fetch)\s*\(\s*\$_(?:GET|POST|REQUEST)/i'],
          ['high','security','Insecure TLS verification','TLS certificate verification appears explicitly disabled.','/(?:CURLOPT_SSL_VERIFYPEER\s*,\s*(?:false|0)|rejectUnauthorized\s*:\s*false|verify\s*=\s*False)/i'],
          ['high','security','Request-controlled code evaluation','Request-controlled content appears to reach a dynamic code-evaluation function.','/\beval\s*\(\s*(?:\$_(?:GET|POST|REQUEST)|req\.(?:body|query|params)|request\.(?:args|form|json))/i'],
          ['high','security','Unsafe deserialization','Untrusted request content appears to reach an unsafe deserialization API.','/(?:unserialize\s*\(\s*\$_(?:GET|POST|REQUEST)|pickle\.loads?\s*\(\s*request\.(?:data|args|form|json))/i'],
          ['high','security','Potential DOM injection','A browser-controlled value appears assigned directly to an HTML execution sink.','/(?:innerHTML\s*=|document\.write\s*\()\s*(?:location\.(?:search|hash|href)|document\.(?:URL|cookie))/i'],
          ['high','security','Weak password hashing','A password-like value appears to be hashed with MD5 or SHA-1.','/\b(?:md5|sha1)\s*\(\s*(?:\$[A-Za-z0-9_]*(?:password|passwd|pass|pwd)[A-Za-z0-9_]*|\$_(?:POST|REQUEST)\s*\[\s*["\'](?:pass|password|pwd))/i'],
        ];
        foreach($patterns as [$sev,$cat,$title,$desc,$re]){
            if(!preg_match_all($re,$c,$matches,PREG_SET_ORDER|PREG_OFFSET_CAPTURE))continue;
            foreach(array_slice($matches,0,5) as $m){
                $match=(string)$m[0][0];
                if($title==='Hard-coded credential'){
                    if(in_array($ext,['md','txt'],true))continue;
                    if(preg_match('/example|changeme|your[_-]|placeholder|dummy|sample|test123|xxxx|redacted|replace[_-]?me/i',$match))continue;
                }
                if(in_array($title,['Provider credential material','Database credential URI'],true) && in_array($ext,['md','txt','example'],true))continue;
                $offset=(int)$m[0][1];
                if($title==='Unsafe command execution'){
                    // Defense in depth for method/static calls in case future PCRE changes
                    // make the look-behind expression less strict.
                    $prefix=substr($c,max(0,$offset-3),3);
                    if(str_ends_with($prefix,'->')||str_ends_with($prefix,'::'))continue;
                }
                if($title==='Incomplete production code' && in_array($ext,['md','txt','json','lock','env','example'],true))continue;
                $line=substr_count(substr($c,0,$offset),"\n")+1;
                if($title==='Provider credential material'){
                    $duplicate=false;foreach($out as $existing){if(($existing['file']??'')===$file&&(int)($existing['line']??0)===$line&&($existing['title']??'')==='Hard-coded credential'){$duplicate=true;break;}}if($duplicate)continue;
                }
                $start=max(0,$offset-100);$snippet=trim(substr($c,$start,min(260,strlen($c)-$start)));
                $snippet=self::redactSecrets($snippet);
                $out[]=['severity'=>$sev,'category'=>$cat,'title'=>$title,'description'=>$desc,'evidence'=>$snippet,'file'=>$file,'line'=>$line,'fix'=>self::fixPrompt($title,$file,$line)];
            }
        }
    }

    private static function fixPrompt(string $title,string $file,int $line): string {
        $where=$file.' near line '.$line;
        return match($title){
            'Hard-coded credential'=>"Remove the hard-coded credential at {$where}. Move the value to the hosting secret/environment configuration, rotate the exposed credential if it was ever deployed, keep only the variable reference in source, and add a regression check that prevents secret literals from being committed.",
            'Provider credential material'=>"Remove the provider credential found at {$where}. Revoke and rotate it immediately if it was ever real or deployed, store the replacement only in protected hosting secrets/environment configuration, and add secret-scanning protection to the repository and release process.",
            'Database credential URI'=>"Remove the credential-bearing database URI at {$where}. Rotate the database password, move the URI to protected environment configuration, restrict the database user to least privilege and allowed hosts, and verify logs/build artifacts do not contain the old URI.",
            'Private key material'=>"Remove private-key material from {$where}. Revoke/rotate the key if it was deployed, store the replacement only in a protected secret manager/environment variable, and verify the application can start without the private key being present in the repository.",
            'Potential SQL injection'=>"Replace the request-controlled SQL construction at {$where} with a prepared/parameterized query. Validate the expected input type/shape, preserve the intended query behavior, and add a test using quotes and SQL metacharacters to prove the value is never interpreted as SQL.",
            'Unsafe command execution'=>"Review the direct OS command call at {$where}. Remove shell execution if possible; otherwise use a fixed executable and strict allow-listed arguments without shell interpolation, reject user-controlled command text, and add a regression test for command-injection characters.",
            'Production debug configuration'=>"Disable production debug/error display at {$where}. Keep detailed errors in protected server logs, expose only a generic user-safe error page, and verify APP_ENV=production does not render stack traces or secrets.",
            'Incomplete production code'=>"Resolve the unfinished production marker at {$where}. Implement the intended behavior or remove the unreachable stub, then test the affected path so a TODO/FIXME/Not implemented branch cannot reach production users.",
            'Credentialed wildcard CORS'=>"Fix the credentialed wildcard CORS policy at {$where}. Use an explicit trusted-origin allow-list for credentialed requests, return the matching allowed Origin rather than '*', include Vary: Origin, and add browser tests proving untrusted origins cannot make authenticated requests.",
            'Potential SSRF surface'=>"Harden remote URL fetching at {$where}. Parse the URL, allow only http/https, reject credentials and private/loopback/link-local IP ranges after DNS resolution and redirects, restrict ports, and add SSRF regression cases for localhost and cloud-metadata addresses.",
            'Insecure TLS verification'=>"Restore TLS certificate and hostname verification at {$where}. Configure the correct CA trust chain instead of bypassing validation, fail closed on certificate errors, and add a test proving an invalid/self-signed endpoint is rejected.",
            'Request-controlled code evaluation'=>"Remove request-controlled dynamic evaluation at {$where}. Replace it with an explicit allow-listed operation or parser, validate the input schema, and add code-injection regression cases that prove input can never become executable code.",
            'Unsafe deserialization'=>"Replace unsafe deserialization at {$where} with a non-executable data format such as validated JSON. Enforce a strict schema and size limit, reject unexpected types, and add a regression payload that would execute or instantiate an unsafe object in the old implementation.",
            'Potential DOM injection'=>"Remove the untrusted HTML sink at {$where}. Render the value as text or sanitize it with a well-maintained allow-list sanitizer when HTML is truly required, preserve the intended UI, and add DOM-XSS tests for script, event-handler and javascript: payloads.",
            'Weak password hashing'=>"Replace weak password hashing at {$where} with the platform password-hashing API (Argon2id or bcrypt), use a backward-compatible rehash-on-login migration for existing users, and add authentication tests for old and newly rehashed credentials.",
            default=>"Fix {$title} at {$where}, preserve intended behavior, and add a regression test that demonstrates the risky pattern is no longer exploitable."
        };
    }

    private static function collectIdentitySignals(array &$signals,string $path,string $content,array $project): void {
        $sample=substr($content,0,350000);
        // Generic links are useful context but are NOT strong enough to reject a package:
        // monitoring/CDN/API integrations naturally point at other domains.
        if(preg_match_all('#https?://([A-Za-z0-9.-]+)(?::\d+)?(?:[/"\'\s]|$)#i',$sample,$m)){
            foreach(array_unique($m[1]??[]) as $host){
                $host=self::normalHost((string)$host);if($host===''||self::ignoredIdentityHost($host))continue;
                $signals['domains'][$host][$path]=true;
            }
        }

        // Strong identity signals are limited to production-site/canonical configuration.
        // This deliberately does not treat SDK/API/vendor URLs as project identity.
        $strongPatterns=[
            '/\b(?:APP_URL|SITE_URL|PUBLIC_URL|CANONICAL_URL|BASE_URL)\b\s*(?:=>|=|:)\s*["\']https?:\/\/([A-Za-z0-9.-]+)/i',
            '/["\'](?:app_url|site_url|public_url|canonical_url)["\']\s*(?:=>|:)\s*["\']https?:\/\/([A-Za-z0-9.-]+)/i',
            '/<link\b[^>]*\brel=["\'][^"\']*canonical[^"\']*["\'][^>]*\bhref=["\']https?:\/\/([A-Za-z0-9.-]+)/i',
            '/<meta\b[^>]*(?:property|name)=["\']og:url["\'][^>]*\bcontent=["\']https?:\/\/([A-Za-z0-9.-]+)/i',
            '/<loc>\s*https?:\/\/([A-Za-z0-9.-]+)/i',
        ];
        foreach($strongPatterns as $re){
            if(!preg_match_all($re,$sample,$sm))continue;
            foreach(array_unique($sm[1]??[]) as $host){$host=self::normalHost((string)$host);if($host!=='')$signals['identity_domains'][$host][$path]=true;}
        }

        $tokens=self::identityTokens($project);
        $hay=strtolower($path."\n".substr($sample,0,180000));
        foreach($tokens as $token){if($token!=='' && str_contains($hay,$token))$signals['tokens'][$token][$path]=true;}
    }

    private static function assessIdentity(array $project,array $signals,string $sourceType,string $original): array {
        $expected=self::normalHost((string)(parse_url((string)($project['url']??''),PHP_URL_HOST)?:''));
        $generic=[];foreach($signals['domains']??[] as $host=>$files)$generic[$host]=count($files);arsort($generic);
        $strong=[];foreach($signals['identity_domains']??[] as $host=>$files)$strong[$host]=count($files);arsort($strong);
        $expectedHits=0;foreach($strong as $host=>$count)if(self::sameProjectHost($host,$expected))$expectedHits+=$count;
        $tokenHits=0;foreach($signals['tokens']??[] as $files)$tokenHits=max($tokenHits,count($files));
        $detected='';$detectedHits=0;
        foreach($strong as $host=>$count){if(!self::sameProjectHost($host,$expected)){$detected=$host;$detectedHits=$count;break;}}
        if($detected===''){$detected=(string)(array_key_first($generic)??'');$detectedHits=$detected?(int)$generic[$detected]:0;}

        // GitHub selection is already tied to an explicitly authorized repository.
        if($sourceType==='github') return ['status'=>'match','expected_host'=>$expected,'detected_host'=>$detected,'expected_hits'=>$expectedHits,'detected_hits'=>$detectedHits,'reason'=>'authorized_repository'];
        if($expected!=='' && $expectedHits>0) return ['status'=>'match','expected_host'=>$expected,'detected_host'=>$expected,'expected_hits'=>$expectedHits,'detected_hits'=>$expectedHits,'reason'=>'canonical_or_app_url_found'];

        // A project-name/slug appearing in multiple first-party files is useful positive
        // evidence, but one incidental mention is not enough.
        if($tokenHits>=2 && !$strong) return ['status'=>'match','expected_host'=>$expected,'detected_host'=>$detected,'expected_hits'=>$expectedHits,'detected_hits'=>$detectedHits,'reason'=>'project_identity_tokens'];

        // Reject only a strong canonical/APP_URL identity for another site. Generic URLs
        // (SDKs, payment providers, APIs, CDNs) can never trigger this gate by themselves.
        if($expected!=='' && $detected!=='' && !self::sameProjectHost($detected,$expected) && isset($strong[$detected])){
            return ['status'=>'mismatch','expected_host'=>$expected,'detected_host'=>$detected,'expected_hits'=>0,'detected_hits'=>(int)$strong[$detected],'reason'=>'explicit_other_project_identity'];
        }
        if($tokenHits>=2) return ['status'=>'match','expected_host'=>$expected,'detected_host'=>$detected,'expected_hits'=>$expectedHits,'detected_hits'=>$detectedHits,'reason'=>'project_identity_tokens'];
        return ['status'=>'unknown','expected_host'=>$expected,'detected_host'=>$detected,'expected_hits'=>$expectedHits,'detected_hits'=>$detectedHits,'reason'=>'insufficient_identity_evidence','archive'=>substr($original,0,120)];
    }

    private static function sameProjectHost(string $a,string $b): bool {
        $a=self::normalHost($a);$b=self::normalHost($b);if($a===''||$b==='')return false;
        return $a===$b || str_ends_with($a,'.'.$b) || str_ends_with($b,'.'.$a);
    }

    private static function identityTokens(array $project): array {
        $raw=[(string)($project['name']??''),(string)($project['slug']??'')];$out=[];
        foreach($raw as $v){$v=strtolower(trim($v));$v=preg_replace('/[^a-z0-9]+/','-',$v)??'';$v=trim($v,'-');if(strlen($v)>=5)$out[]=$v;$compact=str_replace('-','',$v);if(strlen($compact)>=5)$out[]=$compact;}
        return array_values(array_unique($out));
    }

    private static function normalHost(string $host): string {
        $host=strtolower(trim($host,'. '));if(str_starts_with($host,'www.'))$host=substr($host,4);return $host;
    }

    private static function ignoredIdentityHost(string $host): bool {
        $ignored=[
            'github.com','githubusercontent.com','api.github.com','npmjs.com','npmjs.org','registry.npmjs.org','jsdelivr.net','cdn.jsdelivr.net','unpkg.com','esm.sh',
            'google.com','googleapis.com','gstatic.com','fonts.googleapis.com','fonts.gstatic.com','accounts.google.com','microsoft.com','apple.com','facebook.com','x.com','twitter.com',
            'stripe.com','stripe.network','paypal.com','cloudflare.com','cloudflareinsights.com','sentry.io','openai.com','api.openai.com','anthropic.com','vercel.com','netlify.com',
            'localhost','127.0.0.1','example.com','example.org','w3.org','schema.org','mozilla.org','php.net','composer.org','packagist.org'
        ];
        foreach($ignored as $d){if($host===$d||str_ends_with($host,'.'.$d))return true;}return false;
    }
}
