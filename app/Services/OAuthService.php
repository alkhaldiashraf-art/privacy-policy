<?php
namespace App\Services;
use App\Core\Env;
use App\Core\Util;

final class OAuthService {
    public static function configured(string $provider): bool {
        return match($provider){
            'google'=>(bool)Env::get('GOOGLE_OAUTH_CLIENT_ID') && (bool)Env::get('GOOGLE_OAUTH_CLIENT_SECRET'),
            'github'=>(bool)Env::get('GITHUB_OAUTH_CLIENT_ID') && (bool)Env::get('GITHUB_OAUTH_CLIENT_SECRET'),
            default=>false,
        };
    }
    public static function authorizationUrl(string $provider,string $state): string {
        if(!self::configured($provider)) throw new \RuntimeException('OAuth provider is not configured.');
        if($provider==='github') return 'https://github.com/login/oauth/authorize?'.http_build_query([
            'client_id'=>Env::get('GITHUB_OAUTH_CLIENT_ID'),'redirect_uri'=>Util::baseUrl().'/oauth/github/callback','scope'=>'read:user user:email','state'=>$state,
        ]);
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id'=>Env::get('GOOGLE_OAUTH_CLIENT_ID'),'redirect_uri'=>Util::baseUrl().'/oauth/google/callback','response_type'=>'code','scope'=>'openid email profile','state'=>$state,'access_type'=>'online','prompt'=>'select_account',
        ]);
    }
    public static function profile(string $provider,string $code): array {
        if($provider==='github'){
            $token=self::request('https://github.com/login/oauth/access_token',[
                'client_id'=>Env::get('GITHUB_OAUTH_CLIENT_ID'),'client_secret'=>Env::get('GITHUB_OAUTH_CLIENT_SECRET'),'code'=>$code,'redirect_uri'=>Util::baseUrl().'/oauth/github/callback'
            ]);
            $access=(string)($token['access_token']??''); if($access==='') throw new \RuntimeException('GitHub did not return an access token.');
            $u=self::request('https://api.github.com/user',[],['Authorization: Bearer '.$access]);
            $emails=self::request('https://api.github.com/user/emails',[],['Authorization: Bearer '.$access]);
            $email=null; if(is_array($emails)){foreach($emails as $em){if(!empty($em['primary'])&&!empty($em['verified'])){$email=$em['email'];break;}}if(!$email)foreach($emails as $em){if(!empty($em['verified'])){$email=$em['email'];break;}}}
            return ['provider'=>'github','id'=>(string)($u['id']??''),'name'=>(string)($u['name']??$u['login']??'GitHub user'),'email'=>(string)$email,'email_verified'=>(bool)$email,'avatar'=>(string)($u['avatar_url']??'')];
        }
        $token=self::request('https://oauth2.googleapis.com/token',[
            'client_id'=>Env::get('GOOGLE_OAUTH_CLIENT_ID'),'client_secret'=>Env::get('GOOGLE_OAUTH_CLIENT_SECRET'),'code'=>$code,'grant_type'=>'authorization_code','redirect_uri'=>Util::baseUrl().'/oauth/google/callback'
        ]);
        $access=(string)($token['access_token']??''); if($access==='') throw new \RuntimeException('Google did not return an access token.');
        $u=self::request('https://openidconnect.googleapis.com/v1/userinfo',[],['Authorization: Bearer '.$access]);
        return ['provider'=>'google','id'=>(string)($u['sub']??''),'name'=>(string)($u['name']??'Google user'),'email'=>(string)($u['email']??''),'email_verified'=>!empty($u['email_verified']),'avatar'=>(string)($u['picture']??'')];
    }
    private static function request(string $url,array $post=[],array $headers=[]): array {
        if(!function_exists('curl_init')) throw new \RuntimeException('PHP cURL extension is required for OAuth.');
        $ch=curl_init($url);$h=array_merge(['Accept: application/json','User-Agent: SIUGOALS'],$headers);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>$h,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
        if($post){curl_setopt($ch,CURLOPT_POST,true);curl_setopt($ch,CURLOPT_POSTFIELDS,http_build_query($post));}
        $raw=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$err=curl_error($ch);curl_close($ch);if($err||$code>=400)throw new \RuntimeException('OAuth provider request failed.');$j=json_decode((string)$raw,true);return is_array($j)?$j:[];
    }
}
