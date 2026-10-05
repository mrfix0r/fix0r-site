<?php
declare(strict_types=1);
require_once __DIR__.'/i18n.php';
if (isset($_GET['lang']) && is_string($_GET['lang']) && in_array($_GET['lang'], ['ru', 'en'], true)) {
    setcookie('fc_language', fc_language(), ['expires'=>time()+31536000, 'path'=>'/', 'secure'=>(($_SERVER['HTTPS']??'')==='on' || ($_SERVER['HTTPS']??'')==='1'), 'httponly'=>false, 'samesite'=>'Lax']);
}
ini_set('display_errors','0');
header('Content-Type: text/html; charset=utf-8');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; img-src 'self'; font-src 'self'; script-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
header('Referrer-Policy: no-referrer'); header('X-Content-Type-Options: nosniff'); header('Cache-Control: no-store');
// Cheap rejection before database, session creation and password hashing.
$method=$_SERVER['REQUEST_METHOD']??'GET';
if(!in_array($method,['GET','HEAD','POST'],true)){http_response_code(405);header('Allow: GET, HEAD, POST');exit(t('Метод не поддерживается.'));}
if((int)($_SERVER['CONTENT_LENGTH']??0)>32768){http_response_code(413);exit(t('Слишком большой запрос.'));}
if($method==='POST')foreach($_POST as $value)if(!is_string($value)){http_response_code(400);exit(t('Некорректный запрос.'));}
require __DIR__.'/auth.php';
require __DIR__.'/remember_login.php';
require __DIR__.'/dkp_store.php';
require __DIR__.'/announcements_store.php';
require __DIR__.'/telegram_store.php';
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function go(string $page, string $message=''): never { if ($message) $_SESSION['flash']=$message; header('Location: /dkp/?page='.$page.'&lang='.fc_language(), true, 303); exit; }
function field(string $name,string $label,string $type='text',string $auto=''): void {
    echo '<label>'.h($label).'<input name="'.h($name).'" type="'.h($type).'" required maxlength="'.($type==='password'?'128':'254').'" autocomplete="'.h($auto).'"'.($type==='password'?' minlength="12"':'').'></label>';
}
function fc_login_session(array $user, ?string $rememberHash=null): void {
    session_regenerate_id(true);
    $_SESSION=['uid'=>(int)$user['id'],'version'=>(int)$user['session_version'],'born'=>time(),'last'=>time(),'csrf'=>bin2hex(random_bytes(32))];
    if ($rememberHash !== null) $_SESSION['remember_hash']=$rememberHash;
}
function fc_clear_login_session(): void {
    $_SESSION=[]; session_regenerate_id(true); $_SESSION['csrf']=bin2hex(random_bytes(32));
}
$ready=false; $error=''; $user=false;
try {
    if (!is_file(__DIR__.'/config.php')) throw new RuntimeException('Configuration missing');
    $config=require __DIR__.'/config.php';
    $origin=rtrim($config['origin'],'/');
    if (!preg_match('~^https://[a-z0-9.-]+$~iD',$origin)) throw new RuntimeException('Invalid origin');
    if (($_SERVER['HTTPS']??'') !== 'on' && ($_SERVER['HTTPS']??'') !== '1') { header('Location: '.$origin.'/dkp/',true,302); exit; }
    if (!filter_var($config['mail_from'], FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/',$config['mail_from'])) throw new RuntimeException('Invalid sender');
    $db=new PDO($config['dsn'],$config['db_user'],$config['db_password'],[PDO::ATTR_TIMEOUT=>5]);
    $auth=new Auth($db,$origin,static function(string $to,string $subject,string $body) use($config):bool {
        return mail($to,'=?UTF-8?B?'.base64_encode($subject).'?=',$body,['From'=>$config['mail_from'],'Content-Type'=>'text/plain; charset=UTF-8','MIME-Version'=>'1.0']);
    });
    // REMOTE_ADDR must be restored by the hosting proxy; never trust client headers.
    try {
        $ip=$_SERVER['REMOTE_ADDR']??'unknown';
        $auth->rate('request-ip:'.$ip,120,60);
        if($method==='POST')$auth->rate('post-burst-ip:'.$ip,30,60);
    } catch(AuthError $e) {
        http_response_code(429);header('Retry-After: 60');exit(t('Слишком много запросов. Подожди минуту и повтори.'));
    }
    ini_set('session.use_strict_mode','1'); ini_set('session.use_only_cookies','1');
    session_name('FC_DKP'); session_set_cookie_params(['lifetime'=>0,'path'=>'/dkp','secure'=>true,'httponly'=>true,'samesite'=>'Lax']); session_start();
    $_SESSION['csrf']??=bin2hex(random_bytes(32));
    $auth->query('SELECT id FROM dkp_users LIMIT 1');
    $remember=new RememberLogin($auth);
    if (isset($_SESSION['uid'])) {
        $user=$auth->user((int)$_SESSION['uid']);
        $valid=$user && $user['verified_at'] && (int)$user['session_version']===($_SESSION['version']??0);
        if ($valid && isset($_SESSION['remember_hash'])) {
            $grant=$remember->byHash($_SESSION['remember_hash']);
            $valid=$grant && (int)$grant['user']['id']===(int)$user['id'];
        }
        if (!$valid) {
            RememberLogin::cookie(null); fc_clear_login_session(); $user=false;
        } elseif (time()-($_SESSION['last']??0)>3600 || time()-($_SESSION['born']??0)>43200) {
            fc_clear_login_session(); $user=false;
        } else $_SESSION['last']=time();
    }
    if (!$user && isset($_COOKIE[RememberLogin::COOKIE])) {
        $grant=$remember->fromCookie($_COOKIE[RememberLogin::COOKIE]);
        if ($grant) {
            $user=$grant['user']; fc_login_session($user,$grant['hash']);
        } else RememberLogin::cookie(null);
    }
    $dkp=new DKP($auth);$announcements=new Announcements($auth);
    $tgConfig=is_file(__DIR__.'/telegram_config.php')?require __DIR__.'/telegram_config.php':[];
    $telegram=new TelegramGuild($auth,is_array($tgConfig)?$tgConfig:[],$origin);
    $auctionWarning='';
    if($user)try{$dkp->settleDue();}catch(Throwable $e){error_log('DKP settlement: '.get_class($e).' code='.$e->getCode());$auctionWarning=t('Не удалось завершить истёкшие аукционы. Ставки остаются в резерве. Сообщи администратору.');}
    $ready=true;
} catch(Throwable $e) { http_response_code(503); error_log('DKP initialization: '.get_class($e).' code='.$e->getCode().' driver='.($e instanceof PDOException ? ($e->errorInfo[1] ?? 'unknown') : 'n/a')); }
$page=is_string($_GET['page']??null)?$_GET['page']:($user?'profile':'login');
if (!in_array($page,['login','register','forgot','resend','verify','reset','profile','manage','events','auctions','announcements','metrics'],true)) $page='login';
if ($ready && isset($_GET['token']) && in_array($page,['verify','reset'],true)) {
    $token=$_GET['token'];
    if (is_string($token) && preg_match('/^[a-f0-9]{64}$/D',$token)) $_SESSION[$page.'_token']=$token;
    else unset($_SESSION[$page.'_token']);
    go($page);
}
if ($ready && $_SERVER['REQUEST_METHOD']==='POST') {
    try {
        if ((int)($_SERVER['CONTENT_LENGTH']??0)>32768) throw new AuthError(t('Слишком большой запрос.'));
        foreach ($_POST as $value) if (!is_string($value)) throw new AuthError(t('Некорректный запрос.'));
        if (!hash_equals($_SESSION['csrf'],$_POST['csrf']??'')) throw new AuthError(t('Сеанс формы истёк. Обнови страницу и повтори.'));
        $action=$_POST['action']??'';
        $auth->rate('post:'.($_SERVER['REMOTE_ADDR']??''),40);
        $email=$_POST['email']??''; $password=$_POST['password']??'';
        if (in_array($action,['register','forgot','resend'],true)) {
            $auth->rate('mail-ip:'.($_SERVER['REMOTE_ADDR']??''),10);
            $auth->rate('mail-email:'.Auth::email($email),3);
        }
        if ($action==='cw_schedule') {
            if (!$user) throw new AuthError(t('Войди в кабинет.'));
            require_once __DIR__.'/scheduled_events.php';
            $schedule=new ScheduledEvents($auth,require __DIR__.'/cw_schedule.php');
            $schedule->setEnabled((int)$user['id'],$_SESSION['version'],$_POST['enabled']??'',$_POST['revision']??'');
            go('events',($_POST['enabled']??'')==='1'?t('Автосоздание ЧВ включено. Пропущенные события создаваться не будут.'):t('Автосоздание ЧВ отключено. Уже созданные события сохранены.'));
        }
        if(in_array($action,['tg_link','tg_unlink','tg_notify'],true)) {
            if(!$user)throw new AuthError(t('Войди в кабинет.'));
            if($action==='tg_link'){
                $_SESSION['tg_link']=$telegram->startLink((int)$user['id'],$_SESSION['version']);
                go('profile',t('Ссылка на бота готова. Раскрой раздел Telegram, открой бота и нажми Start.'));
            }
            if($action==='tg_unlink'){
                $telegram->unlink((int)$user['id'],$_SESSION['version']);unset($_SESSION['tg_link']);go('profile',t('Telegram отвязан, подписка отключена.'));
            }
            if(($_POST['confirmed']??'')!=='1')throw new AuthError(t('Подтверди отправку подписавшимся участникам.'));
            $count=$telegram->queue((int)$user['id'],$_SESSION['version'],$_POST['id']??'',$_POST['revision']??'');
            go('announcements&announcement='.DKP::id($_POST['id']), t('В очередь добавлено сообщений: ').$count.'.');
        }
        if(str_starts_with($action,'ann_')) {
            if(!$user)throw new AuthError(t('Войди в кабинет.'));
            $kind=substr($action,4);
            if($kind==='archive' && ($_POST['confirmed']??'')!=='1')throw new AuthError(t('Подтверди удаление объявления.'));
            $payload=[];foreach(['id','revision','title','body','expires','pinned','requires_ack','reset_ack'] as $name)$payload[$name]=$_POST[$name]??'';
            $announcementId=$announcements->perform((int)$user['id'],$_SESSION['version'],$_POST['request_key']??'',$kind,$payload);
            go('announcements'.($kind==='archive'?'':'&announcement='.$announcementId),$kind==='ack'?t('Прочтение подтверждено.'):t('Объявление сохранено.'));
        }
        if (str_starts_with($action,'dkp_')) {
            if (!$user) throw new AuthError(t('Войди в кабинет.'));
            $kind=substr($action,4);
            if (in_array($kind,['archive','restore','adjust','link','evt_award','evt_cancel','auc_bid','auc_cancel'],true) && ($_POST['confirmed']??'')!=='1') throw new AuthError(t('Подтверди проверку данных.'));
            $payload=[];
            if ($kind==='adjust') {
                $ids=[]; foreach($_POST as $key=>$value) if(str_starts_with($key,'member_') && $value==='1') $ids[]=substr($key,7);
                sort($ids,SORT_STRING);$payload=['members'=>$ids,'amount'=>$_POST['amount']??'','reason'=>$_POST['reason']??''];
            } elseif($kind==='link') $payload=['member'=>$_POST['member']??'','account'=>$_POST['account']??''];
            elseif($kind==='role') $payload=['account'=>$_POST['account']??'','role'=>$_POST['role']??''];
            elseif(in_array($kind,['archive','restore'],true)) $payload=['member'=>$_POST['member']??'','reason'=>$_POST['reason']??''];
            elseif($kind==='create') $payload=['nickname'=>$_POST['nickname']??''];
            if(str_starts_with($kind,'evt_')) {
                $payload=['event'=>$_POST['event']??'','revision'=>$_POST['revision']??''];
                if(in_array($kind,['evt_create','evt_save'],true)) {
                    $payload+=['title'=>$_POST['title']??'','category'=>$_POST['category']??'','points'=>$_POST['points']??'','scheduled'=>$_POST['scheduled']??''];
                    if($kind==='evt_save') { $ids=[];foreach($_POST as $key=>$value)if(str_starts_with($key,'member_') && $value==='1')$ids[]=substr($key,7);sort($ids,SORT_STRING);$payload['members']=$ids; }
                }
                if($kind==='evt_cancel')$payload['reason']=$_POST['reason']??'';
            }
            if(str_starts_with($kind,'auc_')) {
                $payload=['auction'=>$_POST['auction']??'','revision'=>$_POST['revision']??''];
                if($kind==='auc_create')$payload=['item'=>$_POST['item']??'','minimum'=>$_POST['minimum']??'','step'=>$_POST['step']??'','minutes'=>$_POST['minutes']??''];
                if($kind==='auc_bid')$payload['amount']=$_POST['amount']??'';
                if($kind==='auc_cancel')$payload['reason']=$_POST['reason']??'';
            }
            $changed=$dkp->perform((int)$user['id'],$_SESSION['version'],$_POST['request_key']??'',$kind,$payload);
            if(str_starts_with($kind,'auc_'))go('auctions'.($kind!=='auc_create'?'&auction='.DKP::id($payload['auction']):''),$changed?($kind==='auc_bid'?t('Ставка принята. Очки зарезервированы.'):t('Аукцион сохранён.')):t('Это действие уже выполнено. Повторных изменений нет.'));
            if(str_starts_with($kind,'evt_'))go('events'.($kind!=='evt_create'?'&event='.DKP::id($payload['event']):''),$changed?match($kind){'evt_award'=>t('Награда начислена. Событие закрыто.'),'evt_join'=>t('Ты отмечен в событии. Награду выдаст офицер после проверки.'),'evt_leave'=>t('Твоя отметка участия снята.'),default=>t('Событие сохранено.')}:t('Это действие уже выполнено. Повторных изменений нет.'));
            go('manage',$changed?t('Изменения сохранены.'):t('Эта операция уже выполнена. Повторно ничего не изменено.'));
        }
        switch($action) {
            case 'register': $auth->register($email,$_POST['nickname']??'',$password,($_POST['new_member']??'')==='1'); go('login',t('Если регистрация доступна для этого email, письмо с подтверждением отправлено. Проверь также папку «Спам».'));
            case 'forgot': case 'resend': $auth->requestToken($email,$action==='forgot'?'reset':'verify'); go($action,t('Если для этого email доступно действие, письмо отправлено. Проверь также папку «Спам».'));
            case 'verify': case 'reset':
                $auth->redeem($_SESSION[$action.'_token']??'',$action,$password); unset($_SESSION[$action.'_token']); go('login',$action==='verify'?t('Email подтверждён. Теперь можно войти.'):t('Пароль изменён. Войди с новым паролем.'));
            case 'login':
                $auth->rate('login-ip:'.($_SERVER['REMOTE_ADDR']??''),20);
                $auth->rate('login:'.Auth::email($email),10); $u=$auth->login($email,$password);
                if (($_POST['remember']??'')==='1') {
                    $ticket=$remember->issue($u,$_COOKIE[RememberLogin::COOKIE]??null,$_SESSION['remember_hash']??null);
                    RememberLogin::cookie($ticket); fc_login_session($u,$ticket['hash']);
                } else {
                    $remember->revokeCookie($_COOKIE[RememberLogin::COOKIE]??null);
                    $remember->revokeHash($_SESSION['remember_hash']??null);
                    RememberLogin::cookie(null); fc_login_session($u);
                }
                go('profile');
            case 'logout':
                $remember->revokeCookie($_COOKIE[RememberLogin::COOKIE]??null);
                $remember->revokeHash($_SESSION['remember_hash']??null);
                RememberLogin::cookie(null); fc_clear_login_session(); go('login',t('Ты вышел из кабинета.'));
            case 'nickname':
                if (!$user) throw new AuthError(t('Войди в кабинет.'));
                $nick=trim($_POST['nickname']??''); if (!preg_match('/^[^\p{C}]{1,32}$/u',$nick)) throw new AuthError(t('Ник должен содержать от 1 до 32 символов.'));
                $auth->query('UPDATE dkp_users SET nickname=? WHERE id=?',[$nick,$user['id']]); go('profile',t('Игровой ник изменён.'));
            case 'password':
                if (!$user) throw new AuthError(t('Войди в кабинет.'));
                $auth->changePassword((int)$user['id'],$_POST['old_password']??'',$password); RememberLogin::cookie(null); fc_clear_login_session(); go('login',t('Пароль изменён. Все сеансы завершены. Войди заново.'));
            default: throw new AuthError(t('Неизвестное действие.'));
        }
    } catch(AuthError $e) { $error=$e->getMessage(); http_response_code(400); }
    catch(Throwable $e) { $error=t('Не удалось выполнить действие. Попробуй позже.'); http_response_code(503); error_log('DKP request: '.get_class($e)); }
}
if ($ready && $user && $page==='login' && $method==='GET') go('profile');
if ($ready && in_array($page,['profile','auctions','events','announcements'],true) && !$user) go('login');
if ($ready && $page==='manage' && (!$user || !in_array($user['role'],['admin','officer'],true))) go('profile');
if ($ready && $page==='metrics') {
    if (!$user) go('login');
    if ($user['role']!=='admin') {http_response_code(403);exit(t('Доступ только для администратора.'));}
}
$titles=['metrics'=>t('Статистика сайта'),'announcements'=>t('Объявления'),'auctions'=>t('Аукционы гильдии'),'events'=>t('События гильдии'),'manage'=>t('Управление ДКП'),'login'=>t('С возвращением'),'register'=>t('Присоединяйся к гильдии'),'forgot'=>t('Забыл пароль?'),'resend'=>t('Подтвердим почту'),'verify'=>t('Подтверждение email'),'reset'=>t('Новый пароль'),'profile'=>t('Личный кабинет')];
function dkpForm(string $kind):void { formStart('dkp_'.$kind);echo '<input type="hidden" name="request_key" value="'.bin2hex(random_bytes(32)).'">'; }
function formStart(string $action):void { echo '<form method="post"><input type="hidden" name="csrf" value="'.h($_SESSION['csrf']).'"><input type="hidden" name="action" value="'.h($action).'">'; }
?>
<!doctype html><html lang="<?=fc_language()?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title><?=h($titles[$page])?> · FC DKP</title><link rel="icon" href="/favicon.svg"><script src="/language.js?v=1"></script><script src="/theme.js?v=3"></script><link rel="stylesheet" href="/dkp/style.css?v=4.6.1"><link rel="stylesheet" href="/theme.css?v=4"><?php if($page==='profile'): ?><link rel="stylesheet" href="/dkp/profile-navigation.css?v=2"><?php endif; ?><link rel="stylesheet" href="/language.css?v=1"><?php if($page==='metrics'): ?><link rel="stylesheet" href="/metrics.css?v=1"><?php endif; ?><script src="/metrics.js?v=1" defer></script></head><body class="dkp-app" data-metrics-page="<?=h($ready && !in_array($page,['verify','reset','metrics'],true)?'dkp.'.$page:'')?>">
<header><a class="brand" href="<?=fc_language()==='en'?'/en/?lang=en':'/?lang=ru'?>">FC <span>TrustTheGame</span></a><div class="header-tools"><?php fc_language_switch(); ?><button class="theme-toggle" type="button" data-theme-toggle aria-label="<?=h(t('Тема «Лес и крем»'))?>" aria-pressed="false" hidden><span class="theme-swatch" aria-hidden="true"></span><span data-theme-label><?=h(t('Сумеречный лес'))?></span></button><a href="<?=fc_language()==='en'?'/en/?lang=en':'/?lang=ru'?>"><?=h(t('← На главную'))?></a></div></header>
<main<?= in_array($page,['manage','events','auctions','announcements','metrics'],true)?' class="management"':'' ?>><aside><div class="eyebrow">SLEEPINGFOREST / RF ONLINE</div><h1><?=h(t('Сила гильдии —'))?><br><?=h(t('в каждом из нас.'))?></h1><p><?=h(t('Место для твоего игрового профиля.'))?><br><?=h(t('Вход через собственный аккаунт сайта.'))?></p><div class="crest">FC</div><small><?=h(t('Собираемся вместе. Играем на доверии.'))?></small></aside>
<section class="card">
<?php if (!$ready): ?><div class="eyebrow">FC DKP</div><h2><?=h(t('Кабинет готовится к открытию'))?></h2><p><?=h(t('Администратору нужно завершить настройку сервера. Попробуй зайти позже.'))?></p><a href="<?=fc_language()==='en'?'/en/?lang=en':'/?lang=ru'?>"><?=h(t('Вернуться на главную →'))?></a>
<?php else: ?><?php if($page==='profile'): ?><h2 class="eyebrow"><?=h(t('ЛИЧНЫЙ КАБИНЕТ'))?></h2><?php else: ?><div class="eyebrow"><?=h(t('ЛИЧНЫЙ КАБИНЕТ'))?></div><h2><?=h($titles[$page])?></h2><?php endif; ?>
<?php if(isset($_SESSION['flash'])): ?><p class="notice" role="status"><?=h(t($_SESSION['flash']))?></p><?php unset($_SESSION['flash']); endif; ?>
<?php if($error): ?><p class="error" role="alert"><?=h(t($error))?></p><?php endif; ?>
<?php if($auctionWarning??''): ?><p class="error"><?=h($auctionWarning)?></p><?php endif; ?>
<?php if($page==='metrics'): ?>
<?php require __DIR__.'/metrics_view.php'; ?>
<?php elseif($page==='announcements'): ?>
<?php try { require __DIR__.'/announcements_view.php'; } catch(AuthError $e){echo '<p class="error">'.h(t($e->getMessage())).'</p>';} catch(Throwable $e){error_log('DKP announcements: '.get_class($e));echo ('<p class="error">'.h(t('Не удалось загрузить объявления. Проверь установку обновления базы.')).'</p>');} ?>
<?php elseif($page==='auctions'): ?>
<?php try { require __DIR__.'/auctions_view.php'; } catch(AuthError $e){echo '<p class="error">'.h(t($e->getMessage())).'</p>';} catch(Throwable $e){error_log('DKP auctions: '.get_class($e).' code='.$e->getCode());echo ('<p class="error">'.h(t('Не удалось загрузить аукционы. Сообщи администратору.')).'</p>');} ?>
<?php elseif($page==='events'): ?>
<?php try { require __DIR__.'/events_view.php'; } catch(AuthError $e) { echo '<p class="error">'.h(t($e->getMessage())).'</p>'; } catch(Throwable $e) { error_log('DKP events: '.get_class($e).' code='.$e->getCode()); echo ('<p class="error">'.h(t('События недоступны. Проверь установку обновления базы.')).'</p>'); } ?>
<?php elseif($page==='manage'): ?>
<?php try { require __DIR__.'/manage_view.php'; } catch(Throwable $e) { error_log('DKP management: '.get_class($e).' code='.$e->getCode()); echo ('<p class="error">'.h(t('Панель недоступна. Проверь установку обновления базы.')).'</p>'); } ?>
<?php elseif($page==='profile'): ?>
<?php require __DIR__.'/profile_overview.php'; ?>
<?php if($auth->query("SELECT web_user_id FROM fc_registration_members WHERE web_user_id=? AND status='conflict'",[$user['id']])->fetchColumn() && !$auth->query('SELECT member_id FROM fc_web_links WHERE web_user_id=?',[$user['id']])->fetchColumn()): ?><p role="status"><?=h(t('Email подтверждён, но такой игровой ник уже есть в составе. Новый профиль не создан. Попроси администратора проверить и привязать твой аккаунт.'))?></p><?php endif; ?>
<?php require __DIR__.'/migration_view.php'; ?>
<?php require __DIR__.'/telegram_profile.php'; ?>
<details><summary><?=h(t('Изменить игровой ник'))?></summary><?php formStart('nickname'); field('nickname',t('Новый ник'),'text','nickname'); ?><button><?=h(t('Сохранить ник'))?></button></form></details>
<details><summary><?=h(t('Изменить пароль'))?></summary><?php formStart('password'); field('old_password',t('Текущий пароль'),'password','current-password'); field('password',t('Новый пароль · от 12 символов'),'password','new-password'); ?><button><?=h(t('Изменить пароль'))?></button></form></details>
<?php formStart('logout'); ?><button class="secondary"><?=h(t('Выйти'))?></button></form>
<?php else:
formStart($page);
if(in_array($page,['login','register','forgot','resend'],true)) field('email','Email','email','email');
if($page==='register') {
    field('nickname',t('Игровой ник'),'text','nickname');
    echo '<label class="check"><input type="checkbox" name="new_member" value="1"'.(($_POST['new_member']??'')==='1'?' checked':'').('>'.h(t(' Новый участник гильдии')).'</label>'.'<small>'.h(t('После подтверждения email создадим и привяжем профиль ДКП с этим ником и нулевым балансом. Если ты уже есть в составе, оставь галочку пустой и попроси администратора привязать аккаунт.')).'</small>');
}
if(in_array($page,['login','register','verify','reset'],true)) field('password',in_array($page,['register','reset'],true)?t('Пароль · от 12 символов'):t('Пароль'),'password',in_array($page,['register','reset'],true)?'new-password':'current-password');
if($page==='login') {
    $checked=$method!=='POST' || ($_POST['remember']??'')==='1';
    echo '<label class="check"><input type="checkbox" name="remember" value="1"'.($checked?' checked':'').'>'.h(t('Запомнить меня на 30 дней')).'</label><small>'.h(t('На чужом или общем устройстве сними галочку.')).'</small>';
}
$buttons=['login'=>t('Войти в кабинет'),'register'=>t('Зарегистрироваться'),'forgot'=>t('Отправить ссылку'),'resend'=>t('Отправить письмо'),'verify'=>t('Подтвердить email'),'reset'=>t('Сохранить новый пароль')]; ?>
<button><?=h($buttons[$page])?> <span>→</span></button></form>
<?php if($page==='login'): ?><nav><a href="?page=forgot"><?=h(t('Забыл пароль?'))?></a><a href="?page=resend"><?=h(t('Повторить письмо'))?></a></nav><p class="foot"><?=h(t('Ещё нет аккаунта? '))?><a href="?page=register"><?=h(t('Регистрация'))?></a></p>
<?php else: ?><p class="foot"><a href="?page=login"><?=h(t('← Вернуться ко входу'))?></a></p><?php endif; ?>
<?php if($page==='register'): ?><small><?=h(t('Потребуется подтвердить email. Используй отдельный пароль для сайта.'))?></small><?php endif; ?>
<?php endif; endif; ?>
</section></main><footer>FC · TrustTheGame <span><?=h(t('Таверна открыта'))?></span></footer></body></html>
