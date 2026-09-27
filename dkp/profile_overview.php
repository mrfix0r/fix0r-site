<?php
require_once __DIR__.'/i18n.php';
if(!isset($user,$dkp) || !$user){http_response_code(403);exit;}
$roleName=['member'=>t('Участник'),'officer'=>t('Офицер'),'admin'=>t('Администратор')][$user['role']]??t('Участник');
?>
<div class="profile-identity">
  <p class="identity"><?=h($user['nickname'])?></p>
  <p class="profile-email"><?=h($user['email'])?></p>
  <span class="profile-role"><?=h($roleName)?></span>
</div>
<nav class="profile-actions" aria-label="<?=h(t('Разделы кабинета'))?>">
  <a class="profile-button profile-button-announcements" href="?page=announcements"><?=h(t('Объявления гильдии '))?><span aria-hidden="true">→</span></a>
  <a class="profile-button profile-button-primary" href="?page=events"><?=h(t('События '))?><span aria-hidden="true">→</span></a>
  <a class="profile-button" href="?page=auctions"><?=h(t('Аукционы гильдии '))?><span aria-hidden="true">→</span></a>
  <?php if(in_array($user['role'],['admin','officer'],true)): ?><a class="profile-button profile-button-manage" href="?page=manage"><?=h(t('Управление ДКП '))?><span aria-hidden="true">→</span></a><?php endif; ?>
</nav>
<?php require __DIR__.'/announcement_banner.php'; ?>
<div class="profile-slider" role="group" aria-label="<?=h(t('Ближайшее событие и аукцион'))?>">
  <input class="profile-slide-choice" type="radio" name="profile-slide" id="profile-slide-event" aria-controls="profile-event-panel" checked>
  <input class="profile-slide-choice" type="radio" name="profile-slide" id="profile-slide-auction" aria-controls="profile-auction-panel">
  <div class="profile-slider-controls">
    <label for="profile-slide-event"><?=h(t('Событие'))?></label>
    <label for="profile-slide-auction"><?=h(t('Аукцион'))?></label>
  </div>
<section class="next-event profile-slide profile-slide-event" id="profile-event-panel" aria-labelledby="next-event-heading">
  <h3 id="next-event-heading"><?=h(t('Ближайшее событие'))?></h3>
  <?php try { $nextEvent=$dkp->nextEvent(); ?>
  <?php if($nextEvent): $nextDate=(new DateTimeImmutable('@'.$nextEvent['scheduled_at']))->setTimezone(new DateTimeZone('Europe/Moscow')); ?>
    <p class="next-event-type"><?=h(['cw'=>t('ЧВ'),'pits'=>t('Питы'),'gvg'=>t('ГВГ'),'other'=>t('Другое')][$nextEvent['category']]??t('Событие'))?><?=h(t(' · Открыто'))?></p>
    <p class="next-event-title"><?=h($nextEvent['title'])?></p>
    <time class="next-event-time" datetime="<?=h($nextDate->format(DateTimeInterface::ATOM))?>"><?=h($nextDate->format('d.m.Y'))?> <strong><?=h($nextDate->format('H:i'))?><?=h(t(' МСК'))?></strong></time>
    <p class="next-event-reward"><?=h(t('Награда: '))?><?=h((string)$nextEvent['points'])?><?=h(t(' ДКП'))?></p>
    <a class="profile-button next-event-link" href="?page=events&amp;event=<?=h((string)$nextEvent['id'])?>"><?=h(t('Открыть событие '))?><span aria-hidden="true">→</span></a>
  <?php else: ?><p class="next-event-empty"><?=h(t('Пока нет предстоящих открытых событий.'))?></p><?php endif; ?>
  <?php } catch(Throwable $e) { error_log('DKP next event: '.get_class($e)); ?><p class="next-event-empty"><?=h(t('Не удалось загрузить ближайшее событие. Попробуй обновить страницу.'))?></p><?php } ?>
</section>

<section class="next-event profile-slide profile-slide-auction" id="profile-auction-panel" aria-labelledby="next-auction-heading">
  <h3 id="next-auction-heading"><?=h(t('Ближайший аукцион'))?></h3>
  <?php try { $nextAuction=$dkp->nextAuction(); ?>
  <?php if($nextAuction): $auctionEnd=(new DateTimeImmutable('@'.$nextAuction['ends_at']))->setTimezone(new DateTimeZone('Europe/Moscow')); ?>
    <p class="next-event-type"><?=h(t('Торги открыты · Завершится первым'))?></p>
    <p class="next-event-title"><?=h($nextAuction['item'])?></p>
    <p class="next-event-reward"><?php if($nextAuction['highest_member']!==null): ?><?=h(t('Текущая ставка: '))?><strong><?=h((string)$nextAuction['highest_bid'])?><?=h(t(' ДКП'))?></strong><?php else: ?><?=h(t('Ставок пока нет · Начальная цена: '))?><strong><?=h((string)$nextAuction['minimum'])?><?=h(t(' ДКП'))?></strong><?php endif; ?></p>
    <p class="next-event-type"><?=h(t('Окончание торгов'))?></p>
    <time class="next-event-time" datetime="<?=h($auctionEnd->format(DateTimeInterface::ATOM))?>"><?=h($auctionEnd->format('d.m.Y'))?> <strong><?=h($auctionEnd->format('H:i'))?><?=h(t(' МСК'))?></strong></time>
    <p class="next-event-reward"><?=h(t('Время окончания может продлеваться при поздних ставках.'))?></p>
    <a class="profile-button next-event-link" href="?page=auctions&amp;auction=<?=h((string)$nextAuction['id'])?>"><?=h(t('Открыть аукцион '))?><span aria-hidden="true">→</span></a>
  <?php else: ?><p class="next-event-empty"><?=h(t('Сейчас нет открытых аукционов.'))?></p><?php endif; ?>
  <?php } catch(Throwable $e) { error_log('DKP next auction: '.get_class($e)); ?><p class="next-event-empty"><?=h(t('Не удалось загрузить ближайший аукцион. Попробуй обновить страницу.'))?></p><?php } ?>
</section>


</div>


