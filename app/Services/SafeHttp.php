<?php
namespace App\Services;

/**
 * Server-side HTTP client with SSRF and response-size protections.
 * - http/https only
 * - rejects credentials and non-standard ports
 * - resolves and pins a public IPv4 address for every hop
 * - follows redirects manually and re-validates every destination
 * - caps response headers/body to avoid memory amplification from hostile targets
 */
final class SafeHttp {
    public const MAX_BODY_BYTES=2_500_000;
    public const MAX_HEADER_BYTES=65_536;

    public static function request(string $url, bool $head=false, int $timeout=12, int $maxRedirects=5, string $userAgent='SIUGOALS/1.0'): array {
        if(!function_exists('curl_init')) throw new \RuntimeException('PHP cURL extension is required.');
        $current=$url;
        for($hop=0;$hop<=$maxRedirects;$hop++){
            $u=self::validate($current);
            $host=(string)$u['host'];
            $port=(int)($u['port']??($u['scheme']==='https'?443:80));
            $ip=self::publicIpv4($host);
            $body='';$headerText='';$bodyTooLarge=false;$headersTooLarge=false;
            $ch=curl_init($current);
            $resolve=$host.':'.$port.':'.$ip;
            curl_setopt_array($ch,[
                CURLOPT_RETURNTRANSFER=>false,
                CURLOPT_HEADER=>false,
                CURLOPT_FOLLOWLOCATION=>false,
                CURLOPT_TIMEOUT=>$timeout,
                CURLOPT_CONNECTTIMEOUT=>5,
                CURLOPT_USERAGENT=>$userAgent,
                CURLOPT_SSL_VERIFYPEER=>true,
                CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,
                CURLOPT_RESOLVE=>[$resolve],
                CURLOPT_NOBODY=>$head,
                CURLOPT_ENCODING=>'',
                CURLOPT_HEADERFUNCTION=>static function($curl,string $line) use (&$headerText,&$headersTooLarge): int {
                    if(strlen($headerText)+strlen($line)>self::MAX_HEADER_BYTES){$headersTooLarge=true;return 0;}
                    $headerText.=$line;return strlen($line);
                },
                CURLOPT_WRITEFUNCTION=>static function($curl,string $chunk) use (&$body,&$bodyTooLarge,$head): int {
                    if($head)return strlen($chunk);
                    if(strlen($body)+strlen($chunk)>self::MAX_BODY_BYTES){$bodyTooLarge=true;return 0;}
                    $body.=$chunk;return strlen($chunk);
                },
            ]);
            $started=microtime(true);
            $ok=curl_exec($ch);
            $elapsed=(int)round((microtime(true)-$started)*1000);
            $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
            $errNo=(int)curl_errno($ch);$err=(string)curl_error($ch);
            curl_close($ch);
            if($bodyTooLarge) throw new \RuntimeException('Remote response exceeded the SIUGOALS inspection size limit.');
            if($headersTooLarge) throw new \RuntimeException('Remote response headers exceeded the SIUGOALS inspection size limit.');
            if($ok===false || $errNo!==0) throw new \RuntimeException('Remote request failed: '.($err!==''?$err:'cURL error '.$errNo));
            $headers=self::parseHeaders($headerText);
            if($status>=300 && $status<400 && isset($headers['location'])){
                if($hop===$maxRedirects) throw new \RuntimeException('Too many redirects.');
                $current=self::resolveRedirect($current,$headers['location']);
                continue;
            }
            return ['url'=>$current,'status'=>$status,'headers'=>$headers,'body'=>$head?'':$body,'response_ms'=>$elapsed,'ip'=>$ip];
        }
        throw new \RuntimeException('Too many redirects.');
    }

    public static function validate(string $url): array {
        $u=parse_url($url);
        $scheme=strtolower((string)($u['scheme']??''));
        if(!$u || !in_array($scheme,['http','https'],true) || empty($u['host'])) throw new \InvalidArgumentException('Invalid public HTTP(S) URL.');
        if(isset($u['user'])||isset($u['pass'])) throw new \InvalidArgumentException('URLs with embedded credentials are not allowed.');
        if(isset($u['port']) && !in_array((int)$u['port'],[80,443],true)) throw new \InvalidArgumentException('Non-standard URL ports are not allowed.');
        self::publicIpv4((string)$u['host']);
        return $u;
    }

    private static function publicIpv4(string $host): string {
        if(filter_var($host,FILTER_VALIDATE_IP)){
            if(!filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) throw new \RuntimeException('Private or reserved network targets are not allowed.');
            return $host;
        }
        $ips=gethostbynamel($host)?:[];
        if(!$ips) throw new \RuntimeException('Host could not be resolved to a public IPv4 address.');
        foreach($ips as $ip){
            if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) throw new \RuntimeException('Private or reserved network targets are not allowed.');
        }
        return (string)$ips[0];
    }

    private static function parseHeaders(string $text): array {
        $blocks=preg_split("/\r\n\r\n|\n\n|\r\r/",trim($text))?:[];
        $last=(string)end($blocks);$out=[];
        foreach(preg_split('/\r\n|\n|\r/',$last)?:[] as $line){
            if(str_contains($line,':')){[$k,$v]=explode(':',$line,2);$k=strtolower(trim($k));$v=trim($v);$join=$k==='set-cookie'?"\n":', ';$out[$k]=isset($out[$k])?$out[$k].$join.$v:$v;}
        }
        return $out;
    }

    private static function resolveRedirect(string $base,string $location): string {
        $location=trim($location);
        if(preg_match('#^https?://#i',$location)) return $location;
        $b=parse_url($base); if(!$b) throw new \RuntimeException('Invalid redirect base URL.');
        $origin=$b['scheme'].'://'.$b['host'].(isset($b['port'])?':'.$b['port']:'');
        if(str_starts_with($location,'//')) return $b['scheme'].':'.$location;
        if(str_starts_with($location,'/')) return $origin.$location;
        $path=(string)($b['path']??'/');
        $dir=preg_replace('#/[^/]*$#','/',$path) ?: '/';
        $combined=$dir.$location;$parts=[];
        foreach(explode('/',$combined) as $part){if($part===''||$part==='.')continue;if($part==='..')array_pop($parts);else $parts[]=$part;}
        return $origin.'/'.implode('/',$parts);
    }
}
