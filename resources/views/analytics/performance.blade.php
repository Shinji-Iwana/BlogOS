{{--
    記事の実績と次にやること（品質評価の第4層。D-47 S4）
--}}

@extends('layouts.app')

@section('content')

    <h1>記事の実績と次にやること</h1>

    <p><a href="{{ route('analytics.index') }}">分析</a>・<a href="{{ route('google.index-status') }}">インデックスの登録状態</a>・<a href="{{ route('ai.batches.index') }}">まとめて実行</a></p>

    @include('partials.flash')

    @if ($period === null)
        <p>Google のデータがまだありません。<a href="{{ route('google.settings') }}">Google連携の設定</a>で取得してください。</p>
    @else
        <p class="text-muted">
            Search Console・GA4 の数字（{{ $period['from']->toDateString() }}〜{{ $period['to']->toDateString() }}。前の期間 {{ $period['previous_from']->toDateString() }}〜{{ $period['previous_to']->toDateString() }} と比べる）と、品質評価を並べ、決まった規則で次にやることを出します。
            実績は点数にしません。クリック率の目安は、掲載順位ごとの一般的な傾向から置いたもので（例：1位 28%・3位 11%・10位 2.5%）、その半分より低いと「クリック率が低い」とします。
            表示回数が少ない記事のクリック率は、たまたまの差が大きいため判断しません（{{ \App\Services\Google\ArticlePerformanceService::MIN_IMPRESSIONS }}回以上だけ）。
        </p>
        <p>
            期間：
            @foreach ([28, 90] as $option)
                <a href="{{ route('analytics.performance', ['days' => $option, 'action' => $action]) }}">@if ($days === $option)<strong>{{ $option }}日</strong>@else{{ $option }}日@endif</a>@if (! $loop->last)・@endif
            @endforeach
        </p>

        <table class="data" style="margin-bottom:12px;">
            <thead><tr><th>次にやること</th><th>記事</th><th>やり方</th></tr></thead>
            <tbody>
                @foreach ($actions as $key => $definition)
                    <tr @if ($action === $key) class="row-current" @endif>
                        <td><a href="{{ route('analytics.performance', ['days' => $days, 'action' => $key]) }}">{{ $definition['label'] }}</a></td>
                        <td>{{ $counts[$key] }}件</td>
                        <td class="text-muted">{{ $definition['advice'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if ($action)<p><a href="{{ route('analytics.performance', ['days' => $days]) }}">絞り込みを外す</a></p>@endif

        <div style="overflow-x:auto;">
            <table class="data" style="font-size:90%;">
                <thead>
                    <tr>
                        <th>記事</th><th>インデックス</th><th>表示回数</th><th>クリック</th><th>クリック率（目安）</th><th>平均掲載順位</th><th>ページビュー</th><th>エンゲージ率</th>
                        <th>品質評価（検索結果で選ばれるか・検索意図）</th><th>次にやること</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        @php
                            $m = $row['metrics'];
                            $p = $row['previous'];
                            $evaluation = $row['evaluation'];
                            $diff = $m['clicks'] - $p['clicks'];
                        @endphp
                        <tr>
                            <td style="max-width:300px;"><a href="{{ route('articles.show', ['type' => $row['type'] === 'post' ? 'posts' : 'pages', 'id' => $row['article']->id]) }}">{{ $row['article']->title_raw ?: '（タイトルなし）' }}</a></td>
                            <td>{{ $row['index']?->label() ?? '未確認' }}</td>
                            <td style="text-align:right;">{{ number_format($m['impressions']) }}</td>
                            <td style="text-align:right; white-space:nowrap;">{{ number_format($m['clicks']) }}@if ($p['clicks'] > 0 || $m['clicks'] > 0)<br><span class="{{ $diff < 0 ? 'text-error' : 'text-ok' }}">{{ $diff >= 0 ? '+' : '' }}{{ $diff }}</span>@endif</td>
                            <td style="text-align:right; white-space:nowrap;">
                                {{ $m['impressions'] > 0 ? number_format($m['ctr'] * 100, 1) . '%' : '-' }}
                                @if ($m['position'] !== null)<br><span class="text-muted">（{{ number_format(\App\Services\Google\ArticlePerformanceService::expectedCtr($m['position']) * 100, 1) }}%）</span>@endif
                            </td>
                            <td style="text-align:right;">{{ $m['position'] !== null ? number_format($m['position'], 1) : '-' }}</td>
                            <td style="text-align:right;">{{ number_format($m['views']) }}</td>
                            <td style="text-align:right;">{{ $m['engagement_rate'] !== null ? number_format($m['engagement_rate'] * 100) . '%' : '-' }}</td>
                            <td style="white-space:nowrap;">
                                @if ($evaluation)
                                    <a href="{{ route('evaluations.show', ['id' => $evaluation->id]) }}">{{ $evaluation->score !== null ? number_format($evaluation->score, 1) . '点' : '-' }}</a>
                                    @if ($evaluation->axis_scores)
                                        <br><span class="text-muted">{{ isset($evaluation->axis_scores['ctr']) ? number_format((float) $evaluation->axis_scores['ctr']) . '%' : '-' }}・{{ isset($evaluation->axis_scores['intent']) ? number_format((float) $evaluation->axis_scores['intent']) . '%' : '-' }}</span>
                                    @endif
                                @else
                                    未評価
                                @endif
                            </td>
                            <td style="max-width:260px;">
                                @foreach ($row['actions'] as $key)
                                    <span title="{{ $actions[$key]['advice'] }}">{{ $actions[$key]['label'] }}</span>@if (! $loop->last)<br>@endif
                                @endforeach
                                @if ($row['actions'] !== [])
                                    <br><a href="{{ route('ai.generations.create', ['mode' => 'revision', 'target' => ($row['type'] === 'post' ? 'posts:' : 'pages:') . $row['article']->id]) }}" style="font-size:90%;">改修案を作る</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

@endsection
