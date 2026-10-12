{{--
    PageSpeed Insights の4つの区分の点数の円グラフ（結果の表の上。D-78-09・D-78-11）。
    記事の画面のページの速さ（携帯・デスクトップ）と、PageSpeed Insights情報の画面のトップページ・記事のまとめ（記事の平均）で使う。

    受け取る値（どちらか）：
    ・$runs：[見出し => PageSpeedRun|null]（例：['携帯' => …, 'デスクトップ' => …]）。見出しの下に測った日時を出す
    ・$rows：[見出し => ['sub' => 見出しの下の文|null, 'scores' => [区分の列 => 点数|null], 'none' => 点数がないときの状態]]（記事の平均など）
    ・$decimals（任意）：点数の小数の桁（省略時 0。平均は 1）

    行ごとに、パフォーマンス・ユーザー補助・おすすめの方法・SEO の4つを並べる（品質評価の点数と同じ円グラフ。partials/score-gauge）。
    円グラフはパネルの幅いっぱいに広げる（D-78-10）。
    色と状態は、結果の表と同じ Lighthouse の区切り（90以上：良好・50以上：改善が必要・50未満：不良。PageSpeedReportService::grade）
--}}
@php
    $report = \App\Services\PageSpeed\PageSpeedReportService::class;
    $grades = [[90, 'good', '良好'], [50, 'mid', '改善が必要'], [-INF, 'bad', '不良']];
    $decimals ??= 0;
    $rows ??= collect($runs ?? [])->map(fn ($run) => [
        'sub'    => $run ? \App\Support\DisplayTime::format($run->started_at, 'Y-m-d H:i') : null,
        'scores' => collect($report::CATEGORIES)->mapWithKeys(fn ($label, $column) => [$column => $run?->{$column}])->all(),
        'none'   => $run ? 'データなし' : '未測定',
    ])->all();
@endphp
<div class="score-gauge-rows">
    @foreach ($rows as $label => $row)
        <div class="score-gauge-row">
            <div class="score-gauge-row-label">
                <strong>{{ $label }}</strong>
                @if ($row['sub'])<br><span class="text-muted">{{ $row['sub'] }}</span>@endif
            </div>
            @foreach ($report::CATEGORIES as $column => $categoryLabel)
                <div class="score-gauge-item">
                    @include('partials.score-gauge', ['score' => $row['scores'][$column] ?? null, 'grades' => $grades, 'decimals' => $decimals, 'size' => 'fluid', 'noneLabel' => $row['none']])
                    <div class="score-gauge-caption">{{ $categoryLabel }}</div>
                </div>
            @endforeach
        </div>
    @endforeach
</div>
