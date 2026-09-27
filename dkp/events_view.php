<?php
require_once __DIR__.'/i18n.php';
if(!isset($user,$dkp) || !$user){http_response_code(403);exit;}
$staff=in_array($user['role'],['admin','officer'],true);
$myLink=$auth->query('SELECT member_id FROM fc_web_links WHERE web_user_id=?',[$user['id']])->fetch();
$types=['cw'=>t('ЧВ'),'pits'=>t('Питы'),'gvg'=>t('ГВГ'),'other'=>t('Другое')];$states=['open'=>t('Открыто'),'awarded'=>t('Награда выдана'),'cancelled'=>t('Отменено')];
function eventHidden(array $ev):void {echo '<input type="hidden" name="event" value="'.h((string)$ev['id']).'"><input type="hidden" name="revision" value="'.h((string)$ev['revision']).'">';}
function eventFieldsView(array $ev,array $types):void { ?>
<div class="form-grid"><label><?=h(t('Название'))?><input name="title" required maxlength="160" value="<?=h($ev['title']??'')?>" placeholder="<?=h(t('Например: Вечернее ЧВ'))?>"></label><label><?=h(t('Тип'))?><select name="category"><?php foreach($types as $key=>$label): ?><option value="<?=h($key)?>" <?=($ev['category']??'cw')===$key?'selected':''?>><?=h($label)?></option><?php endforeach; ?></select></label></div>
<div class="form-grid"><label><?=h(t('Награда каждому, ДКП'))?><input type="number" name="points" min="1" max="1000000" step="1" required value="<?=h((string)($ev['points']??5))?>"></label><label><?=h(t('Дата и время · МСК'))?><input type="datetime-local" name="scheduled" required min="2000-01-01T00:00" max="2100-12-31T23:59" value="<?=h((new DateTimeImmutable('@'.($ev['scheduled_at']??time())))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('Y-m-d\TH:i'))?>"></label></div>
<?php }
?>
<p><a href="?page=profile"><?=h(t('← Личный кабинет'))?></a> · <a href="?page=events"><?=h(t('Все события'))?></a><?php if($staff): ?> · <a href="?page=manage"><?=h(t('Управление'))?></a><?php endif; ?></p>
<?php if(isset($_GET['event'])):
 if(!is_string($_GET['event']))throw new AuthError(t('Некорректное событие.'));
 $ev=$dkp->event($_GET['event']);$selected=array_map(fn($m)=>(string)$m['member_id'],$ev['members']); ?>
<div class="event-heading event-state-<?=h(array_key_exists($ev['status'],$states)?$ev['status']:'unknown')?>"><div class="eyebrow"><?=h($types[$ev['category']]??t('Событие'))?> · #<?=h((string)$ev['id'])?></div><h3><?=h($ev['title'])?></h3><span class="status"><?=h($states[$ev['status']]??$ev['status'])?></span></div>
<p><?=h((new DateTimeImmutable('@'.$ev['scheduled_at']))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('d.m.Y H:i'))?><?=h(t(' МСК · '))?><strong><?=h((string)$ev['points'])?><?=h(t(' ДКП каждому'))?></strong><?=h(t(' · участников: '))?><?=count($selected)?></p>
<?php if($ev['status']==='open'): ?>
<div class="notice">
<?php if(!$myLink): ?><p><?=h(t('Чтобы отмечаться, попроси главу гильдии привязать твой аккаунт к игровому профилю.'))?></p>
<?php else:$joined=in_array((string)$myLink['member_id'],$selected,true); ?>
<p><strong><?=$joined?t('Ты в списке участников'):t('Отметь своё участие')?></strong><br><?=h(t('Отметка не начисляет ДКП. Награду выдаёт офицер после проверки присутствия.'))?></p>
<?php dkpForm($joined?'evt_leave':'evt_join');eventHidden($ev); ?><button<?=$joined?' class="secondary"':''?>><?=$joined?t('Отменить участие'):t('Я участвую')?></button></form>
<?php endif; ?></div>
<p><?=h(t('Сейчас отмечены: '))?><?=h(implode(', ',array_column($ev['members'],'nickname'))?:t('пока никто'))?>.</p>
<p><a href="?page=events&amp;event=<?=h((string)$ev['id'])?>"><?=h(t('Обновить список →'))?></a></p>
<?php if($staff): ?>
<details open><summary><?=h(t('Название, награда и список участников'))?></summary>
<?php dkpForm('evt_save');eventHidden($ev);eventFieldsView($ev,$types); ?>
<p><?=h(t('Отметь присутствовавших и сохрани список. Изменения в форме учитываются только после сохранения.'))?></p>
<div class="attendee-grid"><?php foreach($dkp->roster() as $m): ?><label class="check"><input type="checkbox" name="member_<?=h((string)$m['member_id'])?>" value="1" <?=in_array((string)$m['member_id'],$selected,true)?'checked':''?>><?=h($m['nickname'])?></label><?php endforeach; ?></div>
<button><?=h(t('Сохранить событие и участников'))?></button></form></details>
<div class="award-box"><h3><?=h(t('Выдать награду'))?></h3><p><?=h(t('Сохранённый список: '))?><?=h(implode(', ',array_column($ev['members'],'nickname'))?:t('пока пуст'))?>.</p><p><strong><?=count($selected)?> × <?=h((string)$ev['points'])?> = <?=h((string)(count($selected)*(int)$ev['points']))?><?=h(t(' ДКП'))?></strong></p>
<?php dkpForm('evt_award');eventHidden($ev); ?><label class="check"><input type="checkbox" name="confirmed" value="1" required><?=h(t(' Проверил сохранённый список и награду. Начислить очки и закрыть событие.'))?></label><button <?=!$selected?'disabled':''?>><?=h(t('Выдать награду участникам'))?></button></form></div>
<details><summary><?=h(t('Отменить событие'))?></summary><p><?=h(t('Очки не будут начислены. Отмена сохранится в журнале.'))?></p><?php dkpForm('evt_cancel');eventHidden($ev); ?><label><?=h(t('Причина отмены'))?><input name="reason" required maxlength="500"></label><label class="check"><input type="checkbox" name="confirmed" value="1" required><?=h(t(' Подтверждаю отмену события.'))?></label><button class="secondary"><?=h(t('Отменить событие'))?></button></form></details>
<?php endif; ?>
<?php else: ?>
<p><?= $ev['status']==='awarded'?t('Награда начислена всем сохранённым участникам. Повторная выдача и изменение списка закрыты.'):t('Событие отменено. Награда не выдавалась.') ?></p>
<ul><?php foreach($ev['members'] as $m): ?><li><?=h($m['nickname'])?></li><?php endforeach; ?></ul>
<?php if($ev['closed_at']): ?><small><?=h(t('Закрыто '))?><?=h((new DateTimeImmutable('@'.$ev['closed_at']))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('d.m.Y H:i'))?><?=h(t(' МСК'))?></small><?php endif; endif; ?>
<?php else:
 $ep=filter_var($_GET['ep']??1,FILTER_VALIDATE_INT);$ep=max(1,min((int)$ep,100000));$list=$dkp->events($ep);$more=count($list)>20;$list=array_slice($list,0,20); ?>
