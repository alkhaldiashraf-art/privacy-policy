<?php
namespace App\Services;
use App\Core\Env;
final class GitHubService {
    private static function cfg(bool $maintenance=false): array {
        $p=$maintenance?'GITHUB_MAINT_APP_':'GITHUB_APP_';
        return ['id'=>(string)Env::get($p.'ID',''),'slug'=>(string)Env::get($p.'SLUG',''),'key'=>(string)Env::get($p.'PRIVATE_KEY_BASE64','')];
    }
    public static function configured(bool $maintenance=false): bool { $c=self::cfg($maintenance); return $c['id']!==''&&$c['slug']!==''&&$c['key']!==''; }
    public static function installUrl(string $state,bool $maintenance=false): string { $c=self::cfg($maintenance); if(!self::configured($maintenance)) throw new \RuntimeException('GitHub App is not configured.'); return 'https://github.com/apps/'.rawurlencode($c['slug']).'/installations/new?'.http_build_query(['state'=>$state]); }
    private static function jwt(bool $maintenance=false): string {
        $c=self::cfg($maintenance); $key=base64_decode($c['key'],true); if(!$key) throw new \RuntimeException('Invalid GitHub App private key configuration.');
        $enc=fn($v)=>rtrim(strtr(base64_encode($v),'+/','-_'),'=');$header=$enc(json_encode(['alg'=>'RS256','typ'=>'JWT']));$now=time();$payload=$enc(json_encode(['iat'=>$now-60,'exp'=>$now+540,'iss'=>$c['id']]));$data=$header.'.'.$payload;$sig='';if(!openssl_sign($data,$sig,$key,OPENSSL_ALGO_SHA256))throw new \RuntimeException('Could not sign GitHub App JWT.');return $data.'.'.$enc($sig);
    }
    private static function api(string $method,string $path,?string $token=null,array $body=[],bool $appJwt=false,bool $maintenance=false): array {
        $ch=curl_init('https://api.github.com'.$path);$auth=$appJwt?self::jwt($maintenance):$token;$headers=['Accept: application/vnd.github+json','User-Agent: SIUGOALS','X-GitHub-Api-Version: 2022-11-28'];if($auth)$headers[]='Authorization: Bearer '.$auth;curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>45,CURLOPT_HTTPHEADER=>$headers]);if($method==='POST'){curl_setopt($ch,CURLOPT_POST,true);curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body));$headers[]='Content-Type: application/json';curl_setopt($ch,CURLOPT_HTTPHEADER,$headers);}elseif($method!=='GET'){curl_setopt($ch,CURLOPT_CUSTOMREQUEST,$method);if($body){curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body));$headers[]='Content-Type: application/json';curl_setopt($ch,CURLOPT_HTTPHEADER,$headers);}}$raw=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$err=curl_error($ch);curl_close($ch);if($err||$code>=400){$j=json_decode((string)$raw,true);throw new \RuntimeException($j['message']??$err?:('GitHub API error '.$code));}return json_decode((string)$raw,true)?:[];
    }
    public static function installationToken(int $installationId,bool $maintenance=false): string { $j=self::api('POST','/app/installations/'.$installationId.'/access_tokens',null,[],true,$maintenance); if(empty($j['token']))throw new \RuntimeException('GitHub installation token was not returned.'); return (string)$j['token']; }
    public static function repos(int $installationId): array { $token=self::installationToken($installationId,false); $j=self::api('GET','/installation/repositories?per_page=100',$token); return $j['repositories']??[]; }
    public static function fileContent(int $installationId,string $repo,string $path): array {
        $token=self::installationToken($installationId,false);$safe=str_replace('%2F','/',rawurlencode(ltrim($path,'/')));$j=self::api('GET','/repos/'.$repo.'/contents/'.$safe,$token);if(($j['type']??'')!=='file'||empty($j['content']))throw new \RuntimeException('GitHub source file could not be read.');$content=base64_decode(str_replace("\n",'',(string)$j['content']),true);if($content===false)throw new \RuntimeException('GitHub returned invalid file content.');return ['content'=>$content,'sha'=>(string)($j['sha']??''),'size'=>(int)($j['size']??strlen($content))];
    }
    public static function downloadZip(int $installationId,string $repo,string $destination): void {
        $token=self::installationToken($installationId,false);$url='https://api.github.com/repos/'.$repo.'/zipball';$fh=fopen($destination,'wb');if(!$fh)throw new \RuntimeException('Could not create temporary repository archive.');
        $bytes=0;$tooLarge=false;$ch=curl_init($url);curl_setopt_array($ch,[
            CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>5,CURLOPT_TIMEOUT=>90,CURLOPT_CONNECTTIMEOUT=>10,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER=>['Accept: application/vnd.github+json','Authorization: Bearer '.$token,'User-Agent: SIUGOALS','X-GitHub-Api-Version: 2022-11-28'],
            CURLOPT_WRITEFUNCTION=>static function($curl,string $chunk)use($fh,&$bytes,&$tooLarge):int{$length=strlen($chunk);if($bytes+$length>ZipScanner::MAX_ARCHIVE){$tooLarge=true;return 0;}$written=fwrite($fh,$chunk);if($written===false)return 0;$bytes+=$written;return $written;},
        ]);curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$err=curl_error($ch);curl_close($ch);fclose($fh);
        if($tooLarge||$code>=400||$err){@unlink($destination);if($tooLarge)throw new \RuntimeException('GitHub source archive exceeds the 100 MB scan limit.');throw new \RuntimeException('Could not download repository source.');}
    }
    public static function createTextFilePR(int $installationId,string $repo,string $path,string $newContent,string $message): string {
        $token=self::installationToken($installationId,true);$repoInfo=self::api('GET','/repos/'.$repo,$token);$base=$repoInfo['default_branch']??'main';$ref=self::api('GET','/repos/'.$repo.'/git/ref/heads/'.rawurlencode($base),$token);$sha=$ref['object']['sha']??null;if(!$sha)throw new \RuntimeException('Could not resolve base branch.');$branch='siugoals/fix-'.gmdate('Ymd-His').'-'.substr(bin2hex(random_bytes(3)),0,5);self::api('POST','/repos/'.$repo.'/git/refs',$token,['ref'=>'refs/heads/'.$branch,'sha'=>$sha]);$existing=null;try{$existing=self::api('GET','/repos/'.$repo.'/contents/'.str_replace('%2F','/',rawurlencode($path)).'?ref='.rawurlencode($branch),$token);}catch(\Throwable $e){}$payload=['message'=>$message,'content'=>base64_encode($newContent),'branch'=>$branch];if(!empty($existing['sha']))$payload['sha']=$existing['sha'];self::api('PUT','/repos/'.$repo.'/contents/'.str_replace('%2F','/',rawurlencode($path)),$token,$payload);$pr=self::api('POST','/repos/'.$repo.'/pulls',$token,['title'=>$message,'head'=>$branch,'base'=>$base,'body'=>'Prepared by SIUGOALS after explicit Maintenance Mode authorization. Review before merging.']);return (string)($pr['html_url']??'');
    }
}
