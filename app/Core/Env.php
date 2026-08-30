<?php
namespace App\Core;
final class Env {
    private static array $data=[];
    public static function load(string $file): void {
        if (!is_file($file)) throw new \RuntimeException('Missing SIUGOALS configuration file: config/config.php');
        $cfg=require $file;
        if (!is_array($cfg)) throw new \RuntimeException('Invalid SIUGOALS configuration file.');
        self::$data=$cfg;
    }
    public static function get(string $key, mixed $default=null): mixed {
        if (array_key_exists($key,self::$data)) return self::$data[$key];
        $v=getenv($key);
        return $v!==false ? $v : $default;
    }
    public static function bool(string $key, bool $default=false): bool { return filter_var(self::get($key,$default),FILTER_VALIDATE_BOOLEAN); }
}
