<?php
namespace App\Core;
final class Csrf {
    public static function token(): string { return $_SESSION['_csrf'] ??= bin2hex(random_bytes(24)); }
    public static function input(): string { return '<input type="hidden" name="_csrf" value="'.htmlspecialchars(self::token(),ENT_QUOTES).'">'; }
    public static function verify(): void { $t=(string)($_POST['_csrf']??($_SERVER['HTTP_X_CSRF_TOKEN']??'')); if(!$t||!hash_equals(self::token(),$t)){http_response_code(419);exit('CSRF validation failed');} }
}
