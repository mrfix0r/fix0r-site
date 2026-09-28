<?php
require_once __DIR__.'/metrics_store.php';
if(!fc_metrics_admin($user??null)){http_response_code(403);exit;}
$days=is_string($_GET['days']??null)?(int)$_GET['days']:7;
if(!in_array($days,[1,7,30,90],true))$days=7;
try{$report=(new SiteMetrics($auth->db))->report($user,$days);}
catch(Throwable $e){error_log('Site metrics report: '.get_class($e));echo '<p class="error">'.h(t('Статистика пока недоступна. Импортируй private/01-site-metrics.sql в базу сайта.')).'</p>';return;}
$pages=fc_metrics_pages();$actions=fc_metrics_actions();$areas=fc_metrics_areas();
$fmt=static fn(int $n):string=>number_format($n,0,'.',' ');
?>
<p><a href="?page=profile&amp;lang=<?=h(fc_language())?>"><?=h(t('← Личный кабинет'))?></a></p>
<form class="metrics-filter" method="get">
  <input type="hidden" name="page" value="metrics"><input type="hidden" name="lang" value="<?=h(fc_language())?>">
  <label><?=h(t('Период'))?><select name="days"><?php foreach([1=>'Сегодня',7=>'7 дней',30=>'30 дней',90=>'90 дней'] as $value=>$label): ?><option value="<?=$value?>"<?=$days===$value?' selected':''?>><?=h(t($label))?></option><?php endforeach; ?></select></label>
  <button type="submit"><?=h(t('Показать'))?></button>
</form>
<p class="metrics-note"><?=h($report['from'])?> — <?=h($report['to'])?> · <?=h(t('Даты и сутки — по Москве.'))?></p>
<div class="metrics-summary">
  <div><span><?=h(t('Посетители'))?></span><strong><?=$fmt($report['visitors'])?></strong><small><?=h(t('Уникальные браузеры за период'))?></small></div>
  <div><span><?=h(t('Просмотры страниц'))?></span><strong><?=$fmt($report['totals']['view'])?></strong><small><?=h(t('Повторные загрузки тоже учитываются'))?></small></div>
  <div><span><?=h(t('Клики'))?></span><strong><?=$fmt($report['totals']['click'])?></strong><small><?=h(t('По отслеживаемым кнопкам и ссылкам'))?></small></div>
</div>
<?php if(!$report['last']): ?><p class="notice"><?=h(t('Данных пока нет. Открой главную и нажми одну из кнопок, затем обнови отчёт. Статистика собирается с момента установки.'))?></p>
<?php else: ?><p class="metrics-note"><?=h(t('Последнее принятое событие: '))?><?=h((new DateTimeImmutable('@'.$report['last']))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('d.m.Y H:i:s'))?> <?=h(t('МСК'))?></p><?php endif; ?>
<div class="metrics-preferences">
  <button type="button" class="secondary" data-metrics-optout hidden aria-pressed="false" data-include-label="<?=h(t('Учитывать этот браузер'))?>" data-exclude-label="<?=h(t('Исключить мой браузер из статистики'))?>" data-privacy-label="<?=h(t('Учёт отключён настройками приватности браузера'))?>"></button>
  <p class="error" data-metrics-storage-error hidden><?=h(t('Браузер не разрешил сохранить настройку.'))?></p>
  <small><?=h(t('Исключение действует на будущие события этого браузера на этом домене. Сам отчёт не увеличивает счётчики.'))?></small>
</div>
<h3><?=h(t('По дням'))?></h3>
<div class="table-wrap"><table class="metrics-table"><thead><tr><th><?=h(t('Дата'))?></th><th><?=h(t('Посетители'))?></th><th><?=h(t('Просмотры страниц'))?></th><th><?=h(t('Клики'))?></th></tr></thead><tbody>
<?php $maximum=max(1,...array_column($report['daily'],'view'));foreach(array_reverse($report['daily'],true) as $day=>$row): ?>
<tr><td><?=h($day)?></td><td><?=$fmt($row['visitors'])?></td><td><span class="metrics-volume"><meter min="0" max="<?=$maximum?>" value="<?=$row['view']?>" aria-label="<?=h(t('Просмотры страниц'))?>"></meter><?=$fmt($row['view'])?></span></td><td><?=$fmt($row['click'])?></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php foreach(['view'=>'Страницы','click'=>'Кнопки и ссылки'] as $kind=>$heading): ?>
<h3><?=h(t($heading))?></h3>
<div class="table-wrap"><table class="metrics-table"><thead><tr><th><?=h(t($kind==='view'?'Страница':'Действие'))?></th><?php if($kind==='click'): ?><th><?=h(t('Расположение'))?></th><?php endif; ?><th><?=h(t('Язык'))?></th><th><?=h(t('Количество'))?></th></tr></thead><tbody>
<?php $found=false;foreach($report['items'] as $row):if($row['kind']!==$kind)continue;$found=true; ?>
<tr><td><?=h(t(($kind==='view'?$pages:$actions)[$row['item']]??$row['item']))?></td><?php if($kind==='click'): ?><td><?=h(t($areas[$row['area']]??$row['area']))?></td><?php endif; ?><td><?=h(strtoupper($row['lang']))?></td><td><?=$fmt((int)$row['total'])?></td></tr>
<?php endforeach;if(!$found): ?><tr><td colspan="<?=$kind==='view'?3:4?>"><?=h(t('За выбранный период событий нет.'))?></td></tr><?php endif; ?></tbody></table></div>
<?php endforeach; ?>
<p class="metrics-note"><?=h(t('Один человек с разных устройств или после очистки хранилища может считаться несколькими посетителями. Идентификатор браузера обновляется через 30 дней.'))?></p>
<p class="metrics-note"><?=h(t('Счётчики показывают нажатия, а не успешные подписки или операции ДКП. Блокировщики, выключенный JavaScript и настройки приватности могут уменьшать числа.'))?></p>
