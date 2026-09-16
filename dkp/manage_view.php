<?php
if (!isset($user,$dkp) || !$user || !in_array($user['role'],['admin','officer'],true)) { http_response_code(403);exit; }
$roster=$dkp->roster();$admin=$user['role']==='admin';
// Staff-only data: keep this query after the role guard above.
$linkedEmails=[];
foreach($auth->query('SELECT l.member_id,u.email FROM fc_web_links l JOIN dkp_users u ON u.id=l.web_user_id')->fetchAll() as $linkRow) {
    $linkedEmails[(string)$linkRow['member_id']]=$linkRow['email'];
}
$accounts=$admin?$auth->query('SELECT u.id,u.email,u.nickname,u.role,l.member_id FROM dkp_users u LEFT JOIN fc_web_links l ON l.web_user_id=u.id WHERE u.verified_at IS NOT NULL ORDER BY u.id')->fetchAll():[];
if (!function_exists('dkpForm')) { function dkpForm(string $kind):void {
 formStart('dkp_'.$kind);echo '<input type="hidden" name="request_key" value="'.bin2hex(random_bytes(32)).'">';
}}
?>
<p><a href="?page=profile">← Личный кабинет</a></p>
<p>Баланс включает перенесённые очки и операции на сайте. Исправление ошибки — новая операция с пояснением.</p>
<p><a class="event-link" href="?page=events">События: ЧВ, питы и ГВГ →</a></p>
<p><a class="event-link" href="?page=auctions">Аукционы гильдии →</a></p>
<h3>Состав · <?=count($roster)?></h3>
<?php dkpForm('adjust'); ?>
<div class="table-wrap"><table><thead><tr><th>Выбор</th><th>Участник</th><th>Связана</th><th>Email</th><th>Всего</th><th>В ставках</th><th>Доступно</th></tr></thead><tbody>
<?php foreach($roster as $r): $linkedEmail=$linkedEmails[(string)$r['member_id']]??null; ?><tr><td><input type="checkbox" name="member_<?=h((string)$r['member_id'])?>" value="1" aria-label="Выбрать <?=h($r['nickname'])?>"></td><td><?=h($r['nickname'])?></td><td><?=$linkedEmail!==null?'Да':'Нет'?></td><td style="overflow-wrap:anywhere;min-width:160px;max-width:280px"><?=$linkedEmail!==null?h($linkedEmail):'—'?></td><td><?=h((string)$r['balance'])?></td><td><?=h((string)$r['reserved'])?></td><td><?=h((string)((int)$r['balance']-(int)$r['reserved']))?></td></tr><?php endforeach; ?>
</tbody></table></div>
<div class="form-grid"><label>Изменение ДКП каждому выбранному<input type="number" name="amount" required min="-1000000" max="1000000" step="1" placeholder="Например: 5 или -3"></label><label>Причина<input name="reason" required maxlength="500" placeholder="Например: вечернее ЧВ 14.09"></label></div>
<label class="check"><input type="checkbox" name="confirmed" value="1" required> Проверил состав, знак и количество очков. Применить каждому выбранному.</label>
<button>Применить изменение ДКП</button></form>
<?php if($admin): ?>
<details><summary>Добавить нового участника</summary><p>Создаёт игровой профиль с нулевым балансом. Аккаунт сайта можно привязать после регистрации.</p><?php dkpForm('create'); ?><label>Игровой ник<input name="nickname" required maxlength="32"></label><button>Добавить в состав</button></form></details>
<details><summary>Привязать аккаунт к участнику</summary><p>Проверь владельца игрового профиля и его email. Ник аккаунта сам по себе не подтверждает владение.</p><?php dkpForm('link'); ?>
<label>Игровой профиль<select name="member" required><option value="">Выбери участника</option><?php foreach($roster as $r): ?>
<option value="<?=h((string)$r['member_id'])?>"><?=h($r['nickname'])?> · <?=h((string)$r['member_id'])?></option><?php endforeach; ?></select></label>
<label>Подтверждённый аккаунт<select name="account" required><option value="">Выбери аккаунт</option><?php foreach($accounts as $accountRow): if($accountRow['member_id']!==null)continue; ?><option value="<?=h((string)$accountRow['id'])?>">#<?=h((string)$accountRow['id'])?> · <?=h($accountRow['email'])?> · <?=h($accountRow['nickname'])?></option><?php endforeach; ?></select></label>
<label class="check"><input type="checkbox" name="confirmed" value="1" required> Я проверил владельца профиля и email.</label><button>Подтвердить привязку</button></form></details>
<details><summary>Офицеры и аккаунты</summary>
<div class="table-wrap"><table><thead><tr><th>Аккаунт</th><th>Роль</th><th>Профиль</th></tr></thead><tbody><?php foreach($accounts as $accountRow): ?><tr><td><?=h($accountRow['email'])?></td><td><?=h(['member'=>'Участник','officer'=>'Офицер','admin'=>'Администратор'][$accountRow['role']]??'Участник')?></td><td><?=h((string)($accountRow['member_id']??'Не привязан'))?></td></tr><?php endforeach; ?></tbody></table></div>
<?php dkpForm('role'); ?><label>Аккаунт<select name="account" required><option value="">Выбери аккаунт</option><?php foreach($accounts as $accountRow): if($accountRow['role']==='admin')continue; ?><option value="<?=h((string)$accountRow['id'])?>"><?=h($accountRow['email'])?> · <?=h($accountRow['nickname'])?></option><?php endforeach; ?></select></label>
<label>Новая роль<select name="role"><option value="member">Участник</option><option value="officer">Офицер</option></select></label>
<p>Офицер может изменять очки и видеть состав и журнал. Привязками и ролями управляет администратор. После изменения роли аккаунт должен войти заново.</p><button>Сохранить роль</button></form></details>
<?php endif; ?>
<details><summary>Последние операции на сайте</summary>
<?php $ops=$auth->query('SELECT o.*,u.nickname AS actor FROM fc_operations o JOIN dkp_users u ON u.id=o.actor_id ORDER BY o.created_at DESC,o.request_key DESC LIMIT 50')->fetchAll();
foreach($ops as $o): $d=json_decode($o['details'],true); ?>
<article class="operation"><strong><?=h(['evt_auto'=>'Автоматическое ЧВ','evt_join'=>'Игрок отметил участие','evt_leave'=>'Игрок снял отметку','auc_create'=>'Открытие аукциона','auc_bid'=>'Ставка','auc_cancel'=>'Отмена аукциона','auc_close'=>'Автозавершение аукциона','evt_create'=>'Создание события','evt_save'=>'Изменение события','evt_award'=>'Награда за событие','evt_cancel'=>'Отмена события','adjust'=>'Изменение ДКП','link'=>'Привязка','role'=>'Роль','create'=>'Новый участник','bootstrap'=>'Администратор'][$o['kind']]??$o['kind'])?></strong> · <?=h(in_array($o['kind'],['auc_close','evt_auto'],true)?'Автоматически':$o['actor'])?><br><small><?=h((new DateTimeImmutable('@'.$o['created_at']))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('d.m.Y H:i'))?> МСК</small>
<?php if(in_array($o['kind'],['adjust','evt_award'],true)): ?><p><?=h((string)$d['amount'])?> ДКП каждому: <?=h(implode(', ',array_values($d['members'])))?><br><?=h($d['reason'])?></p>
<?php elseif(str_starts_with($o['kind'],'auc_')): ?><p><a href="?page=auctions&amp;auction=<?=h((string)$d['auction_id'])?>"><?=h($d['item'])?></a><?php if(isset($d['amount']))echo ' · '.h((string)$d['amount']).' ДКП';if(isset($d['reason']))echo '<br>'.h($d['reason']); ?></p>
<?php elseif(str_starts_with($o['kind'],'evt_')): ?><p><a href="?page=events&amp;event=<?=h((string)$d['event_id'])?>"><?=h($d['title'])?></a><?php if(isset($d['nickname']))echo '<br>Участник: '.h($d['nickname']);if(isset($d['reason']))echo '<br>'.h($d['reason']); ?></p>
<?php elseif($admin && is_array($d)): ?><p><?php if($o['kind']==='create') echo 'Участник: '.h($d['nickname']); elseif($o['kind']==='link') echo 'Аккаунт #'.h($d['account']).' → профиль '.h($d['member']); elseif($o['kind']==='role') echo 'Аккаунт #'.h($d['account']).' → '.h($d['role']==='officer'?'Офицер':'Участник'); ?></p><?php endif; ?></article>
<?php endforeach; ?><small>Показаны последние 50 операций. Полный журнал сохраняется в базе.</small></details>

