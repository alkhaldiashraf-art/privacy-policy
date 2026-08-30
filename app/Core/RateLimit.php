<?php
namespace App\Core;
final class RateLimit {
    public static function check(string $key,int $limit,int $seconds): void {
        $bucket=hash('sha256',$key.'|'.Util::hashIp(Util::ip()));$now=time();$retry=0;$blocked=false;
        DB::tx(function()use($bucket,$now,$limit,$seconds,&$retry,&$blocked){
            $driver=(string)DB::pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);$lock=$driver==='mysql'?' FOR UPDATE':'';
            $r=DB::one('SELECT * FROM rate_limits WHERE bucket_key=?'.$lock,[$bucket]);
            if(!$r){try{DB::exec('INSERT INTO rate_limits(bucket_key,count,window_started_at) VALUES(?,1,?)',[$bucket,$now]);}catch(\Throwable $e){$r=DB::one('SELECT * FROM rate_limits WHERE bucket_key=?'.$lock,[$bucket]);if(!$r)throw $e;}if(!$r)return;}
            if($now-(int)$r['window_started_at']>=$seconds){DB::exec('UPDATE rate_limits SET count=1,window_started_at=? WHERE bucket_key=?',[$now,$bucket]);return;}
            if((int)$r['count'] >= $limit){$blocked=true;$retry=max(1,$seconds-($now-(int)$r['window_started_at']));return;}
            DB::exec('UPDATE rate_limits SET count=count+1 WHERE bucket_key=?',[$bucket]);
        });
        if($blocked){header('Retry-After: '.$retry);Util::json(['ok'=>false,'error'=>'Too many requests. Try again later.'],429);}
    }
}
