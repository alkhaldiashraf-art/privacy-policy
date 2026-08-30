<?php
namespace App\Services;

/**
 * Small, same-origin, read-only public crawler used as AI evidence.
 * It performs GET requests only, never submits forms, strips query strings,
 * avoids action-like/auth callback/API paths, and returns sanitized summaries.
 */
final class PublicSurfaceService {
    public static function crawl(string $startUrl,int $maxPages=8,int $maxChars=160000): array {
        $maxPages=max(1,min(12,$maxPages));$maxChars=max(20_000,min(240_000,$maxChars));
        $first=SafeHttp::request($startUrl,false,10,4,'SIUGOALS-Public-Surface/5.5');
        $effective=self::canonical((string)$first['url']);$origin=self::origin($effective);
        $queue=[$effective];$seen=[];$pages=[];$failures=[];$used=0;$firstResponse=$first;$linksChecked=0;
        while($queue && count($pages)<$maxPages && $used<$maxChars){
            $url=array_shift($queue);if(isset($seen[$url]))continue;$seen[$url]=true;
            try{
                $r=($url===$effective && $firstResponse)?$firstResponse:SafeHttp::request($url,false,9,3,'SIUGOALS-Public-Surface/5.5');
                $firstResponse=null;$status=(int)($r['status']??0);$final=self::canonical((string)($r['url']??$url));$linksChecked++;
                if(self::origin($final)!==$origin)continue;
                if($status<200 || $status>=400){$failures[]=['url'=>$url,'effective_url'=>$final,'status'=>$status,'error'=>'HTTP '.$status];continue;}
                $body=(string)($r['body']??'');if($body==='')continue;
                $page=self::summarize($final,$body,$status,(int)($r['response_ms']??0),max(2000,min(18000,$maxChars-$used)));
                $encoded=json_encode($page,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)?:'';
                if($used+strlen($encoded)>$maxChars && $pages)break;
                $pages[]=$page;$used+=strlen($encoded);
                foreach(self::links($body,$final,$origin) as $link){if(!isset($seen[$link]) && !in_array($link,$queue,true))$queue[]=$link;if(count($queue)>40)break;}
            }catch(\Throwable $e){$linksChecked++;$failures[]=['url'=>$url,'effective_url'=>$url,'status'=>0,'error'=>substr($e->getMessage(),0,300)];continue;}
        }
        return ['origin'=>$origin,'pages'=>$pages,'pages_scanned'=>count($pages),'links_checked'=>$linksChecked,'failures'=>array_slice($failures,0,40),'characters'=>min($used,$maxChars),'max_pages'=>$maxPages,'read_only'=>true];
    }

