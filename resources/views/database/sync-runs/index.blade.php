{{--
    WordPressとの同期履歴（sync_runs・sync_run_resources。メニューの「履歴 → WordPressとの同期履歴」）
    実行ごとに、実行時の情報だけを並べ、押すと対象ごとの表が開く（初めは全て閉じる。details）
--}}

@extends('layouts.app')

@section('content')

    <h1>WordPressとの同期履歴 @include('partials.tip', ['tip' => "選択中のブログの WordPress から、記事・カテゴリ・タグなどを取り込んだ記録です。\n実行の行を押すと、対象ごとの結果が開きます。"])</h1>

    <p><a href="{{ route('home') }}">トップページに戻る</a></p>

    @include('partials.history-last', ['label' => '最後の同期', 'at' => $latest?->started_at, 'result' => $latest?->status->label()])

    <section class="panel">
    <h2>同期の記録</h2>
    @include('partials.history-count', ['paginator' => $runs])

    @forelse ($runs as $run)
        <details class="sync-run">
            <summary>
                <strong>#{{ $run->id }}</strong>
                ・{{ $run->trigger->label() }}
                ・{{ $run->status->label() }}
                ・{{ \App\Support\DisplayTime::format($run->started_at) }} 〜 {{ $run->finished_at ? \App\Support\DisplayTime::format($run->finished_at) : '（実行中）' }}
                @if ($run->message)
                    ・{{ $run->message }}
                @endif
            </summary>

            <div style="overflow-x:auto;">
                <table class="data">
                    <thead>
                        <tr>
                            <th>対象</th>
                            <th>結果</th>
                            <th>取得</th>
                            <th>新規</th>
                            <th>変更</th>
                            <th>変更なし</th>
                            <th>削除</th>
                            <th>エラー</th>
                            <th>メッセージ</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($run->resources as $resource)
                            <tr>
                                <td>{{ $resource->resource_type }}</td>
                                <td>{{ $resource->status->label() }}</td>
                                <td>{{ $resource->fetched_count }}</td>
                                <td>{{ $resource->created_count }}</td>
                                <td>{{ $resource->updated_count }}</td>
                                <td>{{ $resource->unchanged_count }}</td>
                                <td>{{ $resource->deleted_count }}</td>
                                <td>{{ $resource->error_count }}</td>
                                <td>{{ $resource->message }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    @empty
        <p>記録はありません。</p>
    @endforelse
    {{ $runs->links() }}
    </section>

@endsection
