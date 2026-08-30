<?php
namespace App\Core;
final class Auth {
    public static function user(): ?array {
        if (empty($_SESSION['uid'])) return null;
        return DB::one('SELECT id,email,name,token_balance,bonus_tokens,token_cycle_allocation,token_reset_at,referral_code,locale,created_at FROM users WHERE id=?',[(int)$_SESSION['uid']]);
    }
    public static function id(): ?int { return isset($_SESSION['uid'])?(int)$_SESSION['uid']:null; }
    public static function attempt(string $email,string $password): bool {
        $u=DB::one('SELECT * FROM users WHERE lower(email)=lower(?)',[$email]);
        if(!$u || !password_verify($password,$u['password_hash'])) return false;
        session_regenerate_id(true); $_SESSION['uid']=(int)$u['id']; $_SESSION['locale']=in_array(($u['locale']??'en'),['en','ar'],true)?$u['locale']:'en';
        DB::exec('UPDATE users SET last_login_at=CURRENT_TIMESTAMP WHERE id=?',[$u['id']]); return true;
    }
    public static function login(int $id): void { session_regenerate_id(true); $_SESSION['uid']=$id; $u=DB::one('SELECT locale FROM users WHERE id=?',[$id]); $_SESSION['locale']=in_array(($u['locale']??'en'),['en','ar'],true)?$u['locale']:'en'; }
    public static function logout(): void { $_SESSION=[]; if(ini_get('session.use_cookies')){$p=session_get_cookie_params();setcookie(session_name(),'',time()-42000,$p['path'],$p['domain'],$p['secure'],$p['httponly']);} session_destroy(); }
    public static function requireUser(): array { $u=self::user(); if(!$u){ header('Location: /login'); exit; } return $u; }

    /** Platform-level administrator, separate from per-project roles. */
    public static function isPlatformAdmin(?int $userId=null): bool {
        $uid=$userId??self::id();
        if(!$uid)return false;
        try { return (bool)DB::one('SELECT user_id FROM platform_admins WHERE user_id=? LIMIT 1',[$uid]); }
        catch(\Throwable $e) { return false; }
    }

    public static function requirePlatformAdmin(): array {
        $u=self::requireUser();
        if(!self::isPlatformAdmin((int)$u['id'])){http_response_code(403);exit('Forbidden');}
        return $u;
    }

    public static function canProject(int $projectId,string $role='viewer'): bool {
        $uid=self::id(); if(!$uid)return false;
        // Platform administrators may inspect and administer every project, but owner-only
        // destructive/write-authority actions remain reserved for the actual project owner.
        if(self::isPlatformAdmin($uid) && $role!=='owner')return true;
        $r=DB::one('SELECT role FROM project_members WHERE project_id=? AND user_id=?',[$projectId,$uid]);
        if(!$r)return false; $rank=['viewer'=>1,'developer'=>2,'admin'=>3,'owner'=>4]; return ($rank[$r['role']]??0)>=($rank[$role]??1);
    }
}
