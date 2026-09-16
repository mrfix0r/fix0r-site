<?php
if (!isset($auth,$user) || !$user) { http_response_code(403); exit; }
try {
    $link=$auth->query('SELECT member_id FROM fc_web_links WHERE web_user_id=?',[(int)$user['id']])->fetch();
    if (!$link) echo '<div class="notice"><strong>Подтверждение участника</strong><br>Обратись к главе гильдии: после проверки он привяжет твой аккаунт к игровому профилю.</div>';
    else {
        $id=(string)$link['member_id'];$wallet=$dkp->wallet($id);$balance=$wallet['total'];
        $hp=filter_var($_GET['hp']??1,FILTER_VALIDATE_INT);$hp=max(1,min((int)$hp,100000));
        $history=$dkp->history($id,$hp);$more=count($history)>30;$history=array_slice($history,0,30);
        echo '<div class="notice"><strong>'.h((string)$balance).' ДКП</strong><br>В ставках: '.h((string)$wallet['reserved']).' · Доступно: '.h((string)$wallet['available']).'<br>Перенесённые очки и операции на сайте.</div>';
        echo '<details'.($hp>1?' open':'').'><summary>История ДКП</summary>';
        if (!$history) echo '<p>Операций на этой странице нет.</p>';
        foreach ($history as $row) {
            $amount=(int)$row['amount'];$date=(new DateTimeImmutable('@'.$row['created_at']))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('d.m.Y H:i');
            echo '<p><strong>'.($amount>0?'+':'').h((string)$amount).' ДКП</strong> · '.h($date).' МСК<br>'.h($row['reason']).'<br><small>'.($row['origin']==='bot'?'Перенесено из бота':'Операция на сайте').'</small></p>';
        }
        if($hp>1)echo '<a href="?page=profile&amp;hp='.($hp-1).'">← Новее</a> ';
        if($more)echo '<a href="?page=profile&amp;hp='.($hp+1).'">Ранее →</a>';
        echo '</details>';
    }
} catch(Throwable $e) { error_log('DKP view: '.get_class($e).' code='.$e->getCode());echo '<div class="error">Не удалось загрузить ДКП. Сообщи администратору сайта.</div>'; }
