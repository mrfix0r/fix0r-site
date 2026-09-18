<?php
if(!isset($user,$announcements) || !$user){http_response_code(403);exit;}
$admin=$user['role']==='admin';
function annDate($ts):string{return (new DateTimeImmutable('@'.$ts))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('d.m.Y H:i');}
function annForm(string $kind,?array $row=null):void {
    formStart('ann_'.$kind);echo '<input type="hidden" name="request_key" value="'.bin2hex(random_bytes(32)).'">';
    if($row)echo '<input type="hidden" name="id" value="'.h((string)$row['id']).'"><input type="hidden" name="revision" value="'.h((string)$row['revision']).'">';
}
function annEditor(?array $row=null):void {
    global $error;
    $retry=!empty($error) && ($_POST['action']??'')===($row?'ann_edit':'ann_create');
    $values=$row??[];
    if($retry){foreach(['title','body','pinned','requires_ack'] as $field)$values[$field]=$_POST[$field]??'';if($row)$row['revision']=$_POST['revision']??'';}
    annForm($row?'edit':'create',$row);
    $expires=$row && $row['expires_at']!==null?(new DateTimeImmutable('@'.$row['expires_at']))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('Y-m-d\TH:i'):'';
    if($retry)$expires=$_POST['expires']??'';
    ?>
    <label>Заголовок<input name="title" required maxlength="120" value="<?=h($values['title']??'')?>"></label>
    <label>Текст объявления<textarea name="body" rows="7" maxlength="2000" required><?=h($values['body']??'')?></textarea></label>
    <label>Показывать до · МСК (необязательно)<input type="datetime-local" name="expires" value="<?=h($expires)?>"></label>
    <label class="check"><input type="checkbox" name="pinned" value="1" <?=!empty($values['pinned'])?'checked':''?>> Закрепить в кабинете вместо текущего объявления</label>
    <label class="check"><input type="checkbox" name="requires_ack" value="1" <?=!empty($values['requires_ack'])?'checked':''?>> Участники должны подтвердить прочтение</label>
    <?php if($row): ?><label class="check"><input type="checkbox" name="reset_ack" value="1" <?=!$retry || ($_POST['reset_ack']??'')==='1'?'checked':''?>> Запросить подтверждение заново после изменения</label><small>Сними галочку только при несущественной правке: прежние подтверждения сохранятся.</small><?php endif; ?>
    <button><?=$row?'Сохранить изменения':'Опубликовать'?></button></form>
    <?php
}
?>
<p><a href="?page=profile">← Личный кабинет</a></p>
<?php if(!$announcements->canRead($user)): ?>
<p>Объявления доступны участникам гильдии. Попроси администратора привязать активный профиль ДКП.</p>
<?php return; endif; ?>
<?php $id=is_string($_GET['announcement']??null)?$_GET['announcement']:''; ?>
<?php if($id!==''): $item=$announcements->get($user,$id); ?>
<p><a href="?page=announcements">← Все объявления</a></p>
<article class="announcement-full">
<h3><?=h($item['title'])?></h3>
<p class="announcement-meta"><?=h(annDate($item['created_at']))?> МСК · <?=h($item['author'])?><?php if((int)$item['revision']>1): ?> · Обновлено <?=h(annDate($item['updated_at']))?> МСК<?php endif; ?></p>
<?php if((int)$item['archived']): ?><p class="notice">Удалено из показа</p><?php elseif(!$announcements->active($item)): ?><p class="notice">Срок показа завершён</p><?php endif; ?>
<div class="announcement-body"><?=h($item['body'])?></div>
<?php if($item['expires_at']!==null): ?><p class="announcement-meta">Показывается до <?=h(annDate($item['expires_at']))?> МСК</p><?php endif; ?>
<?php if((int)$item['requires_ack'] && $announcements->active($item)): ?>
<?php if($announcements->acknowledged($item,(int)$user['id'])): ?><p class="notice">✓ Ты подтвердил прочтение этой версии.</p>
<?php elseif($announcements->member((int)$user['id'])!==false): annForm('ack',$item); ?><button>Прочитал</button></form>
<?php else: ?><p>Для подтверждения прочтения нужен активный привязанный профиль ДКП.</p><?php endif; ?>
<?php endif; ?>
</article>
<?php if($admin){require __DIR__.'/telegram_announcement.php';} ?>
<?php if($admin && (int)$item['requires_ack']): $readers=$announcements->readers($user,$item);$readCount=count(array_filter($readers,fn($r)=>$r['read_at']!==null)); ?>
<details><summary>Прочитали <?=$readCount?> из <?=count($readers)?> · текущий состав</summary>
<p>Подтверждения текущей версии. Новые участники появляются в списке, удалённые из состава — исключаются.</p>
<div class="table-wrap"><table><thead><tr><th>Участник</th><th>Прочтение</th></tr></thead><tbody>
<?php foreach($readers as $reader): ?><tr><td><?=h($reader['nickname'])?></td><td><?=$reader['read_at']!==null?h(annDate($reader['read_at'])).' МСК':($reader['web_user_id']===null?'Нет привязанного аккаунта':'Не подтверждено')?></td></tr><?php endforeach; ?>
</tbody></table></div></details>
<?php endif; ?>
<?php if($admin && !(int)$item['archived']): ?>
<details <?=!empty($error) && ($_POST['action']??'')==='ann_edit'?'open':''?>><summary>Редактировать объявление</summary><?php annEditor($item); ?></details>
<details><summary>Удалить из показа</summary><p>Объявление исчезнет у участников. Запись и подтверждения останутся в административном архиве.</p><?php annForm('archive',$item); ?><label class="check"><input type="checkbox" name="confirmed" value="1" required> Подтверждаю удаление объявления из показа</label><button class="secondary">Удалить объявление</button></form></details>
<?php endif; ?>
<?php else: ?>
<?php if($admin): ?><details <?=!empty($error) && ($_POST['action']??'')==='ann_create'?'open':''?>><summary>Новое объявление</summary><?php annEditor(); ?></details><?php endif; ?>
<?php $pageNumber=max(1,min(100000,(int)($_GET['p']??1)));$items=$announcements->feed($user,$pageNumber);$hasNext=count($items)>20;$items=array_slice($items,0,20); ?>
<?php if(!$items): ?><p>Объявлений пока нет.</p><?php endif; ?>
<?php foreach($items as $item): ?>
<article class="announcement-list-item">
<p class="announcement-meta"><?=h(annDate($item['created_at']))?> МСК · <?=h($item['author'])?><?php if((int)$item['archived']): ?> · Удалено<?php elseif(!$announcements->active($item)): ?> · Срок показа завершён<?php elseif((int)$item['pinned']): ?> · Закреплено<?php endif; ?></p>
<h3><a href="?page=announcements&amp;announcement=<?=h((string)$item['id'])?>"><?=h($item['title'])?></a></h3>
<?php if((int)$item['requires_ack'] && $announcements->active($item)): ?><p class="announcement-meta"><?=$announcements->acknowledged($item,(int)$user['id'])?'✓ Прочтение подтверждено':'Требуется подтверждение прочтения'?></p><?php endif; ?>
</article>
<?php endforeach; ?>
<nav aria-label="Страницы объявлений"><?php if($pageNumber>1): ?><a href="?page=announcements&amp;p=<?=$pageNumber-1?>">← Новее</a><?php endif; ?><?php if($hasNext): ?><a href="?page=announcements&amp;p=<?=$pageNumber+1?>">Старее →</a><?php endif; ?></nav>
<?php endif; ?>

