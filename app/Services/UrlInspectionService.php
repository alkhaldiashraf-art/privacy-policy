<?php
namespace App\Services;

/**
 * Evidence-first public URL inspection used by both the free Production Audit
 * and authenticated Readiness URL scans.
 *
 * The service deliberately limits itself to non-destructive public HTTP(S)
 * requests. It never submits forms, authenticates, brute-forces endpoints or
 * attempts to access private networks.
 */
final class UrlInspectionService {
    public static function discover(string $url): array {
        $r = SafeHttp::request($url, false, 12, 5, 'SIUGOALS-Discovery/2.0');
        return self::detect((string)$r['body'], (array)$r['headers'], (string)$r['url']);
    }

    public static function inspect(string $url): array {
        $main = SafeHttp::request($url, false, 12, 5, 'SIUGOALS-URL-Inspection/2.0');
        $effective = (string)$main['url'];
        $body = (string)$main['body'];
        $headers = (array)$main['headers'];
        $lower = strtolower($body);
        $status = (int)$main['status'];
        $origin = self::origin($effective);
        $detected = self::detect($body, $headers, $effective);

        // A WAF/CDN challenge page is evidence about the scanner path, not about the
        // underlying application. Never score the challenge HTML as if it were the app.
        $blocked = self::blockedResponse($status,$body,$headers);
        if($blocked){
            $https=str_starts_with(strtolower($effective),'https://');
            $reason='Automated inspection was blocked or challenged by the target edge/WAF (HTTP '.$status.').';
            $checks=[
                ['key'=>'https','label'=>'HTTPS certificate','state'=>$https?'verified':'attention','message'=>$https?'The challenged endpoint is delivered over HTTPS.':'The challenged endpoint is not delivered over HTTPS.','weight'=>14,'area'=>'Security','severity'=>'critical','confidence'=>1.0,'meta'=>['effective_url'=>$effective]],
            ];
            foreach([
                ['security_headers','Browser security headers',11,'Security','high'],
                ['no_exposed_secrets','Exposed secrets',13,'Security','critical'],
                ['mixed_content','Mixed-content protection',5,'Security','medium'],
                ['cookie_security','Session-cookie protection',5,'Security','medium'],
                ['reachability','Production reachability',8,'Operations','critical'],
                ['404_recovery','Error handling and broken-link recovery',10,'Operations','high'],
                ['spam_protection','Spam protection',7,'Security','medium'],
                ['privacy_policy','Privacy policy',9,'Compliance','high'],
                ['terms','Terms of service',6,'Compliance','medium'],
                ['monitoring','Uptime / error monitoring',7,'Operations','high'],
                ['database_exposure','Database access exposure',10,'Security','critical'],
            ] as [$key,$label,$weight,$area,$severity]){
                $checks[]=['key'=>$key,'label'=>$label,'state'=>'unknown','message'=>$reason.' This check was not scored.','weight'=>$weight,'area'=>$area,'severity'=>$severity,'confidence'=>0.0,'meta'=>['blocked'=>true,'status'=>$status]];
            }
            return [
                'url'=>$url,'effective_url'=>$effective,'status'=>$status,'response_ms'=>(int)$main['response_ms'],
                'headers'=>$headers,'detected'=>$detected,'checks'=>$checks,
                'meta'=>['blocked'=>true,'blocked_reason'=>$reason,'invalid_path_status'=>null,'forms_detected'=>0,'antibot_detected'=>false],
            ];
        }

        $invalid = null;
        try {
            $invalid = SafeHttp::request(
                $origin . '/__siugoals_missing_' . bin2hex(random_bytes(6)),
                false,
                10,
                3,
                'SIUGOALS-Recovery-Check/2.0'
            );
        } catch (\Throwable $e) {
            $invalid = ['status'=>0,'body'=>'','headers'=>[],'url'=>'','response_ms'=>0,'error'=>$e->getMessage()];
        }

        $checks = [];
        $add = static function(
            string $key,
            string $label,
            string $state,
            string $message,
            int $weight,
            string $area,
            string $severity='medium',
            float $confidence=1.0,
            array $meta=[]
        ) use (&$checks): void {
            $checks[] = compact('key','label','state','message','weight','area','severity','confidence','meta');
        };

        $https = str_starts_with(strtolower($effective), 'https://');
        $add('https','HTTPS certificate',$https?'verified':'attention',
            $https?'The final page is delivered over HTTPS.':'The final page is not delivered over HTTPS.',
            14,'Security','critical',1.0,['effective_url'=>$effective]);

        $baselineHeaders = ['content-security-policy','x-content-type-options'];
        if($https)$baselineHeaders[]='strict-transport-security';
        $present = array_values(array_filter($baselineHeaders, static fn($h)=>isset($headers[$h]) && trim((string)$headers[$h])!==''));
        $missing = array_values(array_diff($baselineHeaders, $present));
        $headerState = count($missing)===0 ? 'verified' : 'attention';
        $add('security_headers','Browser security headers',$headerState,
            $headerState==='verified'
                ? 'Detected ' . implode(', ', $present) . '.'
                : 'Missing or unverified baseline headers: ' . implode(', ', $missing ?: $baselineHeaders) . '.',
            11,'Security','high',0.95,['present'=>$present,'missing'=>$missing]);

        $secretPatterns = [
            'AWS access key'=>'/AKIA[0-9A-Z]{16}/',
            'OpenAI-style secret'=>'/sk-[A-Za-z0-9_-]{20,}/',
            'Google API key'=>'/AIza[0-9A-Za-z\-_]{30,}/',
            'Private key'=>'/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/',
            'Supabase service role'=>'/(?:service[_-]?role|SUPABASE_SERVICE_ROLE)[^\n\r]{0,80}/i',
        ];
        $leaks=[];
        foreach($secretPatterns as $name=>$pattern){ if(preg_match($pattern,$body)) $leaks[]=$name; }
        $add('no_exposed_secrets','Exposed secrets',$leaks?'attention':'verified',
            $leaks?'Credential-like public content detected: '.implode(', ',$leaks).'.':'No common private credential pattern was found in the public HTML.',
            13,'Security','critical',$leaks?0.95:0.72,['patterns'=>$leaks]);

        $mixed = $https && (bool)preg_match('/(?:src|href)=["\']http:\/\//i',$body);
        $add('mixed_content','Mixed-content protection',$mixed?'attention':'verified',
            $mixed?'The HTTPS page references at least one insecure HTTP asset.':'No obvious HTTP asset reference was found in the HTTPS document.',
            5,'Security','medium',0.75);

        $cookie = (string)($headers['set-cookie']??'');
        if($cookie===''){
            $add('cookie_security','Session-cookie protection','unknown','No Set-Cookie header was returned by this public page, so authenticated session-cookie behavior was not observable.',4,'Security','low',0.35);
        } else {
            $observed=array_values(array_filter(array_map('trim',preg_split('/\r\n|\n|\r/',$cookie)?:[])));
            $sessions=[];foreach($observed as $line){$name=strtolower(trim((string)strtok($line,'=')));if(preg_match('/(?:session|sessid|jsessionid|phpsessid|connect\.sid|auth|login|jwt|token)/i',$name))$sessions[]=['name'=>substr($name,0,80),'attrs'=>strtolower((string)(strstr($line,';')?:''))];}
            if(!$sessions){
                $add('cookie_security','Session-cookie protection','unknown','Cookies were observed, but no clearly session-like cookie was identifiable on this public response.',4,'Security','low',0.45,['cookies_observed'=>count($observed)]);
            }else{
                $weak=[];foreach($sessions as $session){$missing=[];$attrs=$session['attrs'];if($https&&!preg_match('/(?:^|;)\s*secure(?:;|$)/i',$attrs))$missing[]='Secure';if(!preg_match('/(?:^|;)\s*httponly(?:;|$)/i',$attrs))$missing[]='HttpOnly';if(!preg_match('/(?:^|;)\s*samesite\s*=/i',$attrs))$missing[]='SameSite';if($missing)$weak[]=$session['name'].' missing '.implode('/',$missing);}
                $ok=!$weak;
                $add('cookie_security','Session-cookie protection',$ok?'verified':'attention',
                    $ok?'Every observed session-like cookie included the baseline Secure, HttpOnly, and SameSite flags.':'Observed session-cookie protection needs attention: '.implode('; ',array_slice($weak,0,5)).'.',
                    5,'Security','medium',0.9,['session_cookies'=>array_column($sessions,'name'),'weak'=>array_slice($weak,0,10)]);
            }
        }

        $reachable = $status>=200 && $status<400;
        $add('reachability','Production reachability',$reachable?'verified':'attention','HTTP status '.$status.' returned in '.(int)$main['response_ms'].' ms.',8,'Operations','critical',1.0,['status'=>$status,'response_ms'=>(int)$main['response_ms']]);

        $invalidStatus=(int)($invalid['status']??0);
        $invalidBody=strtolower((string)($invalid['body']??''));
        $helpful = (bool)preg_match('/\b(404|not found|page not found|go back|return home|homepage|doesn.t exist)\b/i',$invalidBody);
        $sameAsHome = $invalidStatus>=200 && $invalidStatus<300 && self::similarBody($body,(string)($invalid['body']??''));
        if($invalidStatus===0){
            $recoveryState='unknown';$recoveryMessage='The invalid-path recovery check could not be completed.';
        } elseif(in_array($invalidStatus,[404,410],true) || $helpful){
            $recoveryState='verified';$recoveryMessage='An invalid URL returns a dedicated recovery response (HTTP '.$invalidStatus.').';
        } elseif($sameAsHome){
            $recoveryState='attention';$recoveryMessage='Invalid URLs appear to return the normal app page instead of a clear recovery response.';
        } else {
            $recoveryState='unknown';$recoveryMessage='Invalid-path behavior was reachable but the recovery experience could not be confidently classified.';
        }
        $add('404_recovery','Error handling and broken-link recovery',$recoveryState,$recoveryMessage,10,'Operations','high',$invalidStatus?0.9:0.4,['status'=>$invalidStatus]);

        $forms = preg_match_all('/<form\b/i',$body,$dummy);
        $antiBot = (bool)preg_match('/recaptcha|hcaptcha|turnstile|cf-turnstile|captcha/i',$body);
        if(!$forms){
            $add('spam_protection','Spam protection','unknown','No form was visible on the inspected landing page. Other public routes may still contain forms, so this was not scored.',6,'Security','medium',0.35);
        } else {
            $add('spam_protection','Spam protection',$antiBot?'verified':'attention',
                $antiBot?'A public anti-bot control was detected on a form.':'Public forms were detected, but no common CAPTCHA/Turnstile control was visible in the page source.',
                7,'Security','medium',$antiBot?0.9:0.6,['forms'=>$forms]);
        }

        $privacyLink=self::findLink($body,$effective,'privacy');
        $privacyReachable=$privacyLink?self::publicPageReachable($privacyLink):null;
        $privacyText=str_contains($lower,'privacy policy');
        $hasPrivacy=$privacyText || $privacyReachable===true;
        $privacyMessage=$privacyReachable===true
            ?'A privacy-policy link was found and returned a public success response.'
            :($privacyText?'Clear privacy-policy content was detected on the inspected page.':'No reachable public privacy-policy evidence was found.');
        $add('privacy_policy','Privacy policy',$hasPrivacy?'verified':'attention',$privacyMessage,
            9,'Compliance','high',$privacyReachable===true?0.94:($privacyText?0.75:0.82),['url'=>$privacyLink]);

        $termsLink=self::findLink($body,$effective,'terms');
        $termsReachable=$termsLink?self::publicPageReachable($termsLink):null;
        $termsText=str_contains($lower,'terms of service') || str_contains($lower,'terms & conditions');
        $hasTerms=$termsText || $termsReachable===true;
        $termsMessage=$termsReachable===true
            ?'A terms-of-service link was found and returned a public success response.'
            :($termsText?'Clear terms-related content was detected on the inspected page.':'No reachable public terms-of-service evidence was found.');
        $add('terms','Terms of service',$hasTerms?'verified':'attention',$termsMessage,
            6,'Compliance','medium',$termsReachable===true?0.92:($termsText?0.72:0.78),['url'=>$termsLink]);

        $monitoringNeedles=['sentry','logrocket','datadog','newrelic','new relic','bugsnag','rollbar','betteruptime','better uptime','statuspage','pingdom'];
        $monitoring=[];
        foreach($monitoringNeedles as $needle) if(str_contains($lower,$needle)) $monitoring[]=$needle;
        if($monitoring){
            $add('monitoring','Uptime / error monitoring','verified','Monitoring-related public integration evidence detected: '.implode(', ',array_unique($monitoring)).'.',7,'Operations','high',0.72,['clues'=>array_values(array_unique($monitoring))]);
        } else {
            $add('monitoring','Uptime / error monitoring','unknown','Monitoring cannot be reliably proven from the public page alone.',7,'Operations','high',0.35);
        }

        $dbDanger=[];
        if(preg_match('/service[_-]?role/i',$body)) $dbDanger[]='service-role marker';
        if(preg_match('/(?:postgres(?:ql)?:\/\/|mysql:\/\/)[^\s"\']+/i',$body)) $dbDanger[]='database connection string';
        $add('database_exposure','Database access exposure',$dbDanger?'attention':'verified',
            $dbDanger?'Sensitive database-access material may be public: '.implode(', ',$dbDanger).'.':'No obvious database admin credential or connection string was found in public HTML.',
            10,'Security','critical',$dbDanger?0.95:0.62,['clues'=>$dbDanger]);

        return [
            'url'=>$url,
            'effective_url'=>$effective,
            'status'=>$status,
            'response_ms'=>(int)$main['response_ms'],
            'headers'=>$headers,
            'detected'=>$detected,
            'checks'=>$checks,
            'meta'=>[
                'invalid_path_status'=>$invalidStatus,
                'forms_detected'=>(int)$forms,
                'antibot_detected'=>$antiBot,
            ],
        ];
    }

