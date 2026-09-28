<?php
declare(strict_types=1);

// Only presentation uses this preference. It never affects permissions or stored data.
function fc_language(): string {
    foreach ([$_GET['lang'] ?? null, $_COOKIE['fc_language'] ?? null] as $value) {
        if (is_string($value) && in_array($value, ['ru', 'en'], true)) return $value;
    }
    return 'ru';
}

function t(string $source): string {
    if (fc_language() !== 'en') return $source;
    static $messages;
    if ($messages === null) {
        $messages = json_decode(file_get_contents(dirname(__DIR__).'/i18n/en.json'), true, 512, JSON_THROW_ON_ERROR);
    }
    $key = trim($source);
    if (!isset($messages[$key])) return $source;
    // Preserve spaces around translated labels next to names, numbers and links.
    $start = strlen($source) - strlen(ltrim($source));
    return substr($source, 0, $start).$messages[$key].substr($source, $start + strlen($key));
}

function fc_language_url(string $language): string {
    $query = [];
    foreach (['page','event','auction','announcement','ep','ap','bp','hp','p','sort','direction','days'] as $key) {
        if (isset($_GET[$key]) && is_string($_GET[$key])) $query[$key] = $_GET[$key];
    }
    $query['lang'] = $language === 'en' ? 'en' : 'ru';
    return '/dkp/?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}

function fc_language_switch(): void { ?>
<div class="language-switch" role="group" aria-label="<?=htmlspecialchars(t('Язык сайта'), ENT_QUOTES, 'UTF-8')?>">
  <a href="<?=htmlspecialchars(fc_language_url('ru'), ENT_QUOTES, 'UTF-8')?>" data-language="ru" lang="ru" hreflang="ru" aria-label="Русский"<?=fc_language()==='ru'?' aria-current="true"':''?>>RU</a>
  <a href="<?=htmlspecialchars(fc_language_url('en'), ENT_QUOTES, 'UTF-8')?>" data-language="en" lang="en" hreflang="en" aria-label="English"<?=fc_language()==='en'?' aria-current="true"':''?>>EN</a>
</div>
<?php }
