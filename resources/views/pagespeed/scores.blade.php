{{--
    PageSpeed Insights の結果の表（D-78。記事の画面のパネル・PageSpeed Insights情報の画面のトップページ）。
    受け取る値：$runs（[見出し => PageSpeedRun|null]。例：['携帯' => …, 'デスクトップ' => …]）

    点数の判定は Lighthouse と同じ区切り（90以上：良好・50以上：改善が必要・50未満：不良）。
    実際の利用者の値は、Chrome の利用者の記録（直近28日の75パーセンタイル）で、アクセスが少ないとない。
    合格しなかった項目は、区分ごとにたたんで出す。
--}}
@php
    $report = \App\Services\PageSpeed\PageSpeedReportService::class;
    $ms = fn ($value) => $value !== null ? number_format($value / 1000, 1) . '秒' : '-';
    $field = fn ($run, $prefix) => $run && $run->{$prefix . '_category'}
        ? (\App\Models\PageSpeedRun::FIELD_CATEGORIES[$run->{$prefix . '_category'}] ?? $run->{$prefix . '_category'})
            . '（LCP ' . $ms($run->{$prefix . '_lcp_ms'}) . '・INP ' . ($run->{$prefix . '_inp_ms'} !== null ? $run->{$prefix . '_inp_ms'} . 'ms' : '-') . '・CLS ' . ($run->{$prefix . '_cls'} !== null ? number_format($run->{$prefix . '_cls'}, 2) : '-') . '）'
        : 'データなし';
@endphp
<div style="overflow-x:auto;">
    <table class="data">
        <thead>
            <tr>
                <th></th>
                @foreach ($runs as $label => $run)
                    <th>{{ $label }}@if ($run)<br><span class="text-muted">{{ \App\Support\DisplayTime::format($run->started_at, 'Y-m-d H:i') }}</span>@endif</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($report::CATEGORIES as $column => $categoryLabel)
                <tr>
                    <th style="text-align:left; white-space:nowrap;">{{ $categoryLabel }}</th>
                    @foreach ($runs as $run)
                        <td>{{ $run?->{$column} ?? '-' }}@if ($run && $run->{$column} !== null)（{{ $report::grade($run->{$column}) }}）@endif</td>
                    @endforeach
                </tr>
            @endforeach
            <tr>
                <th style="text-align:left; white-space:nowrap;">LCP @include('partials.tip', ['tip' => 'Largest Contentful Paint：一番大きい要素（見出し・画像など）が出るまでの時間（試しに開いた値）。2.5秒以下が良好です。'])</th>
                @foreach ($runs as $run)<td>{{ $ms($run?->lcp_ms) }}</td>@endforeach
            </tr>
            <tr>
                <th style="text-align:left; white-space:nowrap;">CLS @include('partials.tip', ['tip' => 'Cumulative Layout Shift：読み込み中に画面がずれる量（試しに開いた値）。0.1以下が良好です。'])</th>
                @foreach ($runs as $run)<td>{{ $run?->cls !== null ? number_format($run->cls, 3) : '-' }}</td>@endforeach
            </tr>
            <tr>
                <th style="text-align:left; white-space:nowrap;">TBT @include('partials.tip', ['tip' => 'Total Blocking Time：読み込み中に操作できない時間の合計（試しに開いた値）。200ミリ秒以下が良好です。'])</th>
                @foreach ($runs as $run)<td>{{ $run?->tbt_ms !== null ? number_format($run->tbt_ms) . 'ms' : '-' }}</td>@endforeach
            </tr>
            <tr>
                <th style="text-align:left; white-space:nowrap;">FCP @include('partials.tip', ['tip' => 'First Contentful Paint：最初に何かが表示されるまでの時間（試しに開いた値）。1.8秒以下が良好です。'])</th>
                @foreach ($runs as $run)<td>{{ $ms($run?->fcp_ms) }}</td>@endforeach
            </tr>
            <tr>
                <th style="text-align:left; white-space:nowrap;">Speed Index @include('partials.tip', ['tip' => 'ページの中身が見えてくる速さ（試しに開いた値）。3.4秒以下が良好です。'])</th>
                @foreach ($runs as $run)<td>{{ $ms($run?->si_ms) }}</td>@endforeach
            </tr>
            <tr>
                <th style="text-align:left; white-space:nowrap;">実際の利用者（このページ） @include('partials.tip', ['tip' => "Chrome の利用者の記録（直近28日の75パーセンタイル）による判定です（Core Web Vitals）。\nLCP 2.5秒・INP 200ms・CLS 0.1 以下が良好です。アクセスが少ないページにはありません。"])</th>
                @foreach ($runs as $run)<td>{{ $field($run, 'field') }}</td>@endforeach
            </tr>
            <tr>
                <th style="text-align:left; white-space:nowrap;">実際の利用者（サイト全体）</th>
                @foreach ($runs as $run)<td>{{ $field($run, 'origin') }}</td>@endforeach
            </tr>
        </tbody>
    </table>
</div>

{{-- 合格しなかった項目（区分ごと） --}}
@foreach ($runs as $label => $run)
    @if ($run && $run->failed_audits)
        <details>
            <summary>{{ $label }}：合格しなかった項目（{{ count($run->failed_audits) }}件）</summary>
            @foreach ($report::AUDIT_CATEGORIES as $categoryKey => $categoryLabel)
                @php
                    $audits = array_filter($run->failed_audits, fn ($audit) => in_array($categoryKey, $audit['categories'] ?? [], true));
                @endphp
                @if ($audits !== [])
                    <p><strong>{{ $categoryLabel }}</strong></p>
                    <ul>
                        @foreach ($audits as $audit)
                            <li>{{ $audit['title'] }}{{ ! empty($audit['display_value']) ? '（' . $audit['display_value'] . '）' : '' }}</li>
                        @endforeach
                    </ul>
                @endif
            @endforeach
        </details>
    @endif
@endforeach