    public static function detect(string $body,array $headers,string $effectiveUrl): array {
        $text=strtolower($body.' '.json_encode($headers));
        $host=strtolower((string)parse_url($effectiveUrl,PHP_URL_HOST));
        $detected=[
            'builder'=>'', 'framework'=>'', 'hosting'=>'', 'database'=>'', 'ai_provider'=>'', 'monitoring'=>[]
        ];
        $map=[
            'builder'=>[
                'lovable'=>'Lovable','bolt.new'=>'Bolt','stackblitz'=>'Bolt','base44'=>'Base44','replit'=>'Replit','v0.dev'=>'v0','claude code'=>'Claude Code','cursor'=>'Cursor'
            ],
            'framework'=>[
                '/_next/'=>'Next.js','next/static'=>'Next.js','__next_data__'=>'Next.js','vite'=>'Vite','wp-content'=>'WordPress','nuxt'=>'Nuxt','react'=>'React','vue'=>'Vue','angular'=>'Angular'
            ],
            'database'=>[
                'supabase'=>'Supabase','firebase'=>'Firebase','firestore'=>'Firebase','appwrite'=>'Appwrite','neon.tech'=>'Neon','planetscale'=>'PlanetScale','mongodb'=>'MongoDB'
            ],
            'ai_provider'=>[
                'api.openai.com'=>'OpenAI','openai'=>'OpenAI','anthropic'=>'Claude','claude'=>'Claude','generativelanguage.googleapis.com'=>'Gemini','gemini'=>'Gemini','deepseek'=>'DeepSeek'
            ],
        ];
        foreach($map as $kind=>$needles){ foreach($needles as $needle=>$label){ if(str_contains($text,strtolower($needle))){$detected[$kind]=$label;break;} } }

        $hostingMap=[
            'Vercel'=>['vercel','x-vercel-id','.vercel.app'],
            'Netlify'=>['netlify','x-nf-request-id','.netlify.app'],
            'Cloudflare'=>['cloudflare','cf-ray','cf-cache-status'],
            'Render'=>['render.com','.onrender.com'],
            'Railway'=>['railway.app'],
            'Hostinger'=>['hstgr','hostinger'],
        ];
        foreach($hostingMap as $label=>$needles){
            foreach($needles as $needle){ if(str_contains($text,strtolower($needle))||str_contains($host,strtolower($needle))){$detected['hosting']=$label;break 2;} }
        }
        $monitoringNeedles=['Sentry'=>'sentry','LogRocket'=>'logrocket','Datadog'=>'datadog','New Relic'=>'newrelic','Bugsnag'=>'bugsnag','Rollbar'=>'rollbar'];
        foreach($monitoringNeedles as $label=>$needle) if(str_contains($text,$needle)) $detected['monitoring'][]=$label;
        $detected['monitoring']=array_values(array_unique($detected['monitoring']));
        return $detected;
    }

