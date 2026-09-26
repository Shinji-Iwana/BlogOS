{{--
    まとめて実行の一覧（手動のまとめて実行と、自動の再評価。D-25）
--}}

@extends('layouts.app')

@section('content')

    <h1>AIのまとめて実行（{{ $blog->display_name }}）</h1>

    <p>
        <a href="{{ route('home') }}">トップページに戻る</a>
        ・<a href="{{ route('ai.batches.create', ['mode' => 'quality_diagnosis']) }}">品質診断をまとめて実行する</a>
        ・<a href="{{ route('ai.batches.create', ['mode' => 'revision']) }}">記事改修をまとめて実行する</a>
        ・<a href="{{ route('ai.batches.create', ['mode' => 'management_suggestion']) }}">管理情報の案をまとめて作る</a>
        ・<a href="{{ route('ai.settings.edit') }}">AIの設定（自動の再評価）</a>
        ・<a href="{{ route('ai.generations.index') }}">AI実行記録</a>
    </p>

    @include('partials.flash')

    <div style="overflow-x:auto;">
        <table border="1" cellpadding="4" cellspacing="0">
            <thead><tr><th>#</th><th>実行モード</th><th>きっかけ</th><th>対象</th><th>モデル</th><th>状態</th><th>進み具合</th><th>費用の目安</th><th>日時</th></tr></thead>
            <tbody>
                @forelse ($batches as [$batch, $progress])
                    @php $finished = array_sum(array_intersect_key($progress['counts'], array_flip(['succeeded', 'failed', 'skipped']))); @endphp
                    <tr>
                        <td><a href="{{ route('ai.batches.show', ['id' => $batch->id]) }}">{{ $batch->id }}</a></td>
                        <td>{{ $batch->purpose->label() }}</td>
                        <td>{{ $batch->trigger->label() }}</td>
                        <td>{{ $batch->target->label() }}</td>
                        <td>{{ $batch->model }}・{{ $batch->reasoning_effort }}</td>
                        <td>{{ $batch->status->label() }}</td>
                        <td>{{ $finished }} / {{ $batch->total_count }}（失敗 {{ $progress['counts']['failed'] ?? 0 }}）</td>
                        <td>${{ number_format($progress['cost'], 4) }}</td>
                        <td>{{ \App\Support\DisplayTime::format($batch->created_at) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9">まだ実行していません。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

@endsection
