{{--
    AdSense（選択中のブログ。D-67）：AdSense の画面のホームと同じ見方のカード
    ・推定収益額（本日・昨日・過去7日間・今月）と比較、残高・前回の支払い
    ・パフォーマンス（過去7日間とその前の7日間）、広告ユニット・国・入札方法・トラフィックソース
    本日と残高は開いたときに問い合わせ、それ以外は毎日の同期で保存した数値（App\Services\Google\AdsenseReportService）
--}}

@extends('layouts.app')

@section('title', 'AdSense（BlogOS）')

@section('content')

    <h1>AdSense</h1>

    <p>
        <a href="{{ route('home') }}">トップページに戻る</a>
        ・<a href="{{ route('analytics.index') }}">分析</a>
        ・<a href="{{ route('google.settings') }}">Google連携の設定</a>
    </p>

    @if ($property === null)
        <p class="text-warn">このブログには、AdSense がつながっていません。<a href="{{ route('google.settings') }}">Google連携の設定</a>で、AdSense のアカウントを選んでください。</p>
    @else
        @php
            $currency = $report['currency'];
            // 金額（円は整数、それ以外は小数2けた）
            $money = fn (?float $value) => $value === null ? '-' : ($currency === 'JPY' || $currency === null ? '¥' . number_format(round($value)) : number_format($value, 2) . ' ' . $currency);
            // 比較（増減と割合。前の値がなければ出さない）
            $change = function (?float $current, ?float $previous, bool $isMoney = true, int $decimals = 0) use ($money) {
                if ($current === null || $previous === null) {
                    return null;
                }
                $delta = $current - $previous;
                $percent = $previous != 0 ? $delta / abs($previous) * 100 : null;
                $amount = $isMoney ? ($delta >= 0 ? '+' : '-') . ltrim($money(abs($delta)), '-') : ($delta >= 0 ? '+' : '') . number_format($delta, $decimals);

                return ['up' => $delta >= 0, 'text' => ($delta >= 0 ? '▲ ' : '▼ ') . $amount . ($percent !== null ? sprintf('（%+d%%）', round($percent)) : '')];
            };
            // 小さな線のグラフ（SVG の点の並び）
            $spark = function (array $values) {
                $max = max($values) ?: 1;
                $min = min($values);
                $range = ($max - $min) ?: 1;
                $step = count($values) > 1 ? 120 / (count($values) - 1) : 0;

                return implode(' ', array_map(fn ($value, $i) => round($i * $step, 1) . ',' . round(34 - ($value - $min) / $range * 30, 1), $values, array_keys($values)));
            };
            $live = $report['live'];
            $dimensionTitles = [
                'ad_unit'        => ['code' => 'AD UNITS', 'label' => '広告ユニット'],
                'country'        => ['code' => 'COUNTRY', 'label' => '国'],
                'bid_type'       => ['code' => 'BID TYPE', 'label' => '入札方法'],
                'traffic_source' => ['code' => 'TRAFFIC', 'label' => 'トラフィック ソース'],
            ];
        @endphp

        <p class="text-muted">
            本日の数値と残高：{{ \App\Support\DisplayTime::format(\Illuminate\Support\Carbon::parse($live['fetchedAt']), 'm-d H:i') }} に AdSense から取得（{{ \App\Services\Google\AdsenseReportService::LIVE_MINUTES }}分は使い回します。<a href="{{ route('analytics.adsense', ['refresh' => 1]) }}">最新にする</a>）。
            それ以外は、毎日の「Googleとの同期」で取った数値です（最後の日：{{ $report['lastDate'] ?? 'まだありません' }}）。
            AdSense の数値は、Google 側の更新が数時間遅れます（本日は暫定の値）。
        </p>
        @if ($live['error'])
            <p class="text-warn">本日の数値・残高を AdSense から取得できませんでした：{{ $live['error'] }}</p>
        @endif

        <div class="adsense-grid">

            {{-- 推定収益額 --}}
            <section class="panel adsense-wide">
                <h2 data-code="EARNINGS">推定収益額</h2>
                <div class="adsense-figures">
                    <div class="adsense-figure">
                        <div class="adsense-figure-label">本日（現時点まで）</div>
                        <div class="adsense-figure-value">{{ $money($report['earnings']['today']) }}</div>
                    </div>
                    @foreach (['yesterday' => '昨日', 'week' => '過去7日間', 'month' => '今月'] as $key => $label)
                        @php $item = $report['earnings'][$key]; @endphp
                        @php $diff = $change($item['value'], $item['compare']); @endphp
                        <div class="adsense-figure">
                            <div class="adsense-figure-label">{{ $label }}</div>
                            <div class="adsense-figure-value">{{ $money($item['value']) }}</div>
                            @if ($diff)
                                <div class="adsense-change {{ $diff['up'] ? 'adsense-up' : 'adsense-down' }}">{{ $diff['text'] }}</div>
                                <div class="adsense-figure-note text-muted">{{ $item['label'] }}</div>
                            @elseif ($key === 'month')
                                <div class="adsense-figure-note text-muted">前年のデータがありません</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>

            {{-- 残高 --}}
            <section class="panel">
                <h2 data-code="BALANCE">残高 @include('partials.tip', ['tip' => 'まだ支払われていない収益です。AdSense のアカウント全体の値で、ブログごとには分けられません。'])</h2>
                <div class="adsense-figure-value">{{ $live['balance'] ?? '-' }}</div>
                @if ($live['lastPayment'])
                    <p class="text-muted">前回の支払い：{{ $live['lastPayment']['amount'] }}@if ($live['lastPayment']['date'])（{{ $live['lastPayment']['date'] }}）@endif</p>
                @endif
            </section>

            {{-- パフォーマンス --}}
            <section class="panel">
                <h2 data-code="PERFORMANCE">パフォーマンス</h2>
                <p class="text-muted">過去7日間（{{ $report['period']['weekFrom']->format('m-d') }}〜{{ $report['period']['weekTo']->format('m-d') }}）と、その前の7日間との比較</p>
                @php
                    $week = $report['performance']['week'];
                    $previous = $report['performance']['previous'];
                    $metrics = [
                        'page_views'  => ['label' => 'ページビュー', 'format' => fn ($v) => number_format((int) $v), 'money' => false, 'decimals' => 0],
                        'page_rpm'    => ['label' => 'ページのインプレッション収益', 'format' => $money, 'money' => true, 'decimals' => 0, 'tip' => '1,000ページビューあたりの推定収益額（ページ RPM）。'],
                        'impressions' => ['label' => '表示回数', 'format' => fn ($v) => number_format((int) $v), 'money' => false, 'decimals' => 0],
                        'clicks'      => ['label' => 'クリック数', 'format' => fn ($v) => number_format((int) $v), 'money' => false, 'decimals' => 0],
                        'cpc'         => ['label' => 'CPC', 'format' => $money, 'money' => true, 'decimals' => 0, 'tip' => '1クリックあたりの推定収益額。'],
                        'page_ctr'    => ['label' => 'ページ CTR', 'format' => fn ($v) => $v === null ? '-' : number_format($v, 2) . '%', 'money' => false, 'decimals' => 2, 'tip' => 'ページビューのうち、広告がクリックされた割合。'],
                    ];
                @endphp
                <div class="adsense-metrics">
                    @foreach ($metrics as $key => $metric)
                        @php $diff = $change($week[$key], $previous[$key], $metric['money'], $metric['decimals']); @endphp
                        <div class="adsense-metric">
                            <div class="adsense-figure-label">{{ $metric['label'] }}@isset($metric['tip']) @include('partials.tip', ['tip' => $metric['tip']])@endisset</div>
                            <div class="adsense-metric-value">{{ $metric['format']($week[$key]) }}</div>
                            @if ($diff)
                                <div class="adsense-change {{ $diff['up'] ? 'adsense-up' : 'adsense-down' }}">{{ $diff['text'] }}</div>
                            @endif
                            <svg class="adsense-spark" viewBox="0 0 120 36" preserveAspectRatio="none" aria-hidden="true">
                                <polyline points="{{ $spark(array_map(fn ($day) => (float) ($day[$key] ?? 0), $report['performance']['daily'])) }}" />
                            </svg>
                        </div>
                    @endforeach
                </div>
            </section>

            {{-- 広告ユニット・国・入札方法・トラフィックソース --}}
            @foreach ($dimensionTitles as $dimension => $title)
                @php $rows = $report['dimensions'][$dimension] ?? []; @endphp
                @php $max = max(array_merge([0.0], array_column($rows, 'estimated_earnings'), array_column($rows, 'previous'))) ?: 1; @endphp
                <section class="panel">
                    <h2 data-code="{{ $title['code'] }}">{{ $title['label'] }}</h2>
                    <p class="text-muted">過去7日間の推定収益額（下の薄い線は、その前の7日間）</p>
                    @forelse ($rows as $row)
                        @php $diff = $change($row['estimated_earnings'], $row['previous']); @endphp
                        <div class="adsense-row">
                            <div class="adsense-row-head">
                                <span class="adsense-row-name">{{ $row['value'] }}</span>
                                @if ($diff)<span class="adsense-change {{ $diff['up'] ? 'adsense-up' : 'adsense-down' }}">{{ $diff['text'] }}</span>@endif
                                <span class="adsense-row-value">{{ $money($row['estimated_earnings']) }}（{{ number_format($row['share'], 1) }}%）</span>
                            </div>
                            <div class="adsense-bar"><span style="width: {{ round($row['estimated_earnings'] / $max * 100, 1) }}%"></span></div>
                            <div class="adsense-bar adsense-bar-previous"><span style="width: {{ round($row['previous'] / $max * 100, 1) }}%"></span></div>
                            <div class="adsense-row-note text-muted">表示回数 {{ number_format($row['impressions']) }}・クリック数 {{ number_format($row['clicks']) }}</div>
                        </div>
                    @empty
                        <p class="text-muted">まだデータがありません（次の「Googleとの同期」で取ります）。</p>
                    @endforelse
                </section>
            @endforeach

        </div>
    @endif

@endsection