    private static function summarize(string $url,string $body,int $status,int $responseMs,int $budget): array {
        $sanitized=preg_replace('/<input\b([^>]*?)\bvalue\s*=\s*(["\']).*?\2([^>]*)>/is','<input$1 value="[REDACTED]"$3>',$body)??$body;
        $sanitized=preg_replace('#<(script|style|noscript|svg)\b[^>]*>.*?</\1>#is',' ',$sanitized)??$sanitized;
        preg_match('/<title\b[^>]*>(.*?)<\/title>/is',$sanitized,$tm);$title=self::clean((string)($tm[1]??''),240);
        preg_match('/<html\b[^>]*\blang\s*=\s*(["\'])([^"\']+)\1/i',$sanitized,$lm);$lang=self::clean((string)($lm[2]??''),30);
        preg_match('/<meta\b[^>]*\bname\s*=\s*(["\'])description\1[^>]*\bcontent\s*=\s*(["\'])(.*?)\2/i',$sanitized,$dm);
        if(empty($dm[3]))preg_match('/<meta\b[^>]*\bcontent\s*=\s*(["\'])(.*?)\1[^>]*\bname\s*=\s*(["\'])description\3/i',$sanitized,$dm2);
        $metaDescription=self::clean((string)($dm[3]??$dm2[2]??''),320);
        preg_match('/<link\b[^>]*\brel\s*=\s*(["\'])canonical\1[^>]*\bhref\s*=\s*(["\'])(.*?)\2/i',$sanitized,$cm);
        if(empty($cm[3]))preg_match('/<link\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1[^>]*\brel\s*=\s*(["\'])canonical\3/i',$sanitized,$cm2);
        $canonical=self::clean((string)($cm[3]??$cm2[2]??''),500);
        $hasViewport=(bool)preg_match('/<meta\b[^>]*\bname\s*=\s*(["\'])viewport\1/i',$sanitized);
        $forms=preg_match_all('/<form\b/i',$sanitized,$x);$inputs=preg_match_all('/<(input|select|textarea)\b/i',$sanitized,$x);
        $buttons=preg_match_all('/<button\b/i',$sanitized,$x);$images=preg_match_all('/<img\b/i',$sanitized,$x);
        $missingAlt=preg_match_all('/<img\b(?![^>]*\balt\s*=)[^>]*>/i',$sanitized,$x);
        $passwords=preg_match_all('/<input\b[^>]*\btype\s*=\s*(["\'])password\1/i',$sanitized,$x);
        $headings=[];$h1Count=preg_match_all('/<h1\b/i',$sanitized,$x);if(preg_match_all('/<h([1-3])\b[^>]*>(.*?)<\/h\1>/is',$sanitized,$hm,PREG_SET_ORDER)){foreach(array_slice($hm,0,15) as $h)$headings[]=self::clean(strip_tags((string)$h[2]),180);}
        $missingLabels=0;if(preg_match_all('/<(input|select|textarea)\b([^>]*)>/is',$sanitized,$controls,PREG_SET_ORDER|PREG_OFFSET_CAPTURE)){foreach($controls as $control){
            $attrs=(string)($control[2][0]??'');$offset=(int)($control[0][1]??0);
            if(preg_match('/\btype\s*=\s*(["\']?)(hidden|submit|button|reset|image)\1/i',$attrs))continue;
            if(preg_match('/\b(?:aria-label|aria-labelledby)\s*=/i',$attrs))continue;
            $id='';if(preg_match('/\bid\s*=\s*(["\'])([^"\']+)\1/i',$attrs,$im))$id=(string)$im[2];
            if($id!==''&&preg_match('/<label\b[^>]*\bfor\s*=\s*(["\'])'.preg_quote($id,'/').'\1/i',$sanitized))continue;
            // A control wrapped by a <label> is labelled even without a `for` value.
            $before=substr($sanitized,max(0,$offset-1200),min(1200,$offset));
            $open=strripos($before,'<label');$close=strripos($before,'</label>');
            if($open!==false&&($close===false||$open>$close))continue;
            $missingLabels++;
        }}
        $formMeta=[];if(preg_match_all('/<form\b([^>]*)>/is',$sanitized,$fm,PREG_SET_ORDER)){foreach(array_slice($fm,0,12) as $f){$attrs=(string)$f[1];preg_match('/\bmethod\s*=\s*(["\'])([^"\']+)\1/i',$attrs,$mm);preg_match('/\baction\s*=\s*(["\'])([^"\']+)\1/i',$attrs,$am);$formMeta[]=['method'=>strtoupper((string)($mm[2]??'GET')),'action'=>self::safePath((string)($am[2]??''))];}}
        $text=html_entity_decode(strip_tags($sanitized),ENT_QUOTES|ENT_HTML5,'UTF-8');$text=preg_replace('/\s+/u',' ',trim($text))??'';$text=self::redact($text);if(strlen($text)>$budget)$text=substr($text,0,$budget).' …';
        return ['url'=>$url,'status'=>$status,'response_ms'=>$responseMs,'title'=>$title,'meta_description'=>$metaDescription,'canonical'=>$canonical,'lang'=>$lang,'has_viewport'=>$hasViewport,'h1_count'=>$h1Count,'headings'=>array_values(array_filter($headings)),'forms'=>$forms,'form_metadata'=>$formMeta,'inputs'=>$inputs,'password_inputs'=>$passwords,'form_controls_missing_label'=>$missingLabels,'buttons'=>$buttons,'images'=>$images,'images_missing_alt'=>$missingAlt,'visible_text'=>$text];
    }

    private static function links(string $body,string $base,string $origin): array {
        if(!preg_match_all('/<a\b[^>]*href\s*=\s*(["\'])(.*?)\1/is',$body,$m,PREG_SET_ORDER))return [];$out=[];
        foreach($m as $match){$href=html_entity_decode(trim((string)$match[2]),ENT_QUOTES|ENT_HTML5,'UTF-8');$u=self::resolve($base,$href);if(!$u)continue;$u=self::canonical($u);if(self::origin($u)!==$origin || self::unsafePath($u))continue;$out[$u]=true;if(count($out)>=30)break;}return array_keys($out);
    }

