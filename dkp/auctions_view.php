<?php
require_once __DIR__.'/i18n.php';
if(!isset($user,$dkp) || !$user){http_response_code(403);exit;}
$staff=in_array($user['role'],['admin','officer'],true);
$states=['open'=>t('Ставки открыты'),'closed'=>t('Завершён'),'cancelled'=>t('Отменён')];
$myLink=$auth->query('SELECT member_id FROM fc_web_links WHERE web_user_id=?',[$user['id']])->fetch();
function auctionHidden(array $lot):void {echo '<input type="hidden" name="auction" value="'.h((string)$lot['id']).'"><input type="hidden" name="revision" value="'.h((string)$lot['revision']).'">';}
function lotDate(int $ts):string{return (new DateTimeImmutable('@'.$ts))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('d.m.Y H:i:s');}
?>
<p><a href="?page=profile"><?=h(t('← Личный кабинет'))?></a> · <a href="?page=auctions"><?=h(t('Все аукционы'))?></a><?php if($staff): ?> · <a href="?page=manage"><?=h(t('Управление'))?></a><?php endif; ?></p>
<?php if($myLink):$wallet=$dkp->wallet((string)$myLink['member_id']); ?>
<div class="notice"><strong><?=h(t('Доступно: '))?><?=h((string)$wallet['available'])?><?=h(t(' ДКП'))?></strong><br><?=h(t('Всего: '))?><?=h((string)$wallet['total'])?><?=h(t(' · В ставках: '))?><?=h((string)$wallet['reserved'])?></div>
<?php else: ?><p class="notice"><?=h(t('Для ставок попроси главу гильдии привязать твой аккаунт к игровому профилю.'))?></p><?php endif; ?>
<?php if($staff):$last=$auth->query('SELECT last_success FROM fc_scheduler WHERE id=1')->fetchColumn(); ?>
<p class="<?=!$last || time()-(int)$last>180?'error':'notice'?>"><?php if(!$last || time()-(int)$last>180): ?><?=h(t('Планировщик не подтверждал работу последние 3 минуты. Проверь Cron на хостинге. До списания ставки остаются в резерве; открытие кабинета также запускает завершение.'))?><?php else: ?><?=h(t('Планировщик: успешно '))?><?=h(lotDate((int)$last))?><?=h(t(' МСК.'))?><?php endif; ?></p>
<?php endif; ?>
<?php if(isset($_GET['auction'])):
 if(!is_string($_GET['auction']))throw new AuthError(t('Некорректный аукцион.'));
 $bp=filter_var($_GET['bp']??1,FILTER_VALIDATE_INT);$bp=max(1,min((int)$bp,100000));$lot=$dkp->auction($_GET['auction'],$bp);$canBid=$lot['status']==='open' && $lot['ends_at']>$dkp->now(); ?>
