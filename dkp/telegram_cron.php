<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');
require __DIR__.'/auth.php';require __DIR__.'/dkp_store.php';require __DIR__.'/telegram_store.php';require __DIR__.'/telegram_api.php';
$key=false;$success=false;$check=($argv[1]??'')==='--check';
try {
    $cfg=require __DIR__.'/config.php';$bot=is_file(__DIR__.'/telegram_config.php')?require __DIR__.'/telegram_config.php':[];
    $origin=rtrim($cfg['origin'],'/');if(!preg_match('~^https://[a-z0-9.-]+$~iD',$origin))throw new RuntimeException('Invalid origin');
    $db=new PDO($cfg['dsn'],$cfg['db_user'],$cfg['db_password'],[PDO::ATTR_TIMEOUT=>5]);$auth=new Auth($db,$origin,static fn()=>false);$tg=new TelegramGuild($auth,$bot,$origin);
    if(!$tg->enabled())throw new RuntimeException('Telegram is not configured');
    $api=new TelegramApi($tg->apiToken());$deadline=microtime(true)+50;
    $key=$tg->acquire();if($key===false){echo "Telegram worker already running\n";exit(0);}
    $me=$api->call('getMe');if(($me['ok']??false)!==true || empty($me['result']['is_bot']) || strcasecmp($me['result']['username']??'',$tg->username())!==0)throw new RuntimeException('Bot token or username check failed');
    $tg->identify($key,(string)$me['result']['id']);
    $hook=$api->call('getWebhookInfo');if(($hook['ok']??false)!==true || !empty($hook['result']['url']))throw new RuntimeException('Bot webhook must be absent; use a dedicated bot');
    if($check) {
        foreach(['fc_tg_tokens','fc_tg_subscriptions','fc_tg_batches','fc_tg_deliveries'] as $table)$auth->query('SELECT * FROM '.$table.' LIMIT 0');
        echo "Telegram identity and database OK. No messages sent.\n";$success=true;}
    else {
        $updates=$api->call('getUpdates',['offset'=>(int)$tg->state()['update_offset'],'timeout'=>1,'limit'=>20,'allowed_updates'=>['message']]);
        if(($updates['ok']??false)!==true || !is_array($updates['result']??null))throw new RuntimeException('Cannot receive bot updates');
        $processed=0;$sent=0;
        foreach($updates['result'] as $update){
            if(microtime(true)>$deadline)break;
            $reply=$tg->update($key,$update);$processed++;
            if($reply && (int)$tg->state()['cooldown_until']<=time()){$api->call('sendMessage',$reply+['link_preview_options'=>['is_disabled'=>true]]);usleep(100000);}
        }
        // Drain command backlogs before notifications, so /stop gets priority.
        if(count($updates['result'])<20 && $processed===count($updates['result']))for($i=0;$i<25 && microtime(true)<$deadline;$i++){
            $job=$tg->claim($key);if(!$job)break;if(isset($job['skipped']))continue;
            $response=$api->call('sendMessage',['chat_id'=>(string)$job['chat_id'],'text'=>$job['message_text'],'link_preview_options'=>['is_disabled'=>true]]);
            $tg->result($key,$job,$response);if(($response['ok']??false)===true)$sent++;usleep(100000);
        }
        echo 'Telegram OK: updates='.$processed.', sent='.$sent."\n";$success=true;
    }
}catch(Throwable $e){fwrite(STDERR,'Telegram job failed: '.get_class($e).'. Check settings, database and Telegram connectivity.' ."\n");}
finally{if($key!==false && isset($tg))try{$tg->release($key,$success && !$check);}catch(Throwable $e){$success=false;}}
exit($success?0:1);
