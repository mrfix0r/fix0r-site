<?php
if(!isset($user,$announcements) || !$user){http_response_code(403);exit;}
try { $pinned=$announcements->pinned($user);if($pinned): ?>
<details class="announcement-banner">
<summary><span class="announcement-kicker">Важное объявление</span><strong><?=h($pinned['title'])?></strong><small><?=h((new DateTimeImmutable('@'.$pinned['created_at']))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('d.m.Y'))?> · Раскрыть</small></summary>
<div class="announcement-body"><?=h($pinned['body'])?></div>
<?php if((int)$pinned['requires_ack']): ?><p class="announcement-meta"><?=$announcements->acknowledged($pinned,(int)$user['id'])?'✓ Прочтение подтверждено':'Открой объявление и подтверди прочтение.'?></p><?php endif; ?>
<a class="profile-button" href="?page=announcements&amp;announcement=<?=h((string)$pinned['id'])?>">Открыть объявление <span aria-hidden="true">→</span></a>
</details>
<?php endif; }catch(Throwable $e){error_log('DKP announcement banner: '.get_class($e));} ?>
