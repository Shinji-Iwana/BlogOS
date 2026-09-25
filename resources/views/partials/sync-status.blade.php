{{--
    選択中のブログの同期の状態と、「今すぐ同期」ボタン（D-01-03、D-01-05）。

    表示する内容はDBから読んだもので、WordPress APIは呼ばない。
    同期の開始待ち・実行中は、api.sync.status を定期的に読んで表示を更新し、終わったら画面を読み込み直す。

    受け取る値：$syncStatus（App\Services\Sync\SyncStatusService::forBlog の結果）
--}}

@php
    $latestRun = $syncStatus['latest_run'];
    $changedCount = collect($latestRun['resources'] ?? [])->sum(fn ($r) => $r['created'] + $r['updated'] + $r['deleted']);
@endphp

<section style="border:1px solid #ccc; padding:10px; margin:10px 0;">

    <h2 style="margin-top:0;">同期</h2>

    <p id="sync-state">
        @switch ($syncStatus['state'])
            @case ('running')
                <strong>同期を実行中です…</strong>
                @break
            @case ('queued')
                <strong>同期の開始を待っています…</strong>
                @break
            @default
                @if ($latestRun === null)
                    まだ同期していません。
                @else
                    最後の同期：{{ \App\Support\DisplayTime::format($latestRun['finished_at'] ?? $latestRun['started_at'], 'Y-m-d H:i') }}
                    （{{ $latestRun['trigger_label'] }}）
                    ・結果：
                    @if ($latestRun['status'] === 'succeeded')
                        {{ $latestRun['status_label'] }}
                    @else
                        <strong style="color:#b00;">{{ $latestRun['status_label'] }}</strong>
                    @endif
                    ・変更：{{ $changedCount }}件
                @endif
        @endswitch
    </p>

    <p>
        未解決の問題：
        @if ($syncStatus['unresolved_issue_count'] > 0)
            <a href="{{ route('sync.issues.index') }}"><strong style="color:#b00;">{{ $syncStatus['unresolved_issue_count'] }}件</strong></a>
        @else
            なし（<a href="{{ route('sync.issues.index', ['resolved' => 1]) }}">解決済みを見る</a>）
        @endif
        ・<a href="{{ route('database.sync-runs.index') }}">同期の記録</a>
    </p>

    @error('sync')
        <p style="color:#b00;">{{ $message }}</p>
    @enderror

    <form method="POST" action="{{ route('sync.runs.store') }}">
        @csrf
        @include('partials.selected-blog-field')
        <button type="submit" @disabled($syncStatus['state'] !== 'idle')>今すぐ同期</button>
    </form>

</section>

@if ($syncStatus['state'] !== 'idle')
    <script>
        // 開始待ち・実行中の間だけ状態を読み、終わったら結果を表示するため読み込み直す
        (function () {
            const stateElement = document.getElementById('sync-state');
            const labels = { queued: '同期の開始を待っています…', running: '同期を実行中です…' };

            const poll = async function () {
                try {
                    const response = await fetch(@json(route('api.sync.status')), { headers: { 'Accept': 'application/json' } });
                    if (response.ok) {
                        const status = await response.json();
                        if (status.state === 'idle') {
                            window.location.reload();
                            return;
                        }
                        stateElement.innerHTML = '<strong>' + labels[status.state] + '</strong>';
                    }
                } catch (e) {
                    // 通信の失敗は、次の読み込みで再度試す
                }
                setTimeout(poll, 5000);
            };

            setTimeout(poll, 5000);
        })();
    </script>
@endif
