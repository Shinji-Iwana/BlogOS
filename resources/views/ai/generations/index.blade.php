{{--
    AI実行記録の一覧（D-07-04）
--}}

@extends('layouts.app')

@section('content')

    <h1>AI実行記録（{{ $blog->display_name }}）</h1>

    <p>
        <a href="{{ route('home') }}">トップページに戻る</a>
        ・<a href="{{ route('ai.generations.create', ['mode' => 'new_article']) }}">AIで新規記事の案を作る</a>
        ・<a href="{{ route('ai.generations.create', ['mode' => 'structure']) }}">AIで記事の構成を作る</a>
        ・<a href="{{ route('ai.batches.index') }}">まとめて実行</a>
        ・<a href="{{ route('ai.settings.edit') }}">AIの設定（自動の再評価）</a>
    </p>

    @include('partials.flash')

    <div style="overflow-x:auto;">
        <table border="1" cellpadding="4" cellspacing="0">
            <thead><tr><th>#</th><th>実行モード</th><th>対象</th><th>状態</th><th>実行方式・モデル</th><th>日時</th></tr></thead>
            <tbody>
                @forelse ($generations as $generation)
                    <tr>
                        <td><a href="{{ route('ai.generations.show', ['id' => $generation->id]) }}">{{ $generation->id }}</a></td>
                        <td>{{ $generation->purpose->label() }}{{ $generation->revision_scope ? '（' . $generation->revision_scope->label() . '）' : '' }}</td>
                        <td>{{ $generation->draft ? '編集案 #' . $generation->draft->id : (($generation->post ?? $generation->page)?->title_raw ?? '（新規）') }}</td>
                        <td>{{ $generation->status->label() }}</td>
                        <td>{{ $generation->execution_method->label() }}・{{ $generation->model ?? '-' }}</td>
                        <td>{{ \App\Support\DisplayTime::format($generation->created_at) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6">ありません。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

@endsection
