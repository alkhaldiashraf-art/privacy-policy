<?php
namespace App\Services;
use App\Core\Env;
final class CryptoService {
    private static function key(): string {
        $appKey=(string)Env::get('APP_KEY','');
        if(strlen($appKey)<64) throw new \RuntimeException('APP_KEY is not configured; refusing to derive an encryption key from a default value.');
        return hash('sha256',$appKey,true);
    }
    public static function enc(string $plain): string { $iv=random_bytes(12);$tag='';$ct=openssl_encrypt($plain,'aes-256-gcm',self::key(),OPENSSL_RAW_DATA,$iv,$tag);return base64_encode($iv.$tag.$ct); }
    public static function dec(?string $enc): ?string { if(!$enc)return null;$b=base64_decode($enc,true);if($b===false||strlen($b)<28)return null;$iv=substr($b,0,12);$tag=substr($b,12,16);$ct=substr($b,28);$p=openssl_decrypt($ct,'aes-256-gcm',self::key(),OPENSSL_RAW_DATA,$iv,$tag);return $p===false?null:$p; }
}
