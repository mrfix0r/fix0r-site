<?php
if(!isset($user,$telegram) || !$user){http_response_code(403);exit;}
?>
<details><summary>Telegram · уведомления об объявлениях</summary>
<?php if(!$telegram->enabled()): ?><p>Администратор ещё не подключил Telegram-бота.</p>
<?php else: try { $tgSub=$telegram->subscription((int)$user['id']);$tgMember=$telegram->member((int)$user['id']); ?>
<?php if($tgSub): ?><p><?=$tgSub['username']!==''?'@'.h($tgSub['username']):'Telegram привязан'?> · <?=(int)$tgSub['subscribed']?'Подписка включена':'Подписка отключена'?></p><?php endif; ?>
<?php if($tgMember!==false): ?>
<p>Получай объявления гильдии в личных сообщениях. Нажми кнопку, открой бота и нажми Start / «Запустить». Это подтвердит твой Telegram и включит подписку.</p>
<?php formStart('tg_link'); ?><button><?=$tgSub?'Привязать Telegram заново':'Привязать Telegram'?></button></form>
<?php $pendingLink=$_SESSION['tg_link']??null;if($pendingLink && (int)$pendingLink['expires_at']>time()): ?>
<p><a class="profile-button" href="<?=h($pendingLink['url'])?>" target="_blank" rel="noopener noreferrer">Открыть Telegram-бота →</a></p><small>Личная ссылка действует 10 минут. Не пересылай её. После Start подожди до минуты и обнови кабинет.</small>
<?php endif; ?>
<?php else: ?><p>Для подписки нужен активный профиль ДКП, привязанный к твоему аккаунту.</p><?php endif; ?>
<?php if($tgSub): formStart('tg_unlink'); ?><button class="secondary">Отписаться и отвязать Telegram</button></form><?php endif; ?>
<p><small>Можно отписаться командой /stop в боте. Получение сообщения не отмечает объявление прочитанным на сайте.</small></p>
<?php }catch(Throwable $e){error_log('DKP Telegram profile: '.get_class($e)); ?><p>Не удалось загрузить подписку. Сообщи администратору.</p><?php } endif; ?>
</details>
