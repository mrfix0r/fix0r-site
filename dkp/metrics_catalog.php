<?php
declare(strict_types=1);

// Only these identifiers can enter analytics. Never accept URLs or button text.
function fc_metrics_pages(): array {
    return ['home'=>'Главная','dkp.login'=>'Вход в ДКП','dkp.register'=>'Регистрация ДКП',
        'dkp.forgot'=>'Восстановление доступа','dkp.resend'=>'Повтор письма',
        'dkp.profile'=>'Личный кабинет','dkp.events'=>'События ДКП',
        'dkp.auctions'=>'Аукционы ДКП','dkp.announcements'=>'Объявления ДКП','dkp.manage'=>'Управление ДКП'];
}
function fc_metrics_actions(): array {
    return ['out.telegram'=>'Telegram','out.twitch'=>'Twitch','out.discord'=>'Discord',
        'out.teamspeak'=>'TeamSpeak 3','out.boosty'=>'Boosty','out.wishlist'=>'Вишлист',
        'nav.home'=>'Переход на главную','nav.stream'=>'Переход к плееру','nav.about'=>'О себе',
        'nav.schedule'=>'Расписание эфиров','nav.code'=>'Наш кодекс','nav.explore'=>'Найти своё место',
        'nav.dkp'=>'Переход в ДКП','dkp.profile'=>'Личный кабинет','dkp.events'=>'События ДКП',
        'dkp.auctions'=>'Аукционы ДКП','dkp.announcements'=>'Объявления ДКП','dkp.manage'=>'Управление ДКП',
        'dkp.login'=>'Вход в ДКП','dkp.register'=>'Регистрация ДКП','dkp.forgot'=>'Восстановление доступа',
        'dkp.resend'=>'Повтор письма','tab.streams'=>'Вкладка: стримы','tab.community'=>'Вкладка: сообщество',
        'tab.support'=>'Вкладка: поддержка','tab.setup'=>'Вкладка: сетап','theme.toggle'=>'Смена темы',
        'language.ru'=>'Русский язык','language.en'=>'Английский язык','lantern.toggle'=>'Фонарь',
        'about.story'=>'История PUBG','about.tournaments'=>'Список турниров','setup.expand'=>'Полный сетап',
        'dkp.slide.event'=>'Ближайшее событие','dkp.slide.auction'=>'Ближайший аукцион'];
}
function fc_metrics_areas(): array {
    return ['header'=>'Шапка','announcement'=>'Блок анонса','hero'=>'Первый экран',
        'player'=>'Плеер','about'=>'О себе','schedule'=>'Расписание','community'=>'Сообщество',
        'support'=>'Поддержка','explore'=>'Раздел интересов','footer'=>'Подвал','dkp'=>'Кабинет ДКП'];
}
function fc_metrics_admin(mixed $user): bool {
    return is_array($user) && ($user['role']??null)==='admin' && !empty($user['verified_at']);
}
function fc_metrics_batch(mixed $data): array {
    if (!is_array($data) || array_diff(array_keys($data),['visitor','events']) ||
        !is_string($data['visitor']??null) || !preg_match('/^[a-f0-9]{32}$/D',$data['visitor']) ||
        !is_array($data['events']??null) || !array_is_list($data['events']) ||
        count($data['events'])<1 || count($data['events'])>20) throw new InvalidArgumentException('Invalid batch');
    foreach ($data['events'] as $event) {
        if (!is_array($event) || count($event)!==5 || array_diff(array_keys($event),['id','kind','item','area','lang'])) throw new InvalidArgumentException('Invalid event');
        foreach ($event as $value) if (!is_string($value)) throw new InvalidArgumentException('Invalid value');
        if (!preg_match('/^[a-f0-9]{32}$/D',$event['id']) || !in_array($event['lang'],['ru','en'],true)) throw new InvalidArgumentException('Invalid identifier');
        if ($event['kind']==='view') {
            if (!isset(fc_metrics_pages()[$event['item']]) || $event['area']!=='page') throw new InvalidArgumentException('Invalid page');
        } elseif ($event['kind']==='click') {
            if (!isset(fc_metrics_actions()[$event['item']],fc_metrics_areas()[$event['area']])) throw new InvalidArgumentException('Invalid click');
        } else throw new InvalidArgumentException('Invalid kind');
    }
    return $data;
}