<div class="eyebrow"><?=h(t('ЛОТ #'))?><?=h((string)$lot['id'])?></div><h3><?=h($lot['item'])?></h3>
<span class="status"><?=h($lot['status']==='open' && !$canBid?t('Время истекло · завершается'):($states[$lot['status']]??$lot['status']))?></span>
<p><?=h(t('Окончание: '))?><strong><?=h(lotDate((int)$lot['ends_at']))?><?=h(t(' МСК'))?></strong><br><?=h(t('Минимум: '))?><?=h((string)$lot['minimum'])?><?=h(t(' ДКП · Шаг: '))?><?=h((string)$lot['bid_step'])?><?=h(t(' ДКП'))?></p>
<?php if($lot['highest_member']!==null): ?><p><strong><?=h($lot['leader'])?> — <?=h((string)$lot['highest_bid'])?><?=h(t(' ДКП'))?></strong><br><?=$lot['status']==='closed'?t('Победитель. ДКП списаны. Предмет передаёт офицер в игре.'):t('Текущая ведущая ставка.')?></p><?php elseif($lot['status']==='closed'): ?><p><?=h(t('Ставок не было. ДКП не списывались.'))?></p><?php else: ?><p><?=h(t('Ставок пока нет.'))?></p><?php endif; ?>
<?php if($canBid && $myLink):$needed=$lot['highest_member']===null?(int)$lot['minimum']:(int)$lot['highest_bid']+(int)$lot['bid_step']; ?>
<div class="award-box"><h3><?=h(t('Сделать ставку'))?></h3><?php if($needed<=1000000): ?>
<?php dkpForm('auc_bid');auctionHidden($lot); ?><label><?=h(t('Твоя полная ставка, ДКП'))?><input type="number" name="amount" required step="1" min="<?=$needed?>" max="1000000" value="<?=$needed?>"></label>
<p><?=h(t('Укажи итоговую сумму, не прибавку. Если ставка в последние 2 минуты принята, до конца останется не меньше 2 минут.'))?></p>
<label class="check"><input type="checkbox" name="confirmed" value="1" required><?=h(t(' Подтверждаю ставку. При победе эта сумма будет списана.'))?></label><button><?=h(t('Поставить ДКП'))?></button></form>
<?php else: ?><p><?=h(t('Достигнута максимальная ставка.'))?></p><?php endif; ?></div><?php endif; ?>
<p><a href="?page=auctions&amp;auction=<?=h((string)$lot['id'])?>"><?=h(t('Обновить ставки и время →'))?></a></p>
<?php if($staff && $canBid && $lot['highest_member']===null): ?><details><summary><?=h(t('Отменить лот без ставок'))?></summary><?php dkpForm('auc_cancel');auctionHidden($lot); ?><label><?=h(t('Причина'))?><input name="reason" required maxlength="500"></label><label class="check"><input type="checkbox" name="confirmed" value="1" required><?=h(t(' Подтверждаю отмену.'))?></label><button class="secondary"><?=h(t('Отменить лот'))?></button></form></details><?php endif; ?>
<?php if($lot['cancel_reason']): ?><p><?=h(t('Причина отмены: '))?><?=h($lot['cancel_reason'])?></p><?php endif; ?>
<details open><summary><?=h(t('История ставок'))?></summary><?php $more=count($lot['bids'])>20;foreach(array_slice($lot['bids'],0,20) as $bid): ?><p><strong><?=h($bid['nickname'])?> · <?=h((string)$bid['amount'])?><?=h(t(' ДКП'))?></strong><br><small><?=h(lotDate((int)$bid['created_at']))?><?=h(t(' МСК'))?></small></p><?php endforeach; ?>
<?php if($bp>1): ?><a href="?page=auctions&amp;auction=<?=h((string)$lot['id'])?>&amp;bp=<?=$bp-1?>"><?=h(t('← Новее'))?></a><?php endif; ?> <?php if($more): ?><a href="?page=auctions&amp;auction=<?=h((string)$lot['id'])?>&amp;bp=<?=$bp+1?>"><?=h(t('Ранее →'))?></a><?php endif; ?></details>
<?php else:
 $ap=filter_var($_GET['ap']??1,FILTER_VALIDATE_INT);$ap=max(1,min((int)$ap,100000));$lots=$dkp->auctions($ap);$more=count($lots)>20; ?>
<p><?=h(t('Ведущие ставки резервируют очки. Когда твою ставку перебивают, резерв освобождается. При победе списывается только твоя последняя ставка.'))?></p>
<?php if($staff): ?><details><summary><?=h(t('Создать аукцион'))?></summary><?php dkpForm('auc_create'); ?><label><?=h(t('Предмет'))?><input name="item" required maxlength="160" placeholder="<?=h(t('Название предмета и характеристики'))?>"></label><div class="form-grid"><label><?=h(t('Минимальная ставка'))?><input type="number" name="minimum" min="1" max="1000000" required value="1"></label><label><?=h(t('Минимальный шаг'))?><input type="number" name="step" min="1" max="1000000" required value="1"></label></div><label><?=h(t('Длительность, минут'))?><input type="number" name="minutes" min="1" max="10080" required value="30"></label><p><?=h(t('После создания условия не меняются. Отменить можно только лот без ставок до окончания времени.'))?></p><button><?=h(t('Открыть аукцион'))?></button></form></details><?php endif; ?>
<?php if(!$lots): ?><p><?=h(t('На этой странице пока нет аукционов.'))?></p><?php endif; ?>
<?php foreach(array_slice($lots,0,20) as $lot): ?><article class="event-card"><div class="eyebrow"><?=h(t('ЛОТ #'))?><?=h((string)$lot['id'])?></div><h3><a href="?page=auctions&amp;auction=<?=h((string)$lot['id'])?>"><?=h($lot['item'])?> →</a></h3><span class="status"><?=h($lot['status']==='open' && $lot['ends_at']<=$dkp->now()?t('Время истекло · завершается'):($states[$lot['status']]??$lot['status']))?></span><p><?=h(lotDate((int)$lot['ends_at']))?><?=h(t(' МСК'))?><br><?=$lot['highest_member']===null?t('Без ставок'):h($lot['leader']).' · '.h((string)$lot['highest_bid']).t(' ДКП')?></p></article><?php endforeach; ?>
<nav><?php if($ap>1): ?><a href="?page=auctions&amp;ap=<?=$ap-1?>"><?=h(t('← Новее'))?></a><?php endif; ?><?php if($more): ?><a href="?page=auctions&amp;ap=<?=$ap+1?>"><?=h(t('Ранее →'))?></a><?php endif; ?></nav>
<?php endif; ?>
