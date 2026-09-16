<?php
if(!isset($user,$dkp) || !$user){http_response_code(403);exit;}
$staff=in_array($user['role'],['admin','officer'],true);
$myLink=$auth->query('SELECT member_id FROM fc_web_links WHERE web_user_id=?',[$user['id']])->fetch();
$types=['cw'=>'ЧВ','pits'=>'Питы','gvg'=>'ГВГ','other'=>'Другое'];$states=['open'=>'Открыто','awarded'=>'Награда выдана','cancelled'=>'Отменено'];
function eventHidden(array $ev):void {echo '<input type="hidden" name="event" value="'.h((string)$ev['id']).'"><input type="hidden" name="revision" value="'.h((string)$ev['revision']).'">';}
function eventFieldsView(array $ev,array $types):void { ?>
<div class="form-grid"><label>Название<input name="title" required maxlength="160" value="<?=h($ev['title']??'')?>" placeholder="Например: Вечернее ЧВ"></label><label>Тип<select name="category"><?php foreach($types as $key=>$label): ?><option value="<?=h($key)?>" <?=($ev['category']??'cw')===$key?'selected':''?>><?=h($label)?></option><?php endforeach; ?></select></label></div>
<div class="form-grid"><label>Награда каждому, ДКП<input type="number" name="points" min="1" max="1000000" step="1" required value="<?=h((string)($ev['points']??5))?>"></label><label>Дата и время · МСК<input type="datetime-local" name="scheduled" required min="2000-01-01T00:00" max="2100-12-31T23:59" value="<?=h((new DateTimeImmutable('@'.($ev['scheduled_at']??time())))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('Y-m-d\TH:i'))?>"></label></div>
<?php }
?>
<p><a href="?page=profile">← Личный кабинет</a> · <a href="?page=events">Все события</a><?php if($staff): ?> · <a href="?page=manage">Управление</a><?php endif; ?></p>
<?php if(isset($_GET['event'])):
 if(!is_string($_GET['event']))throw new AuthError('Некорректное событие.');
 $ev=$dkp->event($_GET['event']);$selected=array_map(fn($m)=>(string)$m['member_id'],$ev['members']); ?>