    private static function origin(string $url): string {
        $u=parse_url($url); if(!$u||empty($u['scheme'])||empty($u['host'])) throw new \RuntimeException('Invalid inspected URL.');
        return strtolower((string)$u['scheme']).'://'.$u['host'].(isset($u['port'])?':'.$u['port']:'');
    }

    private static function findLink(string $body,string $base,string $needle): ?string {
        if(!preg_match_all('/<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is',$body,$m,PREG_SET_ORDER))return null;
        foreach($m as $match){
            $href=html_entity_decode(trim((string)$match[1]),ENT_QUOTES|ENT_HTML5,'UTF-8');
            $label=strtolower(strip_tags((string)$match[2]).' '.$href);
            if(!str_contains($label,strtolower($needle)))continue;
            if(preg_match('#^https?://#i',$href))return $href;
            if(str_starts_with($href,'//')){$scheme=(string)parse_url($base,PHP_URL_SCHEME);return $scheme.':'.$href;}
            if(str_starts_with($href,'/'))return self::origin($base).$href;
            if($href===''||str_starts_with($href,'#')||str_starts_with(strtolower($href),'javascript:'))continue;
            $path=(string)(parse_url($base,PHP_URL_PATH)?:'/');
            $dir=preg_replace('#/[^/]*$#','/',$path)?:'/';
            return self::origin($base).$dir.$href;
        }
        return null;
    }

    private static function publicPageReachable(string $url): ?bool {
        try{
            $r=SafeHttp::request($url,false,8,3,'SIUGOALS-Legal-Page/2.0');
            $status=(int)$r['status'];
            return $status>=200&&$status<400;
        }catch(\Throwable $e){return null;}
    }

    private static function blockedResponse(int $status,string $body,array $headers): bool {
        if(!in_array($status,[401,403,429,503],true)) return false;
        $hay=strtolower(substr($body,0,20000).' '.json_encode($headers));
        $clues=['checking your browser','just a moment','access denied','request blocked','verify you are human','captcha','cloudflare','cf-ray','attention required','bot protection','security challenge'];
        foreach($clues as $clue) if(str_contains($hay,$clue)) return true;
        // 401/403/429 from a public landing page is still not reliable application evidence.
        return in_array($status,[401,403,429],true);
    }

    private static function similarBody(string $a,string $b): bool {
        $norm=static function(string $s): string {
            $s=preg_replace('/\s+/',' ',strip_tags($s))??'';
            return substr(trim(strtolower($s)),0,5000);
        };
        $a=$norm($a);$b=$norm($b);if($a===''||$b==='')return false;
        similar_text($a,$b,$pct);
        return $pct>=88.0;
    }
}