<p><?=h(t('Открой событие и нажми «Я участвую». Пока событие открыто, можно снять свою отметку. Награда выдаётся офицером после проверки списка.'))?></p>
<?php if($staff): ?>
<?php
require_once __DIR__.'/scheduled_events.php';
try {
 $schedule=new ScheduledEvents($auth,require __DIR__.'/cw_schedule.php');$scheduleState=$schedule->state();
 $autoOn=$scheduleState['configured'] && $scheduleState['enabled']; ?>
<div class="notice"><h3><?=h(t('Автосоздание ЧВ'))?></h3>
<p><strong><?=$autoOn?t('Включено'):t('Отключено')?></strong><?=h(t(' · ежедневно в 07:30, 15:30 и 20:30 МСК.'))?></p>
<p><?=h(t('Отключение сохраняет уже созданные события. После включения пропущенные ЧВ не создаются.'))?></p>
<?php if($scheduleState['configured']): formStart('cw_schedule'); ?>
<input type="hidden" name="enabled" value="<?=$autoOn?'0':'1'?>">
<input type="hidden" name="revision" value="<?=h((string)$scheduleState['revision'])?>">
<button<?=$autoOn?' class="secondary"':''?>><?=$autoOn?t('Отключить автосоздание'):t('Включить автосоздание')?></button></form>
<?php else: ?><p><?=h(t('Отключено в настройках сервера. Для включения обратись к администратору.'))?></p><?php endif; ?>
</div>
<?php } catch(Throwable $e) { error_log('CW settings: '.get_class($e)); ?><p class="error"><?=h(t('Настройка автосоздания недоступна. Администратору нужно проверить установку обновления базы.'))?></p><?php } ?>
<details><summary><?=h(t('Создать событие'))?></summary><?php dkpForm('evt_create');eventFieldsView([],$types); ?><button><?=h(t('Создать событие'))?></button></form></details><?php endif; ?>
<?php if(!$list): ?><p><?=h(t('На этой странице пока нет событий.'))?></p><?php endif; ?>
<?php foreach($list as $ev): ?><article class="event-card event-state-<?=h(array_key_exists($ev['status'],$states)?$ev['status']:'unknown')?>"><div class="eyebrow"><?=h($types[$ev['category']]??t('Событие'))?> · #<?=h((string)$ev['id'])?></div><h3><a href="?page=events&amp;event=<?=h((string)$ev['id'])?>"><?=h($ev['title'])?> →</a></h3><p><?=h((new DateTimeImmutable('@'.$ev['scheduled_at']))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('d.m.Y H:i'))?><?=h(t(' МСК · '))?><?=h((string)$ev['points'])?><?=h(t(' ДКП · участников '))?><?=h((string)$ev['attendees'])?></p><span class="status"><?=h($states[$ev['status']]??$ev['status'])?></span></article><?php endforeach; ?>
<nav><?php if($ep>1): ?><a href="?page=events&amp;ep=<?=$ep-1?>"><?=h(t('← Новее'))?></a><?php endif; ?><?php if($more): ?><a href="?page=events&amp;ep=<?=$ep+1?>"><?=h(t('Ранее →'))?></a><?php endif; ?></nav>
<small><?=h(t('Здесь события, созданные на сайте. Прежние начисления из бота сохранены в личной истории ДКП.'))?></small>
<?php endif; ?>
