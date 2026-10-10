{{--
    PageSpeed Insights情報（メニューの「情報 → PageSpeed Insights情報」。D-78）

    選択中のブログの、PageSpeed Insights の測定の結果のまとめ。端末（携帯・デスクトップ）を切り替える（初めは携帯。Google は携帯の表示で評価するため）。
    ・サイト全体：トップページの最新の結果（今すぐ測定できる）
    ・記事のまとめ：記事ごとの最新の結果の、区分ごとの平均と判定の分布・実際の利用者の判定の分布
    ・多い問題：多くの記事で合格しなかった項目（テーマ・プラグインで直す問題の目安）
    ・記事ごとの最新の結果：点数の低い順（並びの区分を選べる）
--}}

@extends('layouts.app')

@section('content')

    @php
        $report = \App\Services\PageSpeed\PageSpeedReportService::class;
        $strategyLabel = \App\Models\PageSpeedRun::STRATEGIES[$strategy];
    @endphp

    <h1>PageSpeed Insights情報 @include('partials.tip', ['tip' => "選択中のブログの記事とトップページを、PageSpeed Insights で測った結果のまとめです（定期実行で毎週。記事ごとの記録は「履歴 → PageSpeed Insightsとの同期履歴」）。\n点数（0〜100）は、90以上が良好・50以上が改善が必要・50未満が不良です。\nGoogle は携帯の表示で評価するため、携帯の結果を主に見てください。"])</h1>

    <p><a href="{{ route('home') }}">トップページに戻る</a> ／ <a href="{{ route('pagespeed.runs.index') }}">PageSpeed Insightsとの同期履歴</a></p>

    @unless ($configured)
        <p class="text-error">PageSpeed Insights の APIキーが設定されていません（.env の GOOGLE_PAGESPEED_API_KEY）。設定するまで測りません。</p>
    @endunless

    @include('partials.history-last', ['label' => '最後の測定', 'at' => $latest?->started_at, 'result' => $latest ? $latest->strategyLabel() . '・' . $latest->statusLabel() : null])

    @include('partials.flash')

    @if (! $blog)
        <p>ブログが選択されていません。</p>
    @else

        {{-- 表示条件：端末 --}}
        <section class="panel">
        <h2 data-code="FILTER">表示条件</h2>
        <form method="GET" action="{{ route('pagespeed.index') }}">
            <input type="hidden" name="sort" value="{{ $sort }}">
            <table class="form-grid">
                <tbody>
                    <tr>
                        <th><label for="pagespeed-strategy">端末</label></th>
                        <td>
                            <select name="strategy" id="pagespeed-strategy">
                                @foreach (\App\Models\PageSpeedRun::STRATEGIES as $value => $label)
                                    <option value="{{ $value }}" @selected($strategy === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </td>
                    </tr>
                </tbody>
            </table>
            <p class="filter-actions"><button class="btn-secondary" type="submit">切り替える</button></p>
        </form>
        </section>

        {{-- サイト全体：トップページ --}}
        <section class="panel">
        <h2 data-code="SITE">トップページ（{{ $strategyLabel }}）</h2>
        @if ($home)
            @include('pagespeed.scores', ['runs' => [$strategyLabel => $home]])
        @else
            <p>まだ測っていません。</p>
        @endif
        <form method="POST" action="{{ route('pagespeed.measure') }}" class="filter-actions">
            @csrf
            @include('partials.selected-blog-field')
            <button type="submit" class="btn-secondary">トップページを今すぐ測定</button>
        </form>
        </section>

        {{-- 記事のまとめ --}}
        <section class="panel">
        <h2 data-code="SUMMARY">記事のまとめ（{{ $strategyLabel }}）</h2>
        <p>測った記事：{{ number_format($summary['measured']) }}件（公開中の記事 {{ number_format($summary['published']) }}件。記事ごとの最新の結果から）</p>
        @if ($summary['measured'] > 0)
            <div style="overflow-x:auto;">
                <table class="data">
                    <thead><tr><th>区分</th><th>平均</th><th>良好（90以上）</th><th>改善が必要（50〜89）</th><th>不良（50未満）</th></tr></thead>
                    <tbody>
                        @foreach ($summary['categories'] as $category)
                            <tr>
                                <td style="white-space:nowrap;">{{ $category['label'] }}</td>
                                <td>{{ $category['average'] ?? '-' }}</td>
                                @foreach ($category['grades'] as $count)
                                    <td>{{ number_format($count) }}件</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p>
                実際の利用者の判定（記事） @include('partials.tip', ['tip' => 'Chrome の利用者の記録（直近28日）がある記事だけです。アクセスが少ない記事にはありません。'])：
                @forelse ($summary['field'] as $category => $count)
                    {{ \App\Models\PageSpeedRun::FIELD_CATEGORIES[$category] ?? $category }} {{ $count }}件@if (! $loop->last)・@endif
                @empty
                    データのある記事はありません。
                @endforelse
            </p>
        @endif
        </section>

        {{-- 多い問題 --}}
        <section class="panel">
        <h2 data-code="ISSUES">多い問題（{{ $strategyLabel }}）@include('partials.tip', ['tip' => "記事ごとの最新の結果で、合格しなかった記事の数の多い順です（上位20件）。\n多くの記事に出る問題は、記事ごとではなく、テーマ・プラグイン・サーバーの設定で直せることが多いです。"])</h2>
        @if ($failures === [])
            <p>ありません。</p>
        @else
            <div style="overflow-x:auto;">
                <table class="data">
                    <thead><tr><th>項目</th><th>区分</th><th>記事の数</th></tr></thead>
                    <tbody>
                        @foreach ($failures as $failure)
                            <tr>
                                <td>{{ $failure['title'] }}</td>
                                <td style="white-space:nowrap;">{{ implode('・', array_map(fn ($key) => $report::AUDIT_CATEGORIES[$key] ?? $key, $failure['categories'])) }}</td>
                                <td>{{ number_format($failure['count']) }}件</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        </section>

        {{-- 記事ごとの最新の結果（点数の低い順） --}}
        <section class="panel">
        <h2 data-code="ARTICLE">記事ごとの最新の結果（{{ $strategyLabel }}）</h2>
        @include('partials.history-count', ['paginator' => $articles, 'order' => $report::CATEGORIES[$sort] . 'の低い順'])
        <p>
            並び：
            @foreach ($report::CATEGORIES as $column => $label)
                @if ($column === $sort)
                    <strong>{{ $label }}</strong>
                @else
                    <a href="{{ route('pagespeed.index', ['strategy' => $strategy, 'sort' => $column]) }}">{{ $label }}</a>
                @endif
                @if (! $loop->last)・@endif
            @endforeach
        </p>
        <div style="overflow-x:auto;">
            <table class="data">
                <thead>
                    <tr><th>記事</th><th>パフォーマンス</th><th>ユーザー補助</th><th>おすすめの方法</th><th>SEO</th><th>LCP</th><th>CLS</th><th>TBT</th><th>実際の利用者</th><th>測定日時</th></tr>
                </thead>
                <tbody>
                    @forelse ($articles as $run)
                        <tr>
                            <td>
                                @if ($run->post_id)
                                    <a href="{{ route('articles.show', ['type' => 'posts', 'id' => $run->post_id]) }}">{{ $run->targetLabel() }}</a>
                                @elseif ($run->page_id)
                                    <a href="{{ route('articles.show', ['type' => 'pages', 'id' => $run->page_id]) }}">{{ $run->targetLabel() }}</a>
                                @else
                                    {{ $run->targetLabel() }}
                                @endif
                            </td>
                            <td>{{ $run->performance_score }}</td>
                            <td>{{ $run->accessibility_score }}</td>
                            <td>{{ $run->best_practices_score }}</td>
                            <td>{{ $run->seo_score }}</td>
                            <td style="white-space:nowrap;">{{ $run->lcp_ms !== null ? number_format($run->lcp_ms / 1000, 1) . '秒' : '' }}</td>
                            <td>{{ $run->cls !== null ? number_format($run->cls, 3) : '' }}</td>
                            <td style="white-space:nowrap;">{{ $run->tbt_ms !== null ? number_format($run->tbt_ms) . 'ms' : '' }}</td>
                            <td style="white-space:nowrap;">{{ $run->field_category ? (\App\Models\PageSpeedRun::FIELD_CATEGORIES[$run->field_category] ?? $run->field_category) : '' }}</td>
                            <td style="white-space:nowrap;">{{ \App\Support\DisplayTime::format($run->started_at, 'Y-m-d H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="10">まだ測っていません。</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $articles->links() }}
        </section>

    @endif

@endsection
