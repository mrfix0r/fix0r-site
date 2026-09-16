<?php
declare(strict_types=1);
ini_set('display_errors','0');
header('Content-Type: text/html; charset=utf-8');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; img-src 'self'; font-src 'self'; script-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
header('Referrer-Policy: no-referrer'); header('X-Content-Type-Options: nosniff'); header('Cache-Control: no-store');
require __DIR__.'/auth.php';
require __DIR__.'/dkp_store.php';
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function go(string $page, string $message=''): never { if ($message) $_SESSION['flash']=$message; header('Location: /dkp/?page='.$page, true, 303); exit; }
function field(string $name,string $label,string $type='text',string $auto=''): void {
    echo '<label>'.h($label).'<input name="'.h($name).'" type="'.h($type).'" required maxlength="'.($type==='password'?'128':'254').'" autocomplete="'.h($auto).'"'.($type==='password'?' minlength="12"':'').'></label>';
}
$ready=false; $error=''; $user=false;
try {
    if (!is_file(__DIR__.'/config.php')) throw new RuntimeException('Configuration missing');
    $config=require __DIR__.'/config.php';
    $origin=rtrim($config['origin'],'/');
    if (!preg_match('~^https://[a-z0-9.-]+$~iD',$origin)) throw new RuntimeException('Invalid origin');
    if (($_SERVER['HTTPS']??'') !== 'on' && ($_SERVER['HTTPS']??'') !== '1') { header('Location: '.$origin.'/dkp/',true,302); exit; }
    if (!filter_var($config['mail_from'], FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/',$config['mail_from'])) throw new RuntimeException('Invalid sender');
    ini_set('session.use_strict_mode','1'); ini_set('session.use_only_cookies','1');
    session_name('FC_DKP'); session_set_cookie_params(['lifetime'=>0,'path'=>'/dkp','secure'=>true,'httponly'=>true,'samesite'=>'Lax']); session_start();
    $_SESSION['csrf']??=bin2hex(random_bytes(32));
    $db=new PDO($config['dsn'],$config['db_user'],$config['db_password'],[PDO::ATTR_TIMEOUT=>5]);
    $auth=new Auth($db,$origin,static function(string $to,string $subject,string $body) use($config):bool {
        return mail($to,'=?UTF-8?B?'.base64_encode($subject).'?=',$body,['From'=>$config['mail_from'],'Content-Type'=>'text/plain; charset=UTF-8','MIME-Version'=>'1.0']);
    });
    $auth->query('SELECT id FROM dkp_users LIMIT 1');
    if (isset($_SESSION['uid'])) {
        $user=$auth->user((int)$_SESSION['uid']);
        if (!$user || !$user['verified_at'] || (int)$user['session_version']!==($_SESSION['version']??0) || time()-($_SESSION['last']??0)>3600 || time()-($_SESSION['born']??0)>43200) {
            unset($_SESSION['uid'],$_SESSION['version']); $user=false;
        } else $_SESSION['last']=time();
    }
    $dkp=new DKP($auth);
    $auctionWarning='';
    if($user)try{$dkp->settleDue();}catch(Throwable $e){error_log('DKP settlement: '.get_class($e).' code='.$e->getCode());$auctionWarning='Не удалось завершить истёкшие аукционы. Ставки остаются в резерве. Сообщи администратору.';}
    $ready=true;
} catch(Throwable $e) { http_response_code(503); error_log('DKP initialization: '.get_class($e).' code='.$e->getCode().' driver='.($e instanceof PDOException ? ($e->errorInfo[1] ?? 'unknown') : 'n/a')); }
$page=is_string($_GET['page']??null)?$_GET['page']:($user?'profile':'login');
if (!in_array($page,['login','register','forgot','resend','verify','reset','profile','manage','events','auctions'],true)) $page='login';
if ($ready && isset($_GET['token']) && in_array($page,['verify','reset'],true)) {
    $token=$_GET['token'];
    if (is_string($token) && preg_match('/^[a-f0-9]{64}$/D',$token)) $_SESSION[$page.'_token']=$token;
    else unset($_SESSION[$page.'_token']);
    go($page);
}
if ($ready && $_SERVER['REQUEST_METHOD']==='POST') {
    try {
        if ((int)($_SERVER['CONTENT_LENGTH']??0)>8192) throw new AuthError('Слишком большой запрос.');
        foreach ($_POST as $value) if (!is_string($value)) throw new AuthError('Некорректный запрос.');
        if (!hash_equals($_SESSION['csrf'],$_POST['csrf']??'')) throw new AuthError('Сеанс формы истёк. Обнови страницу и повтори.');
        $action=$_POST['action']??'';
        $auth->rate('post:'.($_SERVER['REMOTE_ADDR']??''),40);
        $email=$_POST['email']??''; $password=$_POST['password']??'';
        if (in_array($action,['register','forgot','resend'],true)) {
            $auth->rate('mail-ip:'.($_SERVER['REMOTE_ADDR']??''),10);
            $auth->rate('mail-email:'.Auth::email($email),3);
        }
        if (str_starts_with($action,'dkp_')) {
            if (!$user) throw new AuthError('Войди в кабинет.');
            $kind=substr($action,4);
            if (in_array($kind,['adjust','link','evt_award','evt_cancel','auc_bid','auc_cancel'],true) && ($_POST['confirmed']??'')!=='1') throw new AuthError('Подтверди проверку данных.');
            $payload=[];
            if ($kind==='adjust') {
                $ids=[]; foreach($_POST as $key=>$value) if(str_starts_with($key,'member_') && $value==='1') $ids[]=substr($key,7);
                sort($ids,SORT_STRING);$payload=['members'=>$ids,'amount'=>$_POST['amount']??'','reason'=>$_POST['reason']??''];
            } elseif($kind==='link') $payload=['member'=>$_POST['member']??'','account'=>$_POST['account']??''];
            elseif($kind==='role') $payload=['account'=>$_POST['account']??'','role'=>$_POST['role']??''];
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
            if(str_starts_with($kind,'auc_'))go('auctions'.($kind!=='auc_create'?'&auction='.DKP::id($payload['auction']):''),$changed?($kind==='auc_bid'?'Ставка принята. Очки зарезервированы.':'Аукцион сохранён.'):'Это действие уже выполнено. Повторных изменений нет.');
            if(str_starts_with($kind,'evt_'))go('events'.($kind!=='evt_create'?'&event='.DKP::id($payload['event']):''),$changed?match($kind){'evt_award'=>'Награда начислена. Событие закрыто.','evt_join'=>'Ты отмечен в событии. Награду выдаст офицер после проверки.','evt_leave'=>'Твоя отметка участия снята.',default=>'Событие сохранено.'}:'Это действие уже выполнено. Повторных изменений нет.');
            go('manage',$changed?'Изменения сохранены.':'Эта операция уже выполнена. Повторно ничего не изменено.');
        }
        switch($action) {
            case 'register': $auth->register($email,$_POST['nickname']??'',$password); go('login','Если регистрация доступна для этого email, письмо с подтверждением отправлено. Проверь также папку «Спам».');
            case 'forgot': case 'resend': $auth->requestToken($email,$action==='forgot'?'reset':'verify'); go($action,'Если для этого email доступно действие, письмо отправлено. Проверь также папку «Спам».');
            case 'verify': case 'reset':
                $auth->redeem($_SESSION[$action.'_token']??'',$action,$password); unset($_SESSION[$action.'_token']); go('login',$action==='verify'?'Email подтверждён. Теперь можно войти.':'Пароль изменён. Войди с новым паролем.');
            case 'login':
                $auth->rate('login:'.Auth::email($email),10); $u=$auth->login($email,$password);
                session_regenerate_id(true); $_SESSION=['uid'=>(int)$u['id'],'version'=>(int)$u['session_version'],'born'=>time(),'last'=>time(),'csrf'=>bin2hex(random_bytes(32))]; go('profile');
            case 'logout': $_SESSION=[]; session_regenerate_id(true); $_SESSION['csrf']=bin2hex(random_bytes(32)); go('login','Ты вышел из кабинета.');
            case 'nickname':
                if (!$user) throw new AuthError('Войди в кабинет.');
                $nick=trim($_POST['nickname']??''); if (!preg_match('/^[^\p{C}]{1,32}$/u',$nick)) throw new AuthError('Ник должен содержать от 1 до 32 символов.');
                $auth->query('UPDATE dkp_users SET nickname=? WHERE id=?',[$nick,$user['id']]); go('profile','Игровой ник изменён.');
            case 'password':
                if (!$user) throw new AuthError('Войди в кабинет.');
                $auth->changePassword((int)$user['id'],$_POST['old_password']??'',$password); $_SESSION=[]; session_regenerate_id(true); $_SESSION['csrf']=bin2hex(random_bytes(32)); go('login','Пароль изменён. Все сеансы завершены. Войди заново.');
            default: throw new AuthError('Неизвестное действие.');
        }
    } catch(AuthError $e) { $error=$e->getMessage(); http_response_code(400); }
    catch(Throwable $e) { $error='Не удалось выполнить действие. Попробуй позже.'; http_response_code(503); error_log('DKP request: '.get_class($e)); }
}
if ($ready && in_array($page,['profile','auctions','events'],true) && !$user) go('login');
if ($ready && $page==='manage' && (!$user || !in_array($user['role'],['admin','officer'],true))) go('profile');
$titles=['auctions'=>'Аукционы гильдии','events'=>'События гильдии','manage'=>'Управление ДКП','login'=>'С возвращением','register'=>'Присоединяйся к гильдии','forgot'=>'Забыл пароль?','resend'=>'Подтвердим почту','verify'=>'Подтверждение email','reset'=>'Новый пароль','profile'=>'Твой кабинет'];
function dkpForm(string $kind):void { formStart('dkp_'.$kind);echo '<input type="hidden" name="request_key" value="'.bin2hex(random_bytes(32)).'">'; }
function formStart(string $action):void { echo '<form method="post"><input type="hidden" name="csrf" value="'.h($_SESSION['csrf']).'"><input type="hidden" name="action" value="'.h($action).'">'; }
?>
<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title><?=h($titles[$page])?> · FC DKP</title><link rel="icon" href="/favicon.svg"><link rel="stylesheet" href="/dkp/style.css?v=4"></head><body>
<header><a class="brand" href="/">FC <span>TrustTheGame</span></a><a href="/">← На главную</a></header>
<main<?= in_array($page,['manage','events','auctions'],true)?' class="management"':'' ?>><aside><div class="eyebrow">SLEEPINGFOREST / RF ONLINE</div><h1>Сила гильдии —<br>в каждом из нас.</h1><p>Место для твоего игрового профиля.<br>Вход через собственный аккаунт сайта.</p><div class="crest">FC</div><small>Собираемся вместе. Играем на доверии.</small></aside>
<section class="card">
<?php if (!$ready): ?><div class="eyebrow">FC DKP</div><h2>Кабинет готовится к открытию</h2><p>Администратору нужно завершить настройку сервера. Попробуй зайти позже.</p><a href="/">Вернуться на главную →</a>
<?php else: ?><div class="eyebrow">ЛИЧНЫЙ КАБИНЕТ</div><h2><?=h($titles[$page])?></h2>
<?php if(isset($_SESSION['flash'])): ?><p class="notice" role="status"><?=h($_SESSION['flash'])?></p><?php unset($_SESSION['flash']); endif; ?>
<?php if($error): ?><p class="error" role="alert"><?=h($error)?></p><?php endif; ?>
<?php if($auctionWarning??''): ?><p class="error"><?=h($auctionWarning)?></p><?php endif; ?>
<?php if($page==='auctions'): ?>
<?php try { require __DIR__.'/auctions_view.php'; } catch(AuthError $e){echo '<p class="error">'.h($e->getMessage()).'</p>';} catch(Throwable $e){error_log('DKP auctions: '.get_class($e).' code='.$e->getCode());echo '<p class="error">Не удалось загрузить аукционы. Сообщи администратору.</p>';} ?>
<?php elseif($page==='events'): ?>
<?php try { require __DIR__.'/events_view.php'; } catch(AuthError $e) { echo '<p class="error">'.h($e->getMessage()).'</p>'; } catch(Throwable $e) { error_log('DKP events: '.get_class($e).' code='.$e->getCode()); echo '<p class="error">События недоступны. Проверь установку обновления базы.</p>'; } ?>
<?php elseif($page==='manage'): ?>
<?php try { require __DIR__.'/manage_view.php'; } catch(Throwable $e) { error_log('DKP management: '.get_class($e).' code='.$e->getCode()); echo '<p class="error">Панель недоступна. Проверь установку обновления базы.</p>'; } ?>
<?php elseif($page==='profile'): ?>
<p class="identity"><?=h($user['nickname'])?></p><p><?=h($user['email'])?> · <?=h(['member'=>'Участник','officer'=>'Офицер','admin'=>'Администратор'][$user['role']]??'Участник')?></p>
<?php if(in_array($user['role'],['admin','officer'],true)): ?><p><a href="?page=manage">Управление ДКП →</a></p><?php endif; ?>
<p><a href="?page=events">События · отметить участие →</a></p>
<p><a href="?page=auctions">Аукционы гильдии →</a></p>
<?php require __DIR__.'/migration_view.php'; ?>
<details><summary>Изменить игровой ник</summary><?php formStart('nickname'); field('nickname','Новый ник','text','nickname'); ?><button>Сохранить ник</button></form></details>
<details><summary>Изменить пароль</summary><?php formStart('password'); field('old_password','Текущий пароль','password','current-password'); field('password','Новый пароль · от 12 символов','password','new-password'); ?><button>Изменить пароль</button></form></details>
<?php formStart('logout'); ?><button class="secondary">Выйти</button></form>
<?php else:
formStart($page);
if(in_array($page,['login','register','forgot','resend'],true)) field('email','Email','email','email');
if($page==='register') field('nickname','Игровой ник','text','nickname');
if(in_array($page,['login','register','verify','reset'],true)) field('password',in_array($page,['register','reset'],true)?'Пароль · от 12 символов':'Пароль','password',in_array($page,['register','reset'],true)?'new-password':'current-password');
$buttons=['login'=>'Войти в кабинет','register'=>'Зарегистрироваться','forgot'=>'Отправить ссылку','resend'=>'Отправить письмо','verify'=>'Подтвердить email','reset'=>'Сохранить новый пароль']; ?>
<button><?=h($buttons[$page])?> <span>→</span></button></form>
<?php if($page==='login'): ?><nav><a href="?page=forgot">Забыл пароль?</a><a href="?page=resend">Повторить письмо</a></nav><p class="foot">Ещё нет аккаунта? <a href="?page=register">Регистрация</a></p>
<?php else: ?><p class="foot"><a href="?page=login">← Вернуться ко входу</a></p><?php endif; ?>
<?php if($page==='register'): ?><small>Потребуется подтвердить email. Используй отдельный пароль для сайта.</small><?php endif; ?>
<?php endif; endif; ?>
</section></main><footer>FC · TrustTheGame <span>Таверна открыта</span></footer></body></html>
