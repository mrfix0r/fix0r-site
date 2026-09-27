<?php
require_once __DIR__.'/i18n.php';
if(!isset($user,$telegram,$item) || !$user || $user['role']!=='admin'){http_response_code(403);exit;}
?>
<details><summary><?=h(t('Оповещение в Telegram'))?></summary>
<?php if(!$telegram->enabled()): ?><p><?=h(t('Сначала настрой Telegram-бота и планировщик.'))?></p>
<?php else: try { $tgStats=$telegram->stats($user,(string)$item['id']);$tgState=$telegram->state(); ?>
<?php if(!$tgState['last_success'] || (int)$tgState['last_success']<time()-180): ?><p class="error"><?=h(t('Планировщик Telegram ещё не запускался или давно не работал. Рассылка будет ждать в очереди.'))?></p><?php endif; ?>
<?php if($tgStats!==false): ?><p><?=h(t('Рассылка создана. Повторное нажатие не отправит объявление заново.'))?></p>
<ul><?php foreach($tgStats as $tgRow): ?><li><?=h(['pending'=>t('В очереди'),'sending'=>t('Отправляется'),'sent'=>t('Принято Telegram'),'skipped'=>t('Пропущено'),'failed'=>t('Ошибка отправки'),'unknown'=>t('Результат неизвестен')][$tgRow['status']]??$tgRow['status'])?>: <?=h((string)$tgRow['total'])?></li><?php endforeach; ?></ul>
<small><?=h(t('«Принято Telegram» не означает прочтение. Неизвестный результат не повторяется автоматически, чтобы не создать дубликат.'))?></small>
<?php elseif($announcements->active($item)): ?>
<p><?=h(t('Отправить этот текст всем активным участникам, подтвердившим подписку. Если изменить объявление до отправки, оставшиеся сообщения будут отменены. Одна рассылка на объявление.'))?></p>
<?php formStart('tg_notify'); ?><input type="hidden" name="id" value="<?=h((string)$item['id'])?>"><input type="hidden" name="revision" value="<?=h((string)$item['revision'])?>"><label class="check"><input type="checkbox" name="confirmed" value="1" required><?=h(t(' Проверил текст. Отправить подписавшимся участникам.'))?></label><button><?=h(t('Оповестить в Telegram'))?></button></form>
<?php else: ?><p><?=h(t('Недоступное объявление отправить нельзя.'))?></p><?php endif; ?>
<?php }catch(Throwable $e){error_log('DKP Telegram batch: '.get_class($e)); ?><p><?=h(t('Не удалось загрузить состояние рассылки. Проверь импорт SQL.'))?></p><?php } endif; ?>
</details>
