{{--
    品質評価の結果（D-06-02、D-07-03）

    AIの点数は「AIが判定できる項目だけで換算した点数」であり、人が確定した点数とは区別して表示する。
--}}

@extends('layouts.app')

@section('content')

    @php
        $article = $evaluation->post ?? $evaluation->page;
        $details = $evaluation->details->keyBy('item_key');
    @endphp

    <h1>品質評価 #{{ $evaluation->id }}</h1>

    <p>
        @if ($evaluation->draft)
            <a href="{{ route('drafts.edit', ['id' => $evaluation->draft->id]) }}">編集案 #{{ $evaluation->draft->id }}</a>
        @endif
        @if ($article)
            ・<a href="{{ route('articles.show', ['type' => $evaluation->post ? 'posts' : 'pages', 'id' => $article->id]) }}">記事「{{ $article->title_raw }}」</a>
        @endif
        @if ($evaluation->generation)
            ・<a href="{{ route('ai.generations.show', ['id' => $evaluation->generation->id]) }}">AI実行記録 #{{ $evaluation->generation->id }}</a>
        @endif
    </p>

    @include('partials.flash')

    <table border="1" cellpadding="4" cellspacing="0">
        <tr><th style="text-align:left;">評価した主体</th><td>{{ $evaluation->evaluator_type->label() }}（{{ $evaluation->creator?->name ?? '-' }}、{{ \App\Support\DisplayTime::format($evaluation->created_at) }}）</td></tr>
        <tr><th style="text-align:left;">点数</th><td>
            <strong>{{ $evaluation->score !== null ? number_format($evaluation->score, 1) . '点' : '-' }}</strong>
            @if ($evaluation->evaluator_type === \App\Enums\EvaluatorType::Ai)（AIの点数：AIが判定できる項目だけで換算した参考値）@endif
        </td></tr>
        <tr><th style="text-align:left;">必須条件</th><td>{{ match ($evaluation->required_conditions_passed) { true => 'すべて満たす', false => '満たしていない', default => '未確定（要人間確認あり）' } }}</td></tr>
        <tr><th style="text-align:left;">判定</th><td>{{ $verdict }}</td></tr>
        <tr><th style="text-align:left;">確定</th><td>
            @if ($evaluation->is_confirmed)
                <strong style="color:#070;">人が確定した評価</strong>（{{ $evaluation->confirmer?->name }}、{{ \App\Support\DisplayTime::format($evaluation->confirmed_at) }}）
            @else
                未確定
            @endif
        </td></tr>
        <tr><th style="text-align:left;">品質基準</th><td>
            共通基準 {{ $evaluation->quality_common_version }}{{ $evaluation->quality_profile ? '、' . $evaluation->quality_profile . ' ' . $evaluation->quality_profile_version : '' }}
            ・記事種類 {{ $standard->articleTypes[$evaluation->article_type] ?? ($evaluation->article_type ?: '未設定') }}
            @if ($versionChanged)<strong style="color:#b00;">（現在の品質基準とバージョンが違います）</strong>@endif
        </td></tr>
    </table>

    @if (! $evaluation->is_confirmed && $target)
        <p><a href="{{ route('evaluations.create', ['target' => $target, 'from' => $evaluation->id]) }}"><strong>この評価を元に、人が評価・確定する</strong></a></p>
    @endif

    @if ($evaluation->summary)
        <h2>総評</h2>
        <p style="white-space:pre-wrap;">{{ $evaluation->summary }}</p>
    @endif

    <h2>必須条件</h2>
    <table border="1" cellpadding="4" cellspacing="0">
        <thead><tr><th>キー</th><th>条件</th><th>判定</th><th>理由</th></tr></thead>
        <tbody>
            @foreach ($standard->required as $key => $condition)
                @php $detail = $details->get($key); @endphp
                <tr>
                    <td><code>{{ $key }}</code></td>
                    <td>{{ $condition['label'] }}</td>
                    <td>{{ $detail?->judgment->label() ?? '-' }}</td>
                    <td>{{ $detail?->comment }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>採点項目</h2>
    <div style="overflow-x:auto;">
        <table border="1" cellpadding="4" cellspacing="0">
            <thead><tr><th>分類</th><th>キー</th><th>評価項目</th><th>判定</th><th>得点</th><th>理由・改善点</th></tr></thead>
            <tbody>
                @foreach ($standard->items as $key => $item)
                    @php $detail = $details->get($key); @endphp
                    @continue($detail === null)
                    <tr @if ($detail->judgment !== \App\Enums\Judgment::Good) style="background:#fff8c5;" @endif>
                        <td>{{ $item['category'] }}</td>
                        <td><code>{{ $key }}</code></td>
                        <td>{{ $item['label'] }}</td>
                        <td>{{ $detail->judgment->label() }}</td>
                        <td>{{ $detail->points !== null ? rtrim(rtrim(number_format($detail->points, 1), '0'), '.') . ' / ' . $detail->max_points : '-' }}</td>
                        <td>{{ $detail->comment }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

@endsection
