{{--
    定期実行の確認・時刻の変更・今すぐ実行（D-44）
--}}

@extends('layouts.app')

@section('content')

    @php
        use App\Services\Schedule\ScheduledTaskService;
        use App\Support\DisplayTime;
        use App\Support\ScheduledTasks;
    @endphp

    <h1>定期実行</h1>

    @include('partials.flash')

    <p class="text-muted">
        サーバーの cron から毎分起動し、決めた時刻（日本時間）に実行します。
        いつ・有効の設定は、各行の「設定」か、メニューの「設定 → 定期実行」で変えます。変えると、次の定期実行から反映されます（サーバーでの作業は要りません）。
        実行するたびに、開始・終了・かかった時間・処理件数などを記録します。
        同期と Google の取得は Queue で処理するため、Queue の処理が終わった時点を「終了」にします。
    </p>

    <table class="data" style="max-width:1200px;">
        <thead><tr><th>内容</th><th>設定</th><th>次の実行</th><th>前回</th><th></th></tr></thead>
        <tbody>
            @foreach ($tasks as $key => $task)
                @php $last = $task['last']; @endphp
                <tr>
                    <td style="max-width:420px;">
                        {{-- 名前は、メニューとポップアップの題名と同じ（D-63-05） --}}
                        <strong>{{ ScheduledTasks::menuLabel($key) }}</strong><br>
                        <span class="text-muted" style="font-size:90%;">{{ $task['description'] }}</span>
                        @foreach ($warnings[$key] ?? [] as $warning)
                            <br><span class="text-error" style="font-size:90%;">順番の注意：{{ $warning }}</span>
                        @endforeach
                    </td>
                    <td>
                        {{-- いつ・有効は、メニューの「設定 → 定期実行」と同じポップアップで変える（D-63-04） --}}
                        @if (in_array($key, ScheduledTasks::MENU, true))
                            <button type="button" class="btn-secondary" data-modal-open="{{ ScheduledTasks::modalId($key) }}">設定</button>
                        @endif
                    </td>
                    <td style="white-space:nowrap;">{{ $task['next'] ? DisplayTime::format($task['next'], 'Y-m-d H:i') : '無効' }}</td>
                    <td style="font-size:90%;">
                        @if ($last)
                            <span @if ($last->status === 'failed' || $last->isStale()) class="text-error" @endif>{{ $last->statusLabel() }}</span>
                            （{{ DisplayTime::format($last->started_at, 'm-d H:i') }} 開始・{{ $last->finished_at ? ScheduledTaskService::duration($last->duration_seconds) : '処理中' }}）
                            @if ($last->processed_count !== null)<br>処理 {{ number_format($last->processed_count) }}件@endif
                            @if ($last->changed_count !== null)・変更 {{ number_format($last->changed_count) }}件@endif
                            @if ($last->error_count > 0)・<span class="text-error">問題 {{ $last->error_count }}件</span>@endif
                            <br><a href="{{ route('scheduled-tasks.index', ['task' => $key]) }}#runs">記録を見る</a>
                        @else
                            まだ実行していません
                        @endif
                    </td>
                    <td>
                        @if ($task['manual'])
                            <form method="POST" action="{{ route('scheduled-tasks.run', ['key' => $key]) }}" onsubmit="return confirm('「{{ ScheduledTasks::menuLabel($key) }}」を今すぐ実行しますか？');">
                                @csrf
                                <button class="btn-secondary" type="submit">今すぐ実行</button>
                            </form>
                        @else
                            <span class="text-muted" style="font-size:90%;">（料金がかかるため、ボタンはありません）</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <section class="panel">
    <h2 id="runs">実行の記録{{ $filter ? '：' . ScheduledTasks::menuLabel($filter) : '' }}</h2>
    <p>
        @if ($filter)<a href="{{ route('scheduled-tasks.index') }}#runs">すべての定期実行の記録を見る</a>@endif
        <span class="text-muted">（1年で削除します）</span>
    </p>
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
    </section>

    <section class="panel">
    <h2>件数の意味</h2>
    <ul class="text-muted">
        @foreach ($tasks as $key => $task)
            <li>{{ ScheduledTasks::menuLabel($key) }}：{{ $task['counts'] }}</li>
        @endforeach
    </ul>
    </section>

@endsection
