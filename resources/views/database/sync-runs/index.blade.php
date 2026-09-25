{{--
    同期の記録（sync_runs・sync_run_resources）のDB確認画面
--}}

@extends('layouts.app')

@section('content')

    <h1>同期の記録（{{ $blog->display_name }}）</h1>

    <p><a href="{{ route('home') }}">トップページに戻る</a></p>

    <p>表示件数：{{ $runs->count() }}件（新しい順、最大100件）</p>

    @forelse ($runs as $run)
        <section style="border:1px solid #ccc; padding:10px; margin-bottom:10px;">
            <p style="margin-top:0;">
                <strong>#{{ $run->id }}</strong>
                ・{{ $run->trigger->label() }}
                ・{{ $run->status->label() }}
                ・{{ \App\Support\DisplayTime::format($run->started_at) }} 〜 {{ $run->finished_at ? \App\Support\DisplayTime::format($run->finished_at) : '（実行中）' }}
                @if ($run->message)
                    ・{{ $run->message }}
                @endif
            </p>

            <div style="overflow-x:auto;">
                <table border="1" cellpadding="4" cellspacing="0">
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
        </section>
    @empty
        <p>記録はありません。</p>
    @endforelse

@endsection
