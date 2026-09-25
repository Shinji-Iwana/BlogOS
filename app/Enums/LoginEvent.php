<?php

namespace App\Enums;

/**
 * login_histories.event に記録する値（BLOGOS_DATABASE.md 5-1）。
 */
enum LoginEvent: string
{
    case LoginSucceeded = 'login_succeeded';
    case LoginFailed = 'login_failed';
    // 試行回数の制限によって止めた試行（D-17-06）
    case LoginLocked = 'login_locked';
    case Logout = 'logout';
}
