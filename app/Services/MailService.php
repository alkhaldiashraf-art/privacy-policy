<?php
namespace App\Services;
use App\Core\Env;
final class MailService {
    public static function configured(): bool { return Env::bool('MAIL_ENABLED',false) && filter_var((string)Env::get('MAIL_FROM_EMAIL',''),FILTER_VALIDATE_EMAIL); }
    public static function send(string $to,string $subject,string $html): bool {
        if(!self::configured() || !filter_var($to,FILTER_VALIDATE_EMAIL)) return false;
        if(preg_match('/[\r\n]/',$subject)) return false;
        $from=(string)Env::get('MAIL_FROM_EMAIL'); $name=(string)Env::get('MAIL_FROM_NAME','SIUGOALS');
        $headers=['MIME-Version: 1.0','Content-Type: text/html; charset=UTF-8','From: '.$name.' <'.$from.'>','Reply-To: '.$from,'X-Mailer: SIUGOALS'];
        return @mail($to,$subject,$html,implode("\r\n",$headers));
    }
}
