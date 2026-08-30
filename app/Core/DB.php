<?php
namespace App\Core;
use PDO;
final class DB {
    private static ?PDO $pdo=null;
    public static function pdo(): PDO {
        if (self::$pdo) return self::$pdo;
        $driver=(string)Env::get('DB_DRIVER','mysql');
        if ($driver==='sqlite') {
            $path=(string)Env::get('DB_DATABASE',__DIR__.'/../../storage/siugoals.sqlite');
            self::$pdo=new PDO('sqlite:'.$path);
        } else {
            $host=(string)Env::get('DB_HOST','127.0.0.1'); $port=(string)Env::get('DB_PORT','3306');
            $db=(string)Env::get('DB_DATABASE','siugoals'); $charset='utf8mb4';
            self::$pdo=new PDO("mysql:host={$host};port={$port};dbname={$db};charset={$charset}",(string)Env::get('DB_USERNAME',''),(string)Env::get('DB_PASSWORD',''));
        }
        self::$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
        if ($driver==='sqlite') self::$pdo->exec('PRAGMA foreign_keys = ON');
        return self::$pdo;
    }
    public static function all(string $sql,array $params=[]): array { $s=self::pdo()->prepare($sql); $s->execute($params); return $s->fetchAll(); }
    public static function one(string $sql,array $params=[]): ?array { $s=self::pdo()->prepare($sql); $s->execute($params); $r=$s->fetch(); return $r?:null; }
    public static function exec(string $sql,array $params=[]): bool { $s=self::pdo()->prepare($sql); return $s->execute($params); }
    public static function insert(string $sql,array $params=[]): string { self::exec($sql,$params); return self::pdo()->lastInsertId(); }
    public static function tx(callable $fn): mixed { $pdo=self::pdo(); $pdo->beginTransaction(); try{$r=$fn($pdo);$pdo->commit();return $r;}catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;} }
}
