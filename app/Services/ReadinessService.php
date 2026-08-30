<?php
namespace App\Services;

use App\Core\DB;

final class ReadinessService {
    public static function seedChecks(int $projectId): void {
        $checks=[
          ['https','security','HTTPS is enabled','Transport encryption is required for production.','critical'],
          ['security_headers','security','Security headers are configured','CSP, HSTS and related headers reduce common browser attacks.','high'],
          ['no_exposed_secrets','security','No exposed secrets detected','Public configuration and application source should never reveal private credentials.','critical'],
          ['mixed_content','security','HTTPS pages do not load insecure assets','Mixed HTTP assets can weaken an otherwise secure HTTPS page.','medium'],
          ['cookie_security','security','Session cookies use appropriate protection','Secure, HttpOnly and SameSite flags reduce session theft and cross-site abuse.','medium'],
          ['database_exposure','security','Database admin access is not publicly exposed','Database credentials, service-role keys and connection strings must remain private.','critical'],
          ['spam_protection','security','Public forms have appropriate abuse protection','Public forms may need rate limits, CAPTCHA or other anti-abuse controls.','medium'],
          ['rate_limits','security','Abuse protection is in place','Critical endpoints need request limits.','high'],
          ['role_separation','security','Users have appropriate permission levels','Different users should only access what they need.','critical'],
          ['backups','operations','Automatic backups are enabled','Recoverable backups protect user data after an incident.','critical'],
          ['monitoring','operations','Production monitoring is connected','Runtime monitoring detects failures before users report them.','high'],
          ['error_capture','operations','Client errors are captured','Browser errors should be visible to the owner.','high'],
          ['reachability','operations','Production is reachable','The production URL should respond reliably to health checks.','critical'],
          ['performance','operations','Public pages respond within a usable baseline','Slow server responses can make an otherwise working product feel broken.','medium'],
          ['404_recovery','operations','Broken links have a useful recovery path','Visitors should not get stuck on invalid URLs.','high'],
          ['broken_links','code_quality','Internal public links are not broken','Broken navigation prevents users and search engines from reaching important pages.','high'],
          ['runtime_identity','operations','Runtime sessions can be tied to app identities','Identity linking helps support affected users without exposing secrets.','low'],
          ['rollback','operations','A rollback path exists','Bad releases should be reversible quickly.','high'],
          ['cost_cap','operations','Infrastructure and AI spend have limits','Cost limits reduce abuse and unexpected bills.','high'],
          ['source_control','code_quality','Source code is version controlled','Source control makes changes reviewable and reversible.','high'],
          ['dependency_health','code_quality','Dependencies are maintained','Outdated packages can create reliability and security risks.','medium'],
          ['debug_off','code_quality','Production debug output is disabled','Debug traces may expose implementation details.','high'],
          ['dead_code','code_quality','No obvious incomplete production code','TODOs and stubbed handlers should not remain in critical paths.','medium'],
          ['seo_foundation','code_quality','Public pages have essential SEO metadata','Titles, descriptions and page headings help search engines understand the product.','medium'],
          ['privacy_policy','legal_compliance','Privacy policy is available','Users should understand how their data is processed.','high'],
          ['terms','legal_compliance','Terms of service are available','Commercial applications should define user terms.','medium'],
          ['cookie_consent','legal_compliance','Consent behavior matches tracking use','Consent requirements depend on audience and tracking.','medium'],
          ['ai_ownership','legal_compliance','AI-generated code ownership is understood','Terms of AI/build tools can affect commercial rights.','medium'],
          ['accessibility','legal_compliance','Core UI is accessible','Keyboard and semantic accessibility improve usability and compliance.','low'],
          ['trust_page','legal_compliance','Public trust information is available','A public verification page lets customers verify readiness evidence.','low'],
        ];
        foreach($checks as [$key,$cat,$title,$desc,$sev]){
            $sql='INSERT INTO checks(project_id,check_key,category,title,description,status,severity) VALUES(?,?,?,?,?,?,?)';
            try{DB::exec($sql,[$projectId,$key,$cat,$title,$desc,'pending',$sev]);}catch(\Throwable $e){}
        }
    }

