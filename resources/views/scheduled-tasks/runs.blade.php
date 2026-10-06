{{--
    定期実行履歴（scheduled_task_runs。メニューの「履歴 → 定期実行履歴」。D-63-08）
    定期実行ごとにしぼり込める（以前は画面「定期実行」の下の「実行の記録」と、各行の「記録を見る」）
--}}

@extends('layouts.app')

@section('content')

    @php
        use App\Services\Schedule\ScheduledTaskService;
        use App\Support\DisplayTime;
        use App\Support\ScheduledTasks;
    @endphp

    <h1>定期実行履歴 @include('partials.tip', ['tip' => '実行の記録は、1年で削除します。'])</h1>

    <p><a href="{{ route('home') }}">トップページに戻る</a></p>

    {{-- 絞り込み条件：定期実行（選ぶと、すぐしぼり込む） --}}
    <form method="GET" action="{{ route('scheduled-tasks.runs') }}" class="filter-form">
        <label>定期実行：
            <select name="task" onchange="this.form.submit()">
                <option value="">すべて</option>
                {{-- メニューの「設定 → 定期実行」と同じ順 --}}
                @foreach (ScheduledTasks::MENU as $key)
                    <option value="{{ $key }}" @selected($filter === $key)>{{ ScheduledTasks::menuLabel($key) }}</option>
                @endforeach
            </select>
        </label>
        <noscript><button type="submit" class="btn-secondary">絞り込む</button></noscript>
    </form>

    <p>表示件数：{{ $runs->total() }}件（新しい順）</p>

    @if ($runs->isEmpty())
        <p>記録はまだありません。</p>
    @else
        <table class="data" style="max-width:1200px; font-size:90%;">
            <thead><tr><th>内容</th><th>きっかけ</th><th>状態</th><th>開始</th><th>終了</th><th>かかった時間</th><th>ブログ</th><th>処理</th><th>変更</th><th>問題</th><th>メモリ</th><th>結果</th></tr></thead>
            <tbody>
                @foreach ($runs as $run)
                    <tr>
                        <td>{{ ScheduledTasks::get($run->task_key) ? ScheduledTasks::menuLabel($run->task_key) : $run->label() }}</td>
                        <td>
                            {{ \App\Models\ScheduledTaskRun::TRIGGERS[$run->trigger] ?? $run->trigger }}{{ $run->requester ? "（{$run->requester->name}）" : '' }}
                            @if (($delay = $run->delaySeconds()) !== null && $delay >= 120)<br><span class="text-warn">予定より {{ ScheduledTaskService::duration($delay) }}遅れて開始</span>@endif
                        </td>
                        <td @if ($run->status === 'failed' || $run->isStale()) class="text-error" @endif>
                            {{ $run->statusLabel() }}
                            @if ($run->status === 'running' && $run->pending_jobs > 0 && ! $run->isStale())<br>（Queue の処理 {{ $run->pending_jobs }}件を待っています）@endif
                        </td>
                        <td style="white-space:nowrap;">{{ DisplayTime::format($run->started_at) }}</td>
                        <td style="white-space:nowrap;">{{ $run->finished_at ? DisplayTime::format($run->finished_at) : '-' }}</td>
                        <td style="white-space:nowrap;">{{ ScheduledTaskService::duration($run->duration_seconds) }}</td>
                        <td>{{ $run->blog_count ?? '-' }}</td>
                        <td>{{ $run->processed_count !== null ? number_format($run->processed_count) : '-' }}</td>
                        <td>{{ $run->changed_count !== null ? number_format($run->changed_count) : '-' }}</td>
                        <td @if ($run->error_count > 0) class="text-error" @endif>{{ $run->error_count }}</td>
                        <td>{{ $run->peak_memory_mb !== null ? "{$run->peak_memory_mb}MB" : '-' }}</td>
                        <td style="max-width:360px;">
                            @if ($run->error)<span class="text-error" style="white-space:pre-wrap;">{{ $run->error }}</span><br>@endif
                            {{ $run->message }}
                            @if ($run->output)
                                <details><summary>出力</summary><pre style="white-space:pre-wrap; margin:0;">{{ $run->output }}</pre></details>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        {{ $runs->links() }}
    @endif

    <section class="panel">
    <h2>件数の意味</h2>
    <ul class="text-muted">
        @foreach (ScheduledTasks::MENU as $key)
            @if ($filter === null || $filter === $key)
                <li>{{ ScheduledTasks::menuLabel($key) }}：{{ ScheduledTasks::get($key)['counts'] }}</li>
            @endif
        @endforeach
    </ul>
    </section>

@endsection
