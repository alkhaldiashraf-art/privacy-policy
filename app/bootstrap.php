<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log',__DIR__.'/../storage/logs/php-error.log');
require __DIR__.'/Core/Env.php';
require __DIR__.'/Core/DB.php';
require __DIR__.'/Core/Auth.php';
require __DIR__.'/Core/Csrf.php';
require __DIR__.'/Core/Util.php';
require __DIR__.'/Core/View.php';
require __DIR__.'/Core/RateLimit.php';
require __DIR__.'/Core/I18n.php';
foreach (glob(__DIR__.'/Services/*.php') as $f) require_once $f;
\App\Core\Env::load(__DIR__.'/../config/config.php');
$appKey=(string)\App\Core\Env::get('APP_KEY','');
if(strlen($appKey)<64 || str_starts_with($appKey,'CHANGE_THIS')){http_response_code(500);exit('SIUGOALS is not configured. Set a unique APP_KEY (64+ characters) in config/config.php.');}
$dbName=(string)\App\Core\Env::get('DB_DATABASE','');$dbUser=(string)\App\Core\Env::get('DB_USERNAME','');
if($dbName===''||$dbUser===''||str_starts_with($dbName,'YOUR_')||str_starts_with($dbUser,'YOUR_')){http_response_code(500);exit('SIUGOALS database is not configured. Edit config/config.php.');}
$secure=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off');
$requestPath=parse_url((string)($_SERVER['REQUEST_URI']??'/'),PHP_URL_PATH)?:'/';
// Runtime ingestion/config and public SDK modules are intentionally stateless.
// Do not emit a SIUGOALS login-session cookie into monitored applications.
$statelessRuntime=str_starts_with($requestPath,'/api/runtime/') || $requestPath==='/api/access/check' || in_array($requestPath,['/sdk/launchkit.js','/sdk/runtime.js'],true);
if(!$statelessRuntime){
    session_name('siugoals_session');
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']);
    if(session_status()!==PHP_SESSION_ACTIVE) session_start();
}
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: SAMEORIGIN');
if($secure) header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
header("Content-Security-Policy: default-src 'self'; base-uri 'self'; frame-ancestors 'self'; object-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; img-src 'self' data:; font-src 'self' https://fonts.gstatic.com; connect-src 'self' https:; form-action 'self' https://checkout.stripe.com; upgrade-insecure-requests");
set_exception_handler(function(Throwable $e){ error_log((string)$e); if(str_starts_with($_SERVER['REQUEST_URI']??'','/api/')) \App\Core\Util::json(['ok'=>false,'error'=>'Unexpected server error'],500); http_response_code(500); echo '<h1>Something went wrong</h1><p>Please try again.</p>'; });
