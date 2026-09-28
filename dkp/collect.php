<?php
declare(strict_types=1);
ini_set('display_errors','0');
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
header('Content-Type: text/plain; charset=utf-8');
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);header('Allow: POST');exit;}
if(($_SERVER['HTTP_DNT']??'')==='1'||($_SERVER['HTTP_SEC_GPC']??'')==='1'){http_response_code(204);exit;}
if((int)($_SERVER['CONTENT_LENGTH']??0)>8192){http_response_code(413);exit;}
if(strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0]))!=='application/json'){http_response_code(415);exit;}
// No CORS: only accept the page's own origin. Do not trust forwarded headers.
$secure=in_array($_SERVER['HTTPS']??'',['on','1'],true);
$expected=($secure?'https':'http').'://'.($_SERVER['HTTP_HOST']??'');
if(($_SERVER['HTTP_ORIGIN']??'')!==$expected || !in_array($_SERVER['HTTP_SEC_FETCH_SITE']??'same-origin',['same-origin','none'],true)){http_response_code(403);exit;}
require_once __DIR__.'/metrics_catalog.php';
try {
    $raw=file_get_contents('php://input',false,null,0,8193);
    if($raw===false||strlen($raw)>8192){http_response_code(413);exit;}
    $batch=fc_metrics_batch(json_decode($raw,true,16,JSON_THROW_ON_ERROR));
} catch(JsonException|InvalidArgumentException $e){http_response_code(400);exit;}
try {
    $config=require __DIR__.'/config.php';
    $db=new PDO($config['dsn'],$config['db_user'],$config['db_password'],[PDO::ATTR_TIMEOUT=>3]);
    require_once __DIR__.'/auth.php';
    $auth=new Auth($db,rtrim($config['origin'],'/'),static fn()=>false);
    // Separate budget from login/form limits. Only a temporary hash is retained.
    try{$auth->rate('metrics-ip:'.($_SERVER['REMOTE_ADDR']??'unknown'),90,60);}
    catch(AuthError $e){http_response_code(429);header('Retry-After: 60');exit;}
    require_once __DIR__.'/metrics_store.php';
    $metrics=new SiteMetrics($db);$metrics->collect($batch);
    if(random_int(1,100)===1)try{$metrics->cleanup();}catch(Throwable $e){error_log('Site metrics cleanup: '.get_class($e));}
    http_response_code(204);
} catch(Throwable $e){http_response_code(503);error_log('Site metrics collection: '.get_class($e));}