<div class="event-heading event-state-<?=h(array_key_exists($ev['status'],$states)?$ev['status']:'unknown')?>"><div class="eyebrow"><?=h($types[$ev['category']]??'Событие')?> · #<?=h((string)$ev['id'])?></div><h3><?=h($ev['title'])?></h3><span class="status"><?=h($states[$ev['status']]??$ev['status'])?></span></div>
<p><?=h((new DateTimeImmutable('@'.$ev['scheduled_at']))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('d.m.Y H:i'))?> МСК · <strong><?=h((string)$ev['points'])?> ДКП каждому</strong> · участников: <?=count($selected)?></p>
<?php if($ev['status']==='open'): ?>
<div class="notice">
<?php if(!$myLink): ?><p>Чтобы отмечаться, попроси главу гильдии привязать твой аккаунт к игровому профилю.</p>
<?php else:$joined=in_array((string)$myLink['member_id'],$selected,true); ?>
<p><strong><?=$joined?'Ты в списке участников':'Отметь своё участие'?></strong><br>Отметка не начисляет ДКП. Награду выдаёт офицер после проверки присутствия.</p>
<?php dkpForm($joined?'evt_leave':'evt_join');eventHidden($ev); ?><button<?=$joined?' class="secondary"':''?>><?=$joined?'Отменить участие':'Я участвую'?></button></form>
<?php endif; ?></div>
<p>Сейчас отмечены: <?=h(implode(', ',array_column($ev['members'],'nickname'))?:'пока никто')?>.</p>
<p><a href="?page=events&amp;event=<?=h((string)$ev['id'])?>">Обновить список →</a></p>
<?php if($staff): ?>
<details open><summary>Название, награда и список участников</summary>
<?php dkpForm('evt_save');eventHidden($ev);eventFieldsView($ev,$types); ?>
<p>Отметь присутствовавших и сохрани список. Изменения в форме учитываются только после сохранения.</p>
<div class="attendee-grid"><?php foreach($dkp->roster() as $m): ?><label class="check"><input type="checkbox" name="member_<?=h((string)$m['member_id'])?>" value="1" <?=in_array((string)$m['member_id'],$selected,true)?'checked':''?>><?=h($m['nickname'])?></label><?php endforeach; ?></div>
<button>Сохранить событие и участников</button></form></details>
<div class="award-box"><h3>Выдать награду</h3><p>Сохранённый список: <?=h(implode(', ',array_column($ev['members'],'nickname'))?:'пока пуст')?>.</p><p><strong><?=count($selected)?> × <?=h((string)$ev['points'])?> = <?=h((string)(count($selected)*(int)$ev['points']))?> ДКП</strong></p>
<?php dkpForm('evt_award');eventHidden($ev); ?><label class="check"><input type="checkbox" name="confirmed" value="1" required> Проверил сохранённый список и награду. Начислить очки и закрыть событие.</label><button <?=!$selected?'disabled':''?>>Выдать награду участникам</button></form></div>
<details><summary>Отменить событие</summary><p>Очки не будут начислены. Отмена сохранится в журнале.</p><?php dkpForm('evt_cancel');eventHidden($ev); ?><label>Причина отмены<input name="reason" required maxlength="500"></label><label class="check"><input type="checkbox" name="confirmed" value="1" required> Подтверждаю отмену события.</label><button class="secondary">Отменить событие</button></form></details>
<?php endif; ?>
<?php else: ?>
<p><?= $ev['status']==='awarded'?'Награда начислена всем сохранённым участникам. Повторная выдача и изменение списка закрыты.':'Событие отменено. Награда не выдавалась.' ?></p>
<ul><?php foreach($ev['members'] as $m): ?><li><?=h($m['nickname'])?></li><?php endforeach; ?></ul>
<?php if($ev['closed_at']): ?><small>Закрыто <?=h((new DateTimeImmutable('@'.$ev['closed_at']))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('d.m.Y H:i'))?> МСК</small><?php endif; endif; ?>
<?php else:
 $ep=filter_var($_GET['ep']??1,FILTER_VALIDATE_INT);$ep=max(1,min((int)$ep,100000));$list=$dkp->events($ep);$more=count($list)>20;$list=array_slice($list,0,20); ?>
<p>Открой событие и нажми «Я участвую». Пока событие открыто, можно снять свою отметку. Награда выдаётся офицером после проверки списка.</p>
<?php if($staff): ?>
<details><summary>Создать событие</summary><?php dkpForm('evt_create');eventFieldsView([],$types); ?><button>Создать событие</button></form></details><?php endif; ?>
<?php if(!$list): ?><p>На этой странице пока нет событий.</p><?php endif; ?>
<?php foreach($list as $ev): ?><article class="event-card event-state-<?=h(array_key_exists($ev['status'],$states)?$ev['status']:'unknown')?>"><div class="eyebrow"><?=h($types[$ev['category']]??'Событие')?> · #<?=h((string)$ev['id'])?></div><h3><a href="?page=events&amp;event=<?=h((string)$ev['id'])?>"><?=h($ev['title'])?> →</a></h3><p><?=h((new DateTimeImmutable('@'.$ev['scheduled_at']))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('d.m.Y H:i'))?> МСК · <?=h((string)$ev['points'])?> ДКП · участников <?=h((string)$ev['attendees'])?></p><span class="status"><?=h($states[$ev['status']]??$ev['status'])?></span></article><?php endforeach; ?>
<nav><?php if($ep>1): ?><a href="?page=events&amp;ep=<?=$ep-1?>">← Новее</a><?php endif; ?><?php if($more): ?><a href="?page=events&amp;ep=<?=$ep+1?>">Ранее →</a><?php endif; ?></nav>
<small>Здесь события, созданные на сайте. Прежние начисления из бота сохранены в личной истории ДКП.</small>
<?php endif; ?>
