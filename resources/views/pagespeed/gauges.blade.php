{{--
    PageSpeed Insights の4つの区分の点数の円グラフ（記事の画面のページの速さ。結果の表の上。D-78-09）。
    受け取る値：$runs（[見出し => PageSpeedRun|null]。例：['携帯' => …, 'デスクトップ' => …]）

    端末ごとに1行で、パフォーマンス・ユーザー補助・おすすめの方法・SEO の4つを並べる（品質評価の点数と同じ円グラフ。partials/score-gauge）。
    色と状態は、結果の表と同じ Lighthouse の区切り（90以上：良好・50以上：改善が必要・50未満：不良。PageSpeedReportService::grade）
--}}
@php
    $report = \App\Services\PageSpeed\PageSpeedReportService::class;
    $grades = [[90, 'good', '良好'], [50, 'mid', '改善が必要'], [-INF, 'bad', '不良']];
@endphp
<div class="score-gauge-rows">
    @foreach ($runs as $label => $run)
        <div class="score-gauge-row">
            <div class="score-gauge-row-label">
                <strong>{{ $label }}</strong>
                @if ($run)<br><span class="text-muted">{{ \App\Support\DisplayTime::format($run->started_at, 'Y-m-d H:i') }}</span>@endif
            </div>
            @foreach ($report::CATEGORIES as $column => $categoryLabel)
                <div class="score-gauge-item">
                    @include('partials.score-gauge', ['score' => $run?->{$column}, 'grades' => $grades, 'decimals' => 0, 'size' => 'fluid', 'noneLabel' => $run ? 'データなし' : '未測定'])
                    <div class="score-gauge-caption">{{ $categoryLabel }}</div>
                </div>
            @endforeach
        </div>
    @endforeach
</div>
