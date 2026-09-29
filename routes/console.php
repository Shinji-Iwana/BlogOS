<?php

/*
 * 定期実行（XServerのcronから `php artisan schedule:run` を毎分実行する。ARCHITECTURE 28章、D-04-07）
 *
 * 何をいつ実行するかは App\Support\ScheduledTasks（既定）と、画面「定期実行」で変えた設定（scheduled_task_settings）で決まる。
 * Scheduler への登録は bootstrap/app.php の withSchedule から App\Services\Schedule\ScheduledTaskService::register() で行う（D-44）。
 *
 * Queueの処理は、cronから `php artisan queue:work --stop-when-empty --max-time=<秒数>` を
 * 定期的に起動して行う（共用サーバーでは処理プロセスを常駐できないため）。
 */
