{{--
    分析（選択中のブログのGoogleの指標）。保存済みのデータから表示する

    CTR・掲載順位は表示回数で重み付けして集計する。ユーザー数は日をまたいで足し合わせられないため表示しない（DATABASE 12-2）。
--}}

@extends('layouts.app')

@section('content')

    <h1>分析（{{ $blog->display_name }}）</h1>

    <p>
        <a href="{{ route('home') }}">トップページに戻る</a>
        ・<a href="{{ route('google.settings') }}">Google連携の設定</a>
    </p>

    <p>
        期間：
        @foreach (\App\Http\Controllers\Analytics\AnalyticsController::PERIODS as $period)
            @if ($period === $days)
                <strong>{{ $period }}日</strong>
            @else
                <a href="{{ route('analytics.index', ['days' => $period, 'sort' => $sort]) }}">{{ $period }}日</a>
            @endif
        @endforeach
        （{{ $from->toDateString() }}〜{{ $to->toDateString() }}）
    </p>

    <h2>ブログ全体</h2>
    <table border="1" cellpadding="4" cellspacing="0">
        <tr>
            <th style="text-align:left;">GA4</th>
            <td>
                @if ($site['ga4'])
                    表示回数 {{ number_format($site['ga4']->views) }}・セッション {{ number_format($site['ga4']->sessions) }}
                    ・エンゲージメントのあったセッション {{ number_format($site['ga4']->engaged_sessions) }}
                    ・平均エンゲージメント時間 {{ $site['ga4']->sessions > 0 ? round($site['ga4']->engagement_duration / $site['ga4']->sessions) : 0 }}秒/セッション
                @else
                    データなし
                @endif
            </td>
        </tr>
        <tr>
            <th style="text-align:left;">Search Console</th>
            <td>
                @if ($site['search_console'])
                    クリック {{ number_format($site['search_console']->clicks) }}・表示回数 {{ number_format($site['search_console']->impressions) }}
                    ・CTR {{ $site['search_console']->impressions > 0 ? round($site['search_console']->clicks / $site['search_console']->impressions * 100, 2) : 0 }}%
                    ・平均掲載順位 {{ $site['search_console']->position !== null ? round($site['search_console']->position, 1) : '-' }}
                @else
                    データなし
                @endif
            </td>
        </tr>
        <tr>
            <th style="text-align:left;">AdSense</th>
            <td>
                @if ($site['adsense'])
                    見積もり収益 {{ number_format($site['adsense']->earnings, 2) }} {{ $site['adsense']->currency_code }}
                    ・ページビュー {{ number_format($site['adsense']->page_views) }}・表示回数 {{ number_format($site['adsense']->impressions) }}・クリック {{ number_format($site['adsense']->clicks) }}
                    ・ページRPM {{ $site['adsense']->page_views > 0 ? number_format($site['adsense']->earnings / $site['adsense']->page_views * 1000, 2) : 0 }}
                @else
                    データなし
                @endif
            </td>
        </tr>
    </table>

    <h2>記事ごと（上位100件）</h2>
    <p>
        並べ替え：
        @foreach (['views' => '表示回数（GA4）', 'clicks' => 'クリック（Search Console）', 'impressions' => '検索での表示回数', 'earnings' => '収益（AdSense）'] as $key => $label)
            @if ($key === $sort)
                <strong>{{ $label }}</strong>
            @else
                <a href="{{ route('analytics.index', ['days' => $days, 'sort' => $key]) }}">{{ $label }}</a>
            @endif
        @endforeach
    </p>

    <div style="overflow-x:auto;">
        <table border="1" cellpadding="4" cellspacing="0">
            <thead><tr><th>記事</th><th>表示回数</th><th>クリック</th><th>検索での表示回数</th><th>CTR</th><th>平均掲載順位</th><th>収益</th></tr></thead>
            <tbody>
                @forelse ($articles as $row)
                    @php
                        $article = $row->post_id ? ($posts[$row->post_id] ?? null) : ($pages[$row->page_id] ?? null);
                    @endphp
                    <tr>
                        <td>
                            @if ($article)
                                <a href="{{ route('articles.show', ['type' => $row->post_id ? 'posts' : 'pages', 'id' => $article->id]) }}">{{ $article->title_raw ?: '（タイトルなし）' }}</a>
                                @if ($article->wordpress_deleted_at)（削除済み）@endif
                            @endif
                        </td>
                        <td>{{ number_format($row->views) }}</td>
                        <td>{{ number_format($row->clicks) }}</td>
                        <td>{{ number_format($row->impressions) }}</td>
                        <td>{{ $row->impressions > 0 ? round($row->clicks / $row->impressions * 100, 2) . '%' : '-' }}</td>
                        <td>{{ $row->position !== null ? round($row->position, 1) : '-' }}</td>
                        <td>{{ $row->earnings > 0 ? number_format($row->earnings, 2) : '-' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7">データがありません。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <h2>記事に対応付けられなかったページ（GA4の表示回数の多い順）</h2>
    <p>トップページ・カテゴリのページなど、記事以外のページもここに表示されます。</p>
    <ul>
        @forelse ($unresolved as $row)
            <li>{{ $row->url }}：{{ number_format($row->value) }}</li>
        @empty
            <li>ありません。</li>
        @endforelse
    </ul>

@endsection
