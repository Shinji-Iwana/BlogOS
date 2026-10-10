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

    <table class="data">
        <tr><th style="text-align:left;">評価した主体</th><td>{{ $evaluation->evaluator_type->label() }}（{{ $evaluation->creator?->name ?? '-' }}、{{ \App\Support\DisplayTime::format($evaluation->created_at) }}）</td></tr>
        <tr><th style="text-align:left;">点数</th><td>
            <strong>{{ $evaluation->score !== null ? number_format($evaluation->score, 1) . '点' : '-' }}</strong>
            @if ($evaluation->evaluator_type === \App\Enums\EvaluatorType::Ai)（AIの点数：AIが判定できる項目だけで換算した参考値）@endif
        </td></tr>
        <tr><th style="text-align:left;">必須条件</th><td>{{ match ($evaluation->required_conditions_passed) { true => 'すべて満たす', false => '満たしていない', default => '未確定（要人間確認あり）' } }}</td></tr>
        <tr><th style="text-align:left;">記事の型の必須（★）</th><td>
            @if ($evaluation->type_failures)
                <strong class="text-error">満たしていない：{{ collect($evaluation->type_failures)->map(fn ($key) => $allItems[$key]['label'] ?? $key)->implode('、') }}</strong>
            @else
                {{ $form ? '満たしている' : '記事の型が未登録（記事の型の項目は採点していません）' }}
            @endif
        </td></tr>
        <tr><th style="text-align:left;">判定</th><td>{{ $verdict }}</td></tr>
        <tr><th style="text-align:left;">確定</th><td>
            @if ($evaluation->is_confirmed)
                <strong class="text-ok">人が確定した評価</strong>（{{ $evaluation->confirmer?->name }}、{{ \App\Support\DisplayTime::format($evaluation->confirmed_at) }}）
            @else
                未確定
            @endif
        </td></tr>
        <tr><th style="text-align:left;">品質基準</th><td>
            共通基準 {{ $evaluation->quality_common_version }}{{ $evaluation->quality_profile ? '、' . $evaluation->quality_profile . ' ' . $evaluation->quality_profile_version : '' }}
            ・記事種類 {{ $standard->articleTypes[$evaluation->article_type] ?? ($evaluation->article_type ?: '未設定') }}@if ($evaluation->article_subtype)（{{ $evaluation->article_subtype }}）@endif
            @if ($versionChanged)<strong class="text-error">（現在の品質基準とバージョンが違います）</strong>@endif
        </td></tr>
    </table>

    @if (! $evaluation->is_confirmed && $target)
        <p><a href="{{ route('evaluations.create', ['target' => $target, 'from' => $evaluation->id]) }}" class="button-link"><strong>この評価を元に、人が評価・確定する</strong></a></p>
    @endif

    {{-- 観点ごとの適合度（D-47。合否には使わない） --}}
    @if ($evaluation->axis_scores)
        <section class="panel">
        <h2 data-code="AXES">観点ごとの適合度</h2>
        <p class="text-muted">同じ判定を観点ごとに集計した割合です（合否には使いません）。低い観点と、それを下げている項目を、改善の優先度の目安にしてください。</p>
        <table class="data">
            <thead><tr><th>観点</th><th>適合度</th><th>重要度</th><th>下げている項目</th></tr></thead>
            <tbody>
                @foreach ($standard->axes as $axisKey => $axis)
                    @php
                        $value = $evaluation->axis_scores[$axisKey] ?? null;
                        $importance = $standard->importance($evaluation->article_type, $axisKey);
                        $weak = $evaluation->details->filter(fn ($d) => $d->judgment !== \App\Enums\Judgment::Good && $d->judgment !== \App\Enums\Judgment::NeedsHuman && in_array($axisKey, $allItems[$d->item_key]['axes'] ?? [], true));
                    @endphp
                    @continue($value === null)
                    <tr @if ($value < 90 && $importance === '重要') class="row-attention" @endif>
                        <td title="{{ $axis['description'] }}">{{ $axis['label'] }}</td>
                        <td style="white-space:nowrap;">
                            <span class="bar-track" style="display:inline-block; width:120px; vertical-align:middle;"><span class="{{ $value >= 90 ? 'bar-good' : ($value >= 70 ? 'bar-mid' : 'bar-bad') }}" style="display:block; height:10px; width:{{ max(0, min(100, $value)) }}%;"></span></span>
                            {{ number_format($value, 1) }}%
                        </td>
                        <td>{{ $importance }}</td>
                        <td style="font-size:90%;">{{ $weak->map(fn ($d) => ($allItems[$d->item_key]['label'] ?? $d->item_key) . '（' . $d->judgment->label() . '）')->implode('、') ?: '-' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </section>
    @endif

    @if ($evaluation->summary)
        <section class="panel">
        <h2 data-code="SUMMARY">総評</h2>
        <p style="white-space:pre-wrap;">{{ $evaluation->summary }}</p>
        </section>
    @endif

    {{-- 必須条件：採点項目と同じく、評価項目（条件＋キー）・判定の行を押すと、理由・指摘の表が開く（必須条件には配点・判定の基準がない） --}}
    <section class="panel">
    <h2 data-code="REQUIRED">必須条件</h2>
    <div style="overflow-x:auto;">
        <table class="data evaluation-items">
            <colgroup><col><col class="evaluation-items-judgment"></colgroup>
            {{-- 必須条件も、○ でなければ指摘を出し、記事改修に渡す（孤立記事でない（req.not_orphan）は、その記事の改修では直せないため渡さない。RevisionFindingService::NOT_FIXABLE_BY_REVISION） --}}
            <thead><tr><th>評価項目</th><th>判定 @include('partials.tip', ['tip' => "○ でない項目には、指摘（どこが・何が足りないか・どう直すか）を出します。記事改修は、この指摘を直すべきこととして受け取ります。\nただし、孤立記事でない（req.not_orphan）は、ほかの記事からのリンクが要るため、記事改修には渡しません（ロードマップの編集案で直します）。"])</th></tr></thead>
            <tbody>
                @foreach ($standard->required as $key => $condition)
                    @include('quality.evaluations.item-row', ['key' => $key, 'label' => $condition['label'], 'detail' => $details->get($key), 'criteria' => false, 'withPoints' => false])
                @endforeach
            </tbody>
        </table>
    </div>
    </section>

    {{-- 採点項目と指摘：分類（①〜⑪）ごとに、分類名と表に分ける。評価項目・判定・得点の行を押すと、判定の基準・理由・指摘の表が開く --}}
    <section class="panel">
    <h2 data-code="ITEMS">採点項目と指摘</h2>
    @php
        // 今の品質基準の項目の順（分類の順）に並べ、今の品質基準にない項目は最後にまとめる。判定していない項目は出さない
        $scoredItems = collect($allItems + $details->except(array_merge(array_keys($allItems), array_keys($standard->required)))->map(fn ($d) => ['category' => '（今の品質基準にない項目）', 'label' => $d->item_key])->all())
            ->filter(fn ($item, $key) => $details->has($key))
            ->groupBy('category', preserveKeys: true)
            // 分類は、品質基準の見出しの順（①〜⑪）にする。② 記事の型の項目はブログ別のファイルから読み、ほかの項目の後ろに付くため、並べ直す
            ->sortBy(fn ($items, $category) => ($position = array_search($category, array_keys($standard->categories), true)) === false ? PHP_INT_MAX : $position);
    @endphp
    @foreach ($scoredItems as $category => $items)
        {{-- 指摘の説明は、判定の列のツールチップに出す --}}
        @php
            // 分類の総得点（得点 / 配点。例：12.5点 / 15点）。点数の計算と同じく、要人間確認（点が付いていない項目）は、得点・配点のどちらにも入れない
            $scored = collect($items)->keys()->map(fn ($key) => $details->get($key))->filter(fn ($detail) => $detail->points !== null && $detail->max_points !== null);
            $points = fn ($value) => rtrim(rtrim(number_format($value, 1), '0'), '.');
            $categoryTotal = $scored->isNotEmpty() ? $points($scored->sum('points')) . '点 / ' . $points($scored->sum('max_points')) . '点' : null;
        @endphp
        <h3>{{ $category }}@if ($categoryTotal)：総得点（{{ $categoryTotal }}）@endif</h3>
        <div style="overflow-x:auto;">
            <table class="data evaluation-items">
                {{-- 列の幅は決めておく（開いても表の大きさが変わらないように。表はパネルの幅いっぱい） --}}
                <colgroup><col><col class="evaluation-items-judgment"><col class="evaluation-items-points"></colgroup>
                <thead><tr><th>評価項目</th><th>判定 @include('partials.tip', ['tip' => '○ でない項目には、指摘（どこが・何が足りないか・どう直すか）を出します。記事改修は、この指摘を直すべきこととして受け取ります。'])</th><th>得点</th></tr></thead>
                <tbody>
                    @foreach ($items as $key => $item)
                        @include('quality.evaluations.item-row', ['key' => $key, 'label' => $item['label'], 'detail' => $details->get($key), 'criteria' => $item['criteria'] ?? null, 'withPoints' => true, 'typeRequired' => $item['required'] ?? false])
                    @endforeach
                </tbody>
            </table>
        </div>
    @endforeach
    </section>

    <script>
        // 評価項目の行を押す（Enter・スペースでも）と、すぐ下の判定の基準・理由・指摘の表を開く・閉じる
        document.querySelectorAll('.evaluation-item').forEach((row) => {
            const toggle = () => {
                const detail = document.getElementById(row.getAttribute('aria-controls'));
                const open = row.getAttribute('aria-expanded') !== 'true';
                row.setAttribute('aria-expanded', open ? 'true' : 'false');
                detail.hidden = !open;
            };
            row.addEventListener('click', toggle);
            row.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    toggle();
                }
            });
        });
    </script>

@endsection
