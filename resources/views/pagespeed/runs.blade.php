{{--
    PageSpeed Insightsとの同期履歴（pagespeed_runs。メニューの「履歴 → PageSpeed Insightsとの同期履歴」。D-78）

    選択中のブログの、記事とトップページを PageSpeed Insights で測った記録（1つの URL を、携帯かデスクトップで1回測ったごとに1行）。
    合格しなかった診断の項目は、たたんで出す。
--}}

@extends('layouts.app')

@section('content')

    <h1>PageSpeed Insightsとの同期履歴 @include('partials.tip', ['tip' => "選択中のブログの記事とトップページを、PageSpeed Insights で測った記録です（定期実行で毎週。メニューの「設定 → 即時実行」からも測れます）。\n点数（0〜100）は、パフォーマンス・ユーザー補助・おすすめの方法・SEO の4つの区分です。試しに開いた値は、測るたびに数点ぶれます。\n実際の利用者の値は、Chrome の利用者の記録（直近28日の75パーセンタイル）で、アクセスが少ない記事にはありません。"])</h1>

    <p><a href="{{ route('home') }}">トップページに戻る</a></p>

    @unless ($configured)
        <p class="text-error">PageSpeed Insights の APIキーが設定されていません（.env の GOOGLE_PAGESPEED_API_KEY）。設定するまで測りません。</p>
    @endunless

    @include('partials.history-last', ['label' => '最後の測定', 'at' => $latest?->started_at, 'result' => $latest ? $latest->strategyLabel() . '・' . $latest->statusLabel() : null])

    <section class="panel">
    <h2 data-code="RUN">測定の記録</h2>
    @if (! $blog)
        <p>ブログが選択されていません。</p>
    @else
        @include('partials.history-count', ['paginator' => $runs])
        <div style="overflow-x:auto;">
            <table class="data">
                <thead>
                    <tr>
                        <th>日時</th>
                        <th>対象</th>
                        <th>端末</th>
                        <th>契機</th>
                        <th>結果</th>
                        <th>パフォーマンス</th>
                        <th>ユーザー補助</th>
                        <th>おすすめの方法</th>
                        <th>SEO</th>
                        <th>LCP @include('partials.tip', ['tip' => 'Largest Contentful Paint：一番大きい要素（見出し・画像など）が出るまでの時間（試しに開いた値）。2.5秒以下が良好です。'])</th>
                        <th>CLS @include('partials.tip', ['tip' => 'Cumulative Layout Shift：読み込み中に画面がずれる量（試しに開いた値）。0.1以下が良好です。'])</th>
                        <th>TBT @include('partials.tip', ['tip' => 'Total Blocking Time：読み込み中に操作できない時間の合計（試しに開いた値）。200ミリ秒以下が良好です。'])</th>
                        <th>実際の利用者 @include('partials.tip', ['tip' => 'Chrome の利用者の記録による、この記事の判定（LCP・INP・CLS）。アクセスが少ない記事にはありません。'])</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($runs as $run)
                        <tr>
                            <td style="white-space:nowrap;">{{ \App\Support\DisplayTime::format($run->started_at) }}</td>
                            <td>
                                {{ $run->targetLabel() }}
                                <br><a href="{{ $run->url }}" target="_blank" rel="noopener noreferrer" class="text-muted">{{ \Illuminate\Support\Str::limit($run->url, 80) }}</a>
                            </td>
                            <td style="white-space:nowrap;">{{ $run->strategyLabel() }}</td>
                            <td style="white-space:nowrap;">{{ $run->triggerLabel() }}</td>
                            <td style="white-space:nowrap;">{{ $run->statusLabel() }}</td>
                            <td>{{ $run->performance_score }}</td>
                            <td>{{ $run->accessibility_score }}</td>
                            <td>{{ $run->best_practices_score }}</td>
                            <td>{{ $run->seo_score }}</td>
                            <td style="white-space:nowrap;">{{ $run->lcp_ms !== null ? number_format($run->lcp_ms / 1000, 1) . '秒' : '' }}</td>
                            <td>{{ $run->cls !== null ? number_format($run->cls, 3) : '' }}</td>
                            <td style="white-space:nowrap;">{{ $run->tbt_ms !== null ? number_format($run->tbt_ms) . 'ms' : '' }}</td>
                            <td style="white-space:nowrap;">{{ $run->field_category ? (\App\Models\PageSpeedRun::FIELD_CATEGORIES[$run->field_category] ?? $run->field_category) : '' }}</td>
                        </tr>
                        @if ($run->error || $run->failed_audits)
                            <tr>
                                <td></td>
                                <td colspan="12">
                                    @if ($run->error)
                                        <span class="text-error">{{ $run->error }}</span>
                                    @endif
                                    @if ($run->failed_audits)
                                        <details>
                                            <summary>合格しなかった項目（{{ count($run->failed_audits) }}件）</summary>
                                            <ul>
                                                @foreach ($run->failed_audits as $audit)
                                                    <li>{{ $audit['title'] }}{{ ! empty($audit['display_value']) ? '（' . $audit['display_value'] . '）' : '' }}</li>
                                                @endforeach
                                            </ul>
                                        </details>
                                    @endif
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr><td colspan="13">ありません。</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $runs->links() }}
    @endif
    </section>

@endsection