    private static function unsafePath(string $url): bool {
        $path=strtolower((string)(parse_url($url,PHP_URL_PATH)?:'/'));
        if(preg_match('/\.(?:zip|gz|tar|7z|pdf|docx?|xlsx?|pptx?|jpg|jpeg|png|gif|webp|svg|mp4|mp3|woff2?|ttf|ico|css|js|xml|json)$/i',$path))return true;
        foreach(['/logout','/signout','/delete','/remove','/destroy','/unsubscribe','/api/','/webhook','/callback','/oauth/','/auth/callback','/cart/','/checkout','/purchase','/confirm','/verify','/activate','/deactivate','/accept','/reject','/invite','/join','/leave','/follow','/vote','/reset-password','/admin/'] as $bad)if(str_contains($path,$bad))return true;
        return false;
    }

    private static function resolve(string $base,string $href): ?string {
        $href=trim($href);$lower=strtolower($href);if($href===''||str_starts_with($href,'#')||str_starts_with($lower,'javascript:')||str_starts_with($lower,'mailto:')||str_starts_with($lower,'tel:'))return null;
        if(preg_match('#^https?://#i',$href))return $href;$b=parse_url($base);if(!$b)return null;$origin=$b['scheme'].'://'.$b['host'].(isset($b['port'])?':'.$b['port']:'');if(str_starts_with($href,'//'))return $b['scheme'].':'.$href;if(str_starts_with($href,'/'))return $origin.$href;$path=(string)($b['path']??'/');$dir=preg_replace('#/[^/]*$#','/',$path)?:'/';$parts=[];foreach(explode('/',$dir.$href) as $part){if($part===''||$part==='.')continue;if($part==='..')array_pop($parts);else$parts[]=$part;}return $origin.'/'.implode('/',$parts);
    }

    private static function canonical(string $url): string {$u=parse_url($url);if(!$u||empty($u['scheme'])||empty($u['host']))return $url;$path=(string)($u['path']??'/');return strtolower((string)$u['scheme']).'://'.strtolower((string)$u['host']).(isset($u['port'])?':'.$u['port']:'').($path===''?'/':$path);}
    private static function origin(string $url): string {$u=parse_url($url);return strtolower((string)($u['scheme']??'')).'://'.strtolower((string)($u['host']??'')).(isset($u['port'])?':'.$u['port']:'');}
    private static function clean(string $s,int $max): string {$s=preg_replace('/\s+/u',' ',trim(html_entity_decode(strip_tags($s),ENT_QUOTES|ENT_HTML5,'UTF-8')))??'';return substr(self::redact($s),0,$max);}
    private static function safePath(string $value): string {if($value==='')return '';$u=parse_url($value);return (string)($u['path']??$value);}
    private static function redact(string $text): string {
        $text=preg_replace('/\bsk-[A-Za-z0-9_-]{16,}\b/','[REDACTED_KEY]',$text)??$text;
        $text=preg_replace('/\bAKIA[0-9A-Z]{16}\b/','[REDACTED_KEY]',$text)??$text;
        $text=preg_replace('/AIza[0-9A-Za-z\-_]{30,}/','[REDACTED_KEY]',$text)??$text;
        $text=preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/-]{12,}/i','Bearer [REDACTED]',$text)??$text;
        $text=preg_replace('/\beyJ[A-Za-z0-9_-]{12,}\.[A-Za-z0-9_-]{8,}(?:\.[A-Za-z0-9_-]{8,})?\b/','[REDACTED_TOKEN]',$text)??$text;
        $text=preg_replace('/((?:postgres(?:ql)?|mysql|mongodb(?:\+srv)?)\:\/\/[^:\s\/]+:)[^@\s\/]+@/i','$1[REDACTED]@',$text)??$text;
        $text=preg_replace('/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----.*?-----END (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/is','[REDACTED_PRIVATE_KEY]',$text)??$text;
        $text=preg_replace('/([?&](?:token|key|code|secret|password|pass|auth|session|signature)=)[^\s&#]+/i','$1[REDACTED]',$text)??$text;
        $text=preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i','[REDACTED_EMAIL]',$text)??$text;
        return $text;
    }
}
