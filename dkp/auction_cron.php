<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');
require __DIR__.'/auth.php';require __DIR__.'/dkp_store.php';
try {
    $config=require __DIR__.'/config.php';
    $db=new PDO($config['dsn'],$config['db_user'],$config['db_password'],[PDO::ATTR_TIMEOUT=>5]);
    $auth=new Auth($db,rtrim($config['origin'],'/'),static fn()=>false);$dkp=new DKP($auth);
} catch(Throwable $e) {
    fwrite(STDERR,'Scheduler connection failed: '.get_class($e).' code='.$e->getCode()."\n");exit(1);
}
$check=($argv[1]??'')==='--check';$failed=false;
// Independent jobs: failure of CW creation must not stop auction settlement.
try {
    if($check) {
        $auth->query('SELECT id FROM fc_auctions LIMIT 1');$auth->query('SELECT id FROM fc_scheduler WHERE id=1');
        echo "Auction scheduler configuration OK. No balances changed.\n";
    } else {
        $count=$dkp->settleDue();
        $auth->query('UPDATE fc_scheduler SET last_success=? WHERE id=1',[time()]);
        echo gmdate('c')." OK: settled $count auction(s).\n";
    }
} catch(Throwable $e) {
    $failed=true;fwrite(STDERR,'Auction scheduler failed: '.get_class($e).' code='.$e->getCode()."\n");
}
try {
    require_once __DIR__.'/scheduled_events.php';
    $schedule=new ScheduledEvents($auth,require __DIR__.'/cw_schedule.php');
    if($check) {
        $schedule->check();echo "CW scheduler configuration OK. No events created.\n";
    } else {
        $count=$schedule->run();echo gmdate('c')." OK: created $count CW event(s).\n";
    }
} catch(Throwable $e) {
    $failed=true;fwrite(STDERR,'CW scheduler failed: '.get_class($e).' code='.$e->getCode()."\n");
}
exit($failed?1:0);
