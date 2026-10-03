{{--
    AIが作った教材の案の一覧（D-30）：教材の情報の案と、新しい教材の候補
--}}

@extends('layouts.app')

@section('content')

    <h1>教材の案の確認（{{ $blog->display_name }}）：{{ $suggestions->count() }}件</h1>

    <p>
        <a href="{{ route('materials.index') }}">教材の一覧に戻る</a>
        ・<a href="{{ route('materials.discover.create') }}">AIで新しい教材の候補を探す</a>
    </p>

    @include('partials.flash')

    <p class="text-muted">
        AIが調べた結果です。教材の情報の案は、写す項目を選んでから教材に写します。新しい教材の候補は、アフィリエイトのリンクを付けて登録します。
        登録するまで、教材は変わりません。
    </p>

    @if ($suggestions->isEmpty())
        <p>確認待ちの案はありません。</p>
    @else
        @foreach ([\App\Enums\MaterialSuggestionType::Research, \App\Enums\MaterialSuggestionType::Candidate] as $type)
            @php $rows = $suggestions->where('type', $type); @endphp
            @continue($rows->isEmpty())
            <h2>{{ $type->label() }}：{{ $rows->count() }}件</h2>
            <div style="overflow-x:auto;">
                <table class="data">
                    <thead><tr><th>種類</th><th>名前</th><th>版・出版日</th><th>AIの理由</th><th>作った日時</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($rows as $suggestion)
                            <tr>
                                <td>{{ $suggestion->kind->label() }}</td>
                                <td>
                                    {{ $suggestion->name }}
                                    @if ($suggestion->material && $type === \App\Enums\MaterialSuggestionType::Research)
                                        <br><span class="text-muted">教材：<a href="{{ route('materials.edit', ['id' => $suggestion->material->id]) }}">{{ $suggestion->material->name }}</a></span>
                                    @endif
                                    @if ($suggestion->relatedMaterial)
                                        <br><span class="text-warn">「{{ $suggestion->relatedMaterial->name }}」の新しい版・後継</span>
                                    @endif
                                </td>
                                <td>{{ $suggestion->data['edition'] ?? '' }} {{ $suggestion->data['published_on'] ?? '' }}</td>
                                <td style="max-width:420px; white-space:pre-wrap;">{{ $suggestion->reason }}</td>
                                <td>{{ \App\Support\DisplayTime::format($suggestion->created_at) }}</td>
                                <td><a href="{{ route('materials.suggestions.show', ['id' => $suggestion->id]) }}">確認する</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endforeach
    @endif

@endsection
