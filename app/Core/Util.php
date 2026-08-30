<?php
namespace App\Core;
final class Util {
    public static function e(mixed $v): string { return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
    public static function slug(string $s): string { $s=strtolower(trim($s)); $s=preg_replace('/[^a-z0-9]+/','-',$s)?:'project'; return trim($s,'-').'-'.substr(bin2hex(random_bytes(3)),0,4); }
    public static function json(array $data,int $status=200): never { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); exit; }
    public static function redirect(string $to): never { header('Location: '.$to); exit; }
    public static function baseUrl(): string { return rtrim((string)Env::get('APP_URL',((!empty($_SERVER['HTTPS'])?'https':'http').'://'.($_SERVER['HTTP_HOST']??'localhost'))),'/'); }
    public static function ip(): string { return $_SERVER['REMOTE_ADDR']??'0.0.0.0'; }
    public static function hashIp(string $ip): string {
        $appKey=(string)Env::get('APP_KEY','');
        if(strlen($appKey)<64) throw new \RuntimeException('APP_KEY is not configured; refusing to derive an HMAC key from a default value.');
        return hash_hmac('sha256',$ip,$appKey);
    }
}
