<?php
declare(strict_types=1);
/** Anonymous, aggregated public buyer activity. Never accepts customer data. */
header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'");
header('Content-Type: application/json; charset=utf-8');
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);header('Allow: POST');echo '{"ok":false}';exit;}
if((empty($_SERVER['HTTPS'])||$_SERVER['HTTPS']==='off')&&($_SERVER['HTTP_X_FORWARDED_PROTO']??'')!=='https'){
    http_response_code(403);echo '{"ok":false}';exit;
}
if(!in_array((string)($_SERVER['HTTP_ORIGIN']??''),['https://aurenoeva.com','https://www.aurenoeva.com'],true)
    ||strtolower(trim(explode(';',(string)($_SERVER['CONTENT_TYPE']??''),2)[0]))!=='application/json'
    ||(int)($_SERVER['CONTENT_LENGTH']??0)>96){
    http_response_code(403);echo '{"ok":false}';exit;
}
try{
    $payload=json_decode((string)file_get_contents('php://input',false,null,0,97),true,8,JSON_THROW_ON_ERROR);
    if(!is_array($payload)||!isset($payload['event'])||count($payload)!==1)
        throw new InvalidArgumentException('invalid_event');
    require_once __DIR__.'/commercial_intelligence.php';
    aur_ci_event(aur_store_root(),(string)$payload['event']);
    echo '{"ok":true}';
}catch(Throwable $e){
    error_log('AURENOEVA buyer activity: '.get_class($e));
    http_response_code($e instanceof InvalidArgumentException?400:503);
    echo '{"ok":false}';
}
