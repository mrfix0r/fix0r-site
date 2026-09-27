<?php
declare(strict_types=1);
require __DIR__.'/../dkp/auth.php';
require __DIR__.'/../dkp/dkp_store.php';
function check(bool $ok, string $why): void { if (!$ok) throw new RuntimeException($why); }
function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
$_GET = []; $_COOKIE = [];
check(fc_language()==='ru' && t('Личный кабинет')==='Личный кабинет', 'Russian default');
$_COOKIE['fc_language']='en';
check(fc_language()==='en' && t('Личный кабинет')==='My account', 'Shared cookie');
$_GET['lang']='ru';
check(fc_language()==='ru', 'Explicit choice wins');
$_GET['lang']=['en'];
check(fc_language()==='en', 'Reject array input');
$_COOKIE['fc_language']='../../example';
check(fc_language()==='ru', 'Unknown language falls back safely');
$_GET=['lang'=>'en','page'=>'events','event'=>'5','token'=>'do-not-copy'];
check(fc_language_url('ru')==='/dkp/?page=events&event=5&lang=ru', 'Keep current section, omit tokens');
check(t(" \nДКП ")===" \nDKP ", 'Keep whitespace between values');
check(t('Авторский текст')==='Авторский текст', 'Unknown content is preserved');
try { Auth::password('short'); throw new RuntimeException('Expected validation'); }
catch(AuthError $e) { check($e->getMessage()==='Your password must be 12 to 128 characters long.', 'English validation'); }
try { DKP::label("two\nlines", 32); throw new RuntimeException('Expected validation'); }
catch(AuthError $e) { check($e->getMessage()==='Enter text without line breaks: up to 32 characters.', 'Dynamic validation'); }

// Rendering must localize the interface without translating or trusting user content.
$user=['nickname'=>'Честь <script>','email'=>'player@example.test','role'=>'member'];
$dkp=new class {
    function nextEvent() { return false; }
    function nextAuction() { return ['id'=>8,'item'=>'Меч <script>','minimum'=>10,'highest_bid'=>0,'highest_member'=>null,'ends_at'=>2000]; }
};
$announcements=new class { function pinned($user) { return false; } };
ob_start(); include __DIR__.'/../dkp/profile_overview.php'; $html=ob_get_clean();
check(str_contains($html,'Guild announcements') && str_contains($html,'Starting price:'), 'English profile');
check(str_contains($html,'Честь &lt;script&gt;') && str_contains($html,'Меч &lt;script&gt;'), 'Names remain unchanged and escaped');
check(!str_contains($html,'<script>'), 'No markup injection');
$_GET['lang']='ru';
ob_start(); include __DIR__.'/../dkp/profile_overview.php'; $html=ob_get_clean();
check(str_contains($html,'Начальная цена:') && str_contains($html,'Объявления гильдии'), 'Switch back to Russian');
echo "Language selection, validation, section links, profile rendering and user-content escaping OK\n";
