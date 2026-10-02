{{--
    品質評価の結果（D-06-02、D-07-03）

    AIの点数は「AIが判定できる項目だけで換算した点数」であり、人が確定した点数とは区別して表示する。
--}}

@extends('layouts.app')

@section('content')

    @php
        $article = $evaluation->post ?? $evaluation->page;
        $details = $evaluation->details->keyBy('item_key');
        $allItems = $standard->allItems();
        $form = $standard->formFor($evaluation->article_type, $evaluation->article_subtype);
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
        <tr><th style="text-align:left;">記事の型の必須（★）</th><td>
            @if ($evaluation->type_failures)
                <strong style="color:#b00;">満たしていない：{{ collect($evaluation->type_failures)->map(fn ($key) => $allItems[$key]['label'] ?? $key)->implode('、') }}</strong>
            @else
                {{ $form ? '満たしている' : '記事の型が未登録（記事の型の項目は採点していません）' }}
            @endif
        </td></tr>
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
            ・記事種類 {{ $standard->articleTypes[$evaluation->article_type] ?? ($evaluation->article_type ?: '未設定') }}@if ($evaluation->article_subtype)（{{ $evaluation->article_subtype }}）@endif
            @if ($versionChanged)<strong style="color:#b00;">（現在の品質基準とバージョンが違います）</strong>@endif
        </td></tr>
    </table>

    @if (! $evaluation->is_confirmed && $target)
        <p><a href="{{ route('evaluations.create', ['target' => $target, 'from' => $evaluation->id]) }}"><strong>この評価を元に、人が評価・確定する</strong></a></p>
    @endif

    {{-- 観点ごとの適合度（D-47。合否には使わない） --}}
    @if ($evaluation->axis_scores)
        <h2>観点ごとの適合度</h2>
        <p style="color:#666;">同じ判定を観点ごとに集計した割合です（合否には使いません）。低い観点と、それを下げている項目を、改善の優先度の目安にしてください。</p>
        <table border="1" cellpadding="4" cellspacing="0">
            <thead><tr><th>観点</th><th>適合度</th><th>重要度</th><th>下げている項目</th></tr></thead>
            <tbody>
                @foreach ($standard->axes as $axisKey => $axis)
                    @php
                        $value = $evaluation->axis_scores[$axisKey] ?? null;
                        $importance = $standard->importance($evaluation->article_type, $axisKey);
                        $weak = $evaluation->details->filter(fn ($d) => $d->judgment !== \App\Enums\Judgment::Good && $d->judgment !== \App\Enums\Judgment::NeedsHuman && in_array($axisKey, $allItems[$d->item_key]['axes'] ?? [], true));
                    @endphp
                    @continue($value === null)
                    <tr @if ($value < 90 && $importance === '重要') style="background:#fff8c5;" @endif>
                        <td title="{{ $axis['description'] }}">{{ $axis['label'] }}</td>
                        <td style="white-space:nowrap;">
                            <span style="display:inline-block; width:120px; background:#eee; vertical-align:middle;"><span style="display:block; height:10px; width:{{ max(0, min(100, $value)) }}%; background:{{ $value >= 90 ? '#4a90e2' : ($value >= 70 ? '#e8a33d' : '#d9534f') }};"></span></span>
                            {{ number_format($value, 1) }}%
                        </td>
                        <td>{{ $importance }}</td>
                        <td style="font-size:90%;">{{ $weak->map(fn ($d) => ($allItems[$d->item_key]['label'] ?? $d->item_key) . '（' . $d->judgment->label() . '）')->implode('、') ?: '-' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
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

    <h2>採点項目と指摘</h2>
    <p style="color:#666;">○ でない項目には、指摘（どこが・何が足りないか・どう直すか）を出します。記事改修は、この指摘を直すべきこととして受け取ります。</p>
    <div style="overflow-x:auto;">
        <table border="1" cellpadding="4" cellspacing="0">
            <thead><tr><th>分類</th><th>評価項目（判定の基準）</th><th>判定</th><th>得点</th><th>理由</th><th>指摘</th></tr></thead>
            <tbody>
                @foreach ($allItems + $details->except(array_merge(array_keys($allItems), array_keys($standard->required)))->map(fn ($d) => ['category' => '（今の品質基準にない項目）', 'label' => $d->item_key])->all() as $key => $item)
                    @php $detail = $details->get($key); @endphp
                    @continue($detail === null)
                    <tr @if ($detail->judgment !== \App\Enums\Judgment::Good) style="background:#fff8c5;" @endif>
                        <td style="white-space:nowrap;">{{ $item['category'] }}</td>
                        <td style="max-width:360px;">
                            {{ $item['label'] }}@if ($item['required'] ?? false)<strong>（★必須）</strong>@endif <code style="font-size:80%;">{{ $key }}</code>
                            @if ($item['criteria'] ?? null)<br><span style="color:#666; font-size:90%;">{{ $item['criteria'] }}</span>@endif
                        </td>
                        <td>{{ $detail->judgment->label() }}</td>
                        <td style="white-space:nowrap;">{{ $detail->points !== null ? rtrim(rtrim(number_format($detail->points, 1), '0'), '.') . ' / ' . $detail->max_points : '-' }}</td>
                        <td style="max-width:300px;">{{ $detail->comment }}</td>
                        <td style="max-width:360px; font-size:90%;">
                            @if ($detail->location)<strong>どこが：</strong>{{ $detail->location }}<br>@endif
                            @if ($detail->problem)<strong>何が足りないか：</strong>{{ $detail->problem }}<br>@endif
                            @if ($detail->fix)<strong>どう直すか：</strong>{{ $detail->fix }}@endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

@endsection
