{{--
    定期実行の「次の実行」と「前回」（画面「定期実行」の列と同じ情報。メニューの定期実行のポップアップに1行ずつ出す。D-63-06）
    前回は、状態と、開始の日時・かかった時間だけ（処理の件数と、記録へのリンクは出さない）
    受け取る値：$taskKey、$scheduleSettings（ScheduledTaskService::settings()）、$lastRun（App\Models\ScheduledTaskRun|null）
--}}
@php
    $nextRun = app(\App\Services\Schedule\ScheduledTaskService::class)->nextRunAt($taskKey, $scheduleSettings ?? null);
@endphp
<p class="schedule-run-info">
    次の実行：{{ $nextRun ? \App\Support\DisplayTime::format($nextRun, 'Y-m-d H:i') : '無効' }}
    <br>
    前回：
    @if ($lastRun ?? null)
        <span @if ($lastRun->status === 'failed' || $lastRun->isStale()) class="schedule-run-failed" @endif>{{ $lastRun->statusLabel() }}</span>
        （{{ \App\Support\DisplayTime::format($lastRun->started_at, 'm-d H:i') }} 開始・{{ $lastRun->finished_at ? \App\Services\Schedule\ScheduledTaskService::duration($lastRun->duration_seconds) : '処理中' }}）
    @else
        まだ実行していません
    @endif
</p>