    public static function recalc(int $projectId): int {
        $rows=DB::all('SELECT status,COUNT(*) c FROM checks WHERE project_id=? GROUP BY status',[$projectId]);
        $counts=['ready'=>0,'need_attention'=>0,'pending'=>0,'question_required'=>0,'not_applicable'=>0];
        foreach($rows as $r)$counts[$r['status']]=(int)$r['c'];
        $applicable=array_sum($counts)-$counts['not_applicable'];
        $score=$applicable>0?(int)round(($counts['ready']/$applicable)*100):0;
        DB::exec('UPDATE projects SET readiness_score=?,updated_at=CURRENT_TIMESTAMP WHERE id=?',[$score,$projectId]);
        return $score;
    }

    public static function summary(int $projectId): array {
        $rows=DB::all('SELECT status,COUNT(*) c FROM checks WHERE project_id=? GROUP BY status',[$projectId]);
        $c=['ready'=>0,'need_attention'=>0,'pending'=>0,'question_required'=>0,'not_applicable'=>0];
        foreach($rows as $r)$c[$r['status']]=(int)$r['c'];
        $c['pending_total']=$c['pending']+$c['question_required'];
        $c['applicable']=$c['ready']+$c['need_attention']+$c['pending_total'];
        $c['score']=$c['applicable']?round($c['ready']/$c['applicable']*100):0;
        return $c;
    }

    public static function setStatus(int $projectId,string $key,string $status,string $source,string $summary,float $confidence=1.0,array $meta=[]): void {
        $check=DB::one('SELECT id,status FROM checks WHERE project_id=? AND check_key=?',[$projectId,$key]);
        if(!$check)return;
        DB::exec('UPDATE checks SET status=?,last_checked_at=CURRENT_TIMESTAMP,first_detected_at=CASE WHEN ?="need_attention" THEN COALESCE(first_detected_at,CURRENT_TIMESTAMP) ELSE first_detected_at END,resolved_at=CASE WHEN ?="ready" THEN CURRENT_TIMESTAMP WHEN ?="need_attention" THEN NULL ELSE resolved_at END WHERE id=?',[$status,$status,$status,$status,$check['id']]);
        self::insertEvidence($projectId,(int)$check['id'],$source,$summary,$confidence,$meta);
    }

    public static function addEvidence(int $projectId,string $key,string $source,string $summary,float $confidence=1.0,array $meta=[]): void {
        $check=DB::one('SELECT id FROM checks WHERE project_id=? AND check_key=?',[$projectId,$key]);
        if(!$check)return;
        // A check was genuinely inspected even when the resulting evidence is identical
        // to a previous run and is therefore deduplicated. Keep last_checked_at fresh so
        // progressive scan UI can count reviewed checks without manufacturing duplicate evidence.
        DB::exec('UPDATE checks SET last_checked_at=CURRENT_TIMESTAMP WHERE id=?',[(int)$check['id']]);
        self::insertEvidence($projectId,(int)$check['id'],$source,$summary,$confidence,$meta);
    }

    private static function insertEvidence(int $projectId,int $checkId,string $source,string $summary,float $confidence,array $meta): void {
        $confidence=max(0.0,min(1.0,$confidence));$summary=trim($summary);if($summary==='')return;
        // Collapse exact repeats. Re-running the same scanner should not make one fact
        // look like several independent pieces of evidence in the Readiness drawer.
        $dupe=DB::one('SELECT id FROM evidence WHERE check_id=? AND source=? AND summary=? ORDER BY id DESC LIMIT 1',[$checkId,$source,$summary]);
        if($dupe)return;
        $metaJson=$meta?json_encode($meta,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE):null;
        try{
            DB::exec('INSERT INTO evidence(project_id,check_id,source,summary,confidence,meta_json) VALUES(?,?,?,?,?,?)',[$projectId,$checkId,$source,$summary,$confidence,$metaJson]);
        }catch(\Throwable $e){
            // Backward-compatible fallback if the application files are deployed just
            // before the 5.4 DB patch that adds the `ai` enum value.
            if($source==='ai'){
                $fallback=DB::one('SELECT id FROM evidence WHERE check_id=? AND source="configuration" AND summary=? ORDER BY id DESC LIMIT 1',[$checkId,$summary]);
                if(!$fallback)DB::exec('INSERT INTO evidence(project_id,check_id,source,summary,confidence,meta_json) VALUES(?,?,?,?,?,?)',[$projectId,$checkId,'configuration',$summary,$confidence,$metaJson]);
                return;
            }
            throw $e;
        }
    }

