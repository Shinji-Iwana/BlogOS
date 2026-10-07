{{--
    Googleとの同期履歴（google_fetch_runs と、保存している行数。メニューの「履歴 → Googleとの同期履歴」。D-63-19）

    以前は画面「Google連携」の下の欄。Googleアカウントの接続と対応先は、メニューの「設定 → Google」のポップアップ（google/modals）。
--}}

@extends('layouts.app')

@section('content')

    <h1>Googleとの同期履歴 @include('partials.tip', ['tip' => "選択中のブログの、Google（GA4・Search Console・AdSense）からデータを取得した記録と、保存しているデータの行数です。"])</h1>

    <p><a href="{{ route('home') }}">トップページに戻る</a></p>

    @include('partials.flash')

    <section class="panel">
    <h2>取得の記録</h2>
    @include('partials.history-count', ['paginator' => $recentRuns])
    <div style="overflow-x:auto;">
        <table class="data">
            <thead><tr><th>日時</th><th>サービス</th><th>契機</th><th>結果</th><th>期間</th><th>行数</th><th>メッセージ</th></tr></thead>
            <tbody>
                @forelse ($recentRuns as $run)
                    <tr>
                        <td>{{ \App\Support\DisplayTime::format($run->started_at) }}</td>
                        <td>{{ $run->service->label() }}</td>
                        <td>{{ $run->trigger->label() }}</td>
                        <td>{{ $run->status->label() }}</td>
                        <td>{{ $run->date_from?->toDateString() }}〜{{ $run->date_to?->toDateString() }}</td>
                        <td>{{ $run->row_count }}</td>
                        <td>
                            {{ $run->message }}
                            @if ($run->error_body)
                                <details><summary>応答本文（HTTP {{ $run->error_status }}）</summary><pre style="white-space:pre-wrap; word-break:break-all; max-height:200px; overflow:auto;">{{ $run->error_body }}</pre></details>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7">ありません。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $recentRuns->links() }}
    </section>

    <section class="panel">
    <h2>保存している行数</h2>
    <ul>
        @foreach ($counts as $table => $count)
            <li>{{ $table }}：{{ number_format($count) }}</li>
        @endforeach
    </ul>
    </section>

@endsection
