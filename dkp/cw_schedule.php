<?php
declare(strict_types=1);
// No secrets. Times are always Europe/Moscow, independent of the host timezone.
return [
    'enabled' => true,
    'creator_id' => 1, // Existing verified administrator account.
    'points' => 5, // Reward per participant; issued manually by an officer.
    'catch_up_minutes' => 30,
];
