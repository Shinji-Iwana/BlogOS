{{--
    カテゴリごとの記事種類（記事の企画の画面。D-83-01）。受け取る値：$matrix（ArticleTypeMatrixService::build）

    公開中の記事を、カテゴリ（親子の順。親の行は子を含めた小計）× 記事種類で数える。カテゴリで1行にし、押すと記事種類の表を開く（D-83-03）。件数から、その記事の一覧を開く
    （ロードマップは固定ページの一覧を、カテゴリと同じスラッグで絞って開く。D-83-03）。
    収益記事の割合が目安（全体の1割）を超えたら注意を出す（止めはしない。収益記事が多すぎると、読者の信頼を落としやすいため）
--}}
@php
    $roadmaps = ['parent_roadmap', 'child_roadmap'];
    $cell = function (array $row, string $key) use ($roadmaps) {
        $count = $row['counts'][$key] ?? 0;
        if ($count === 0) {
            return '<span class="text-faint">0</span>';
        }
        // ロードマップは固定ページの一覧（カテゴリと同じスラッグで絞る）、ほかは投稿の一覧（D-83-03）
        $type = in_array($key, $roadmaps, true) ? 'pages' : 'posts';

        return '<a href="' . e(route('articles.index', array_filter(['type' => $type, 'category_id' => $row['category']?->id, 'kind' => $key]))) . '">' . $count . '</a>';
    };
@endphp
<section class="panel">
<h2 data-code="TYPES">カテゴリごとの記事種類 @include('partials.tip', ['tip' => "公開中の記事の数です。親カテゴリの行は、子カテゴリの記事も含めた数です（同じ記事は1回だけ数えます）。\n2つのカテゴリに属する投稿は、それぞれのカテゴリで数えます。ロードマップの固定ページは、カテゴリと同じスラッグのものを数えます。\n件数を押すと、その記事の一覧を開きます。"])</h2>

<p>
    公開中の記事：{{ number_format($matrix['total']) }}件
    ・収益記事：{{ number_format($matrix['totals']['revenue'] ?? 0) }}件（{{ $matrix['revenue_ratio'] !== null ? number_format($matrix['revenue_ratio'] * 100, 1) : '0.0' }}%）
    @include('partials.tip', ['tip' => '収益記事の割合の目安は、全体の' . (int) (\App\Services\Topics\ArticleTypeMatrixService::REVENUE_RATIO_GUIDE * 100) . "%までです。\n割合で検索の評価が下がるわけではありませんが、初心者向けの学習ブログで収益記事が目立つと、読者の信頼を落としやすくなります。1つのテーマにつき1記事にし、同じテーマを言い換えた収益記事を増やさないでください。"])
    @if ($matrix['revenue_over'])
        <strong class="text-warn">収益記事の割合が目安（{{ (int) (\App\Services\Topics\ArticleTypeMatrixService::REVENUE_RATIO_GUIDE * 100) }}%）を超えています。</strong>
    @endif
</p>

{{-- 記事種類の表の見出しは2段：ロードマップ（親・子）と集客記事（細分類）はまとめ、ほかは2段を通して出す（D-83-03）。
     細分類は短い名前（Know・Do・Solve・Compare・実践。括弧の説明は外す）、細分類なしは「-」（右の引き出しの幅に収めるため） --}}
@php
    $groups = [];
    foreach ($matrix['columns'] as $key => $label) {
        [$group, $groupLabel, $short] = match (true) {
            $key === 'parent_roadmap'              => ['roadmap', 'ロードマップ', '親'],
            $key === 'child_roadmap'               => ['roadmap', 'ロードマップ', '子'],
            str_starts_with($key, 'acquisition:') => ['acquisition', '集客記事', $key === 'acquisition:' ? '-' : (string) preg_replace('/（.*$/u', '', str_replace('記事', '', (string) preg_replace('/^集客（(.*)）$/u', '$1', $label)))],
            default                                => [$key, $label, null],
        };
        $groups[$group] ??= ['label' => $groupLabel, 'columns' => []];
        $groups[$group]['columns'][] = $short;
    }
    // 開いたときに出す行：各カテゴリと、最後に全体
    $matrixRows = [...$matrix['rows'], ['category' => null, 'depth' => 0, 'counts' => $matrix['totals'], 'total' => $matrix['total']]];
@endphp

{{-- カテゴリで1行（カテゴリ・合計）。行を押す（Enter・スペースでも）と、すぐ下に記事種類ごとの件数の表を開く（右の引き出しの幅でも表で見るため。D-83-03） --}}
<table class="data type-matrix" style="width:100%;">
    <thead>
        <tr><th>カテゴリ</th><th style="width:5em;">合計</th></tr>
    </thead>
    <tbody>
        @foreach ($matrixRows as $row)
            @php
                $detailId = 'type-matrix-' . ($row['category']?->id ?? 'all');
            @endphp
            <tr class="expand-row" tabindex="0" role="button" aria-expanded="false" aria-controls="{{ $detailId }}">
                {{-- 子カテゴリは字下げする --}}
                <td style="padding-left:{{ 6 + $row['depth'] * 18 }}px;">
                    @if ($row['category'] === null)
                        <strong>全体</strong>
                    @elseif ($row['depth'] === 0)
                        <strong>{{ $row['category']->name }}</strong>
                    @else
                        {{ $row['category']->name }}
                    @endif
                </td>
                <td style="text-align:right;">{{ number_format($row['total']) }}</td>
            </tr>
            <tr class="expand-row-detail" id="{{ $detailId }}" hidden>
                <td colspan="2">
                    <div style="overflow-x:auto;">
                        {{-- 開いた幅いっぱいで、10列を同じ幅にする（D-83-03） --}}
                        <table class="data type-matrix-detail" style="width:100%; table-layout:fixed;">
                            <colgroup>@foreach ($matrix['columns'] as $column)<col>@endforeach</colgroup>
                            <thead>
                                <tr>
                                    @foreach ($groups as $group)
                                        @if ($group['columns'] === [null])
                                            <th rowspan="2" style="text-align:center;">{{ $group['label'] }}</th>
                                        @else
                                            <th colspan="{{ count($group['columns']) }}" style="text-align:center;">{{ $group['label'] }}</th>
                                        @endif
                                    @endforeach
                                </tr>
                                <tr>
                                    @foreach ($groups as $group)
                                        @if ($group['columns'] !== [null])
                                            @foreach ($group['columns'] as $short)
                                                <th style="text-align:center;">{{ $short }}</th>
                                            @endforeach
                                        @endif
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    @foreach (array_keys($matrix['columns']) as $key)
                                        <td style="text-align:right;">{!! $cell($row, $key) !!}</td>
                                    @endforeach
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </td>
            </tr>
        @endforeach
    </tbody>
</table>
</section>

<script>
    // カテゴリの行を押す（Enter・スペースでも）と、すぐ下の記事種類の表を開く・閉じる（D-83-03）
    document.querySelectorAll('.type-matrix > tbody > .expand-row').forEach((row) => {
        const toggle = () => {
            const open = row.getAttribute('aria-expanded') !== 'true';
            row.setAttribute('aria-expanded', open ? 'true' : 'false');
            document.getElementById(row.getAttribute('aria-controls')).hidden = !open;
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