    public static function recentEvidence(int $checkId,int $limit=4): array {
        $limit=max(1,min(12,$limit));$rows=DB::all('SELECT * FROM evidence WHERE check_id=? ORDER BY id DESC LIMIT 40',[$checkId]);
        $out=[];$by=[];
        foreach($rows as $row){
            $fingerprint=strtolower(trim((string)$row['source'])).'|'.preg_replace('/\s+/u',' ',strtolower(trim((string)$row['summary'])));
            if(isset($by[$fingerprint])){$out[$by[$fingerprint]]['_repeat_count']++;continue;}
            $row['_repeat_count']=1;$by[$fingerprint]=count($out);$out[]=$row;if(count($out)>=$limit)break;
        }
        return $out;
    }

    public static function presentTitle(array $check): string {
        if (($check['status'] ?? '') !== 'need_attention') return (string)($check['title'] ?? 'Readiness check');
        $map=[
          'https'=>'Your app is not using HTTPS',
          'security_headers'=>'Your app is missing important security headers',
          'no_exposed_secrets'=>'A secret may be exposed in your public app or source',
          'mixed_content'=>'Your HTTPS page loads insecure HTTP content',
          'cookie_security'=>'Session cookies may be missing important protection flags',
          'database_exposure'=>'Database access material may be publicly exposed',
          'spam_protection'=>'Public forms may be missing abuse protection',
          'rate_limits'=>'Critical endpoints may have no abuse protection',
          'role_separation'=>'Users who need different permissions may have the same access',
          'backups'=>'If your database breaks, your users’ data may be lost',
          'monitoring'=>'Bugs or outages can happen without anyone knowing',
          'error_capture'=>'Browser errors can happen without being reported',
          'reachability'=>'The production app is not reliably reachable',
          'performance'=>'Public pages respond too slowly',
          '404_recovery'=>'Visitors on broken links may get stuck with no way back',
          'broken_links'=>'One or more internal public links are broken',
          'runtime_identity'=>'You cannot yet connect runtime sessions to signed-in users',
          'rollback'=>'A bad release may have no quick rollback path',
          'cost_cap'=>'Your infrastructure or AI costs may have no spending cap',
          'source_control'=>'There may be no safe way to undo a bad code change',
          'dependency_health'=>'Outdated dependencies may put production at risk',
          'debug_off'=>'Production debug output may expose implementation details',
          'dead_code'=>'Incomplete production code may still be present',
          'seo_foundation'=>'Public pages are missing essential SEO information',
          'privacy_policy'=>'Your app may be missing a privacy policy',
          'terms'=>'Your app may be missing clear terms of service',
          'cookie_consent'=>'Tracking may run before required consent',
          'ai_ownership'=>'Your AI or builder terms may limit code ownership',
          'accessibility'=>'Core parts of your UI may not be accessible',
          'trust_page'=>'Clients do not yet have a public readiness page',
        ];
        return $map[(string)($check['check_key'] ?? '')] ?? (string)($check['title'] ?? 'Readiness issue');
    }

    public static function presentDescription(array $check): string {
        if (($check['status'] ?? '') !== 'need_attention') return (string)($check['description'] ?? '');
        $map=[
          'security_headers'=>'The public response does not prove the browser protections expected for a production app.',
          'no_exposed_secrets'=>'A URL/source scan found credential-like material that requires manual confirmation and removal if real.',
          'mixed_content'=>'At least one insecure asset appears to be referenced from an HTTPS page.',
          'cookie_security'=>'The response did not prove the expected Secure, HttpOnly and SameSite cookie protections.',
          'database_exposure'=>'Public evidence contains a database connection/service-role pattern that should never be exposed.',
          'spam_protection'=>'A public form is visible without a clear anti-abuse control in the inspected evidence.',
          'backups'=>'SIUGOALS does not have evidence that automatic, recoverable database backups are enabled.',
          'role_separation'=>'Current evidence does not prove that users are restricted to the permissions they actually need.',
          'cost_cap'=>'Current evidence does not prove a spending or usage ceiling that limits unexpected production costs.',
          'source_control'=>'Current evidence does not prove that production code is version-controlled and safely reversible.',
          'privacy_policy'=>'No reliable privacy-policy evidence was found on the public application.',
          '404_recovery'=>'Invalid URLs do not yet have verified recovery behavior for visitors.',
          'performance'=>'Observed public-page response time is above the current production baseline.',
          'broken_links'=>'The safe same-origin crawl reached one or more linked pages that returned an error.',
          'seo_foundation'=>'At least one inspected public page is missing a title, meta description, or a clear single H1.',
        ];
        return $map[(string)($check['check_key'] ?? '')] ?? (string)($check['description'] ?? '');
    }
}
