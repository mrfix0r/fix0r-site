<?php
if(!isset($user,$dkp) || !$user){http_response_code(403);exit;}
$roleName=['member'=>'Участник','officer'=>'Офицер','admin'=>'Администратор'][$user['role']]??'Участник';
?>
<div class="profile-identity">
  <p class="identity"><?=h($user['nickname'])?></p>
  <p class="profile-email"><?=h($user['email'])?></p>
  <span class="profile-role"><?=h($roleName)?></span>
</div>
<nav class="profile-actions" aria-label="Разделы кабинета">
  <a class="profile-button profile-button-announcements" href="?page=announcements">Объявления гильдии <span aria-hidden="true">→</span></a>
  <a class="profile-button profile-button-primary" href="?page=events">События <span aria-hidden="true">→</span></a>
  <a class="profile-button" href="?page=auctions">Аукционы гильдии <span aria-hidden="true">→</span></a>
  <?php if(in_array($user['role'],['admin','officer'],true)): ?><a class="profile-button profile-button-manage" href="?page=manage">Управление ДКП <span aria-hidden="true">→</span></a><?php endif; ?>
</nav>
<?php require __DIR__.'/announcement_banner.php'; ?>
<div class="profile-slider" role="group" aria-label="Ближайшее событие и аукцион">
  <input class="profile-slide-choice" type="radio" name="profile-slide" id="profile-slide-event" aria-controls="profile-event-panel" checked>
  <input class="profile-slide-choice" type="radio" name="profile-slide" id="profile-slide-auction" aria-controls="profile-auction-panel">
  <div class="profile-slider-controls">
    <label for="profile-slide-event">Событие</label>
    <label for="profile-slide-auction">Аукцион</label>
  </div>
<section class="next-event profile-slide profile-slide-event" id="profile-event-panel" aria-labelledby="next-event-heading">
  <h3 id="next-event-heading">Ближайшее событие</h3>
  <?php try { $nextEvent=$dkp->nextEvent(); ?>
  <?php if($nextEvent): $nextDate=(new DateTimeImmutable('@'.$nextEvent['scheduled_at']))->setTimezone(new DateTimeZone('Europe/Moscow')); ?>
    <p class="next-event-type"><?=h(['cw'=>'ЧВ','pits'=>'Питы','gvg'=>'ГВГ','other'=>'Другое'][$nextEvent['category']]??'Событие')?> · Открыто</p>
    <p class="next-event-title"><?=h($nextEvent['title'])?></p>
    <time class="next-event-time" datetime="<?=h($nextDate->format(DateTimeInterface::ATOM))?>"><?=h($nextDate->format('d.m.Y'))?> <strong><?=h($nextDate->format('H:i'))?> МСК</strong></time>
    <p class="next-event-reward">Награда: <?=h((string)$nextEvent['points'])?> ДКП</p>
    <a class="profile-button next-event-link" href="?page=events&amp;event=<?=h((string)$nextEvent['id'])?>">Открыть событие <span aria-hidden="true">→</span></a>
  <?php else: ?><p class="next-event-empty">Пока нет предстоящих открытых событий.</p><?php endif; ?>
  <?php } catch(Throwable $e) { error_log('DKP next event: '.get_class($e)); ?><p class="next-event-empty">Не удалось загрузить ближайшее событие. Попробуй обновить страницу.</p><?php } ?>
</section>

<section class="next-event profile-slide profile-slide-auction" id="profile-auction-panel" aria-labelledby="next-auction-heading">
  <h3 id="next-auction-heading">Ближайший аукцион</h3>
  <?php try { $nextAuction=$dkp->nextAuction(); ?>
  <?php if($nextAuction): $auctionEnd=(new DateTimeImmutable('@'.$nextAuction['ends_at']))->setTimezone(new DateTimeZone('Europe/Moscow')); ?>
    <p class="next-event-type">Торги открыты · Завершится первым</p>
    <p class="next-event-title"><?=h($nextAuction['item'])?></p>
    <p class="next-event-reward"><?php if($nextAuction['highest_member']!==null): ?>Текущая ставка: <strong><?=h((string)$nextAuction['highest_bid'])?> ДКП</strong><?php else: ?>Ставок пока нет · Начальная цена: <strong><?=h((string)$nextAuction['minimum'])?> ДКП</strong><?php endif; ?></p>
    <p class="next-event-type">Окончание торгов</p>
    <time class="next-event-time" datetime="<?=h($auctionEnd->format(DateTimeInterface::ATOM))?>"><?=h($auctionEnd->format('d.m.Y'))?> <strong><?=h($auctionEnd->format('H:i'))?> МСК</strong></time>
    <p class="next-event-reward">Время окончания может продлеваться при поздних ставках.</p>
    <a class="profile-button next-event-link" href="?page=auctions&amp;auction=<?=h((string)$nextAuction['id'])?>">Открыть аукцион <span aria-hidden="true">→</span></a>
  <?php else: ?><p class="next-event-empty">Сейчас нет открытых аукционов.</p><?php endif; ?>
  <?php } catch(Throwable $e) { error_log('DKP next auction: '.get_class($e)); ?><p class="next-event-empty">Не удалось загрузить ближайший аукцион. Попробуй обновить страницу.</p><?php } ?>
</section>


</div>


