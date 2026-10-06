{{--
    AIの設定：API実行の料金表の値下がり（確認待ち。全ブログ共通。D-31-03。料金表は画面「OpenAI API料金表情報」。D-63-28）と、自動の再評価の今日の対象（D-25）
    条件による自動の再評価の設定は、メニューの「設定 → 定期実行 → 記事の再評価」のポップアップ（ai/settings/reevaluation-modal。D-64）
--}}

@extends('layouts.app')

@section('content')

    <h1>AIの設定</h1>

    <p>
        <a href="{{ route('home') }}">トップページに戻る</a>
        ・<a href="{{ route('ai.batches.index') }}">まとめて実行の一覧</a>
        ・<a href="{{ route('ai.credits.index') }}">AIの費用と残高</a>
    </p>

    @include('partials.flash')

    @include('partials.ai-credit-notice')

    <p class="text-muted">
        条件による自動の再評価の設定（有効・モデル・診断の後の編集案の作成・時刻）は、メニューの「設定 → 定期実行 →
        <a href="{{ route('ai.settings.edit') }}" data-modal-open="scheduled-ai-auto-reevaluate-modal">記事の再評価</a>」にあります。
    </p>

    {{-- API実行の料金表の値下がり（確認待ち。全ブログ共通。D-31-03）。料金表は画面「OpenAI API料金表情報」に分けた（D-63-28） --}}
    <section class="panel">
    <h2 id="prices">API実行の料金表の値下がり（確認待ち）</h2>
    <p class="text-muted">
        料金表は、メニューの「情報 → <a href="{{ route('ai.prices.index') }}">OpenAI API料金表情報</a>」、変更の記録は「履歴 → <a href="{{ route('ai.prices.history') }}">OpenAI API料金表との同期履歴</a>」にあります。
    </p>

    @if ($latestPriceCheck && ! $latestPriceCheck->succeeded())
        <div class="text-error">
            <p><strong>{{ \App\Support\DisplayTime::format($latestPriceCheck->created_at) }} の照合で、読み取れなかった料金があります。</strong>今の料金表のまま計算しています。公式のページの形が変わった可能性があるため、料金を確認してください。</p>
            <ul>@foreach ((array) $latestPriceCheck->messages as $message)<li>{{ $message }}</li>@endforeach</ul>
        </div>
    @endif

    @if ($pendingPrices->isNotEmpty())
        <p class="text-warn"><strong>値下がり（確認待ち）：{{ $pendingPrices->count() }}件</strong></p>
        <p class="text-muted">公式のページで値下がりを確かめてから、反映してください（ページの読み違いで料金が安くなると、使いすぎを止められなくなるため、自動では反映しません）。</p>
        <table class="data">
            <thead><tr><th>項目</th><th>今の料金表</th><th>公式のページ</th><th>見つけた日時</th><th></th></tr></thead>
            <tbody>
                @foreach ($pendingPrices as $change)
                    <tr>
                        <td>{{ \App\Services\Ai\AiPriceCheckService::label($change->price_key, $change->field) }}</td>
                        <td>{{ $change->old_value }}</td>
                        <td>{{ $change->new_value }}</td>
                        <td>{{ \App\Support\DisplayTime::format($change->created_at) }}</td>
                        <td style="white-space:nowrap;">
                            <form method="POST" action="{{ route('ai.prices.apply', ['id' => $change->id]) }}" style="display:inline;">
                                @csrf
                                @include('partials.selected-blog-field')
                                <button type="submit">反映する</button>
                            </form>
                            <form method="POST" action="{{ route('ai.prices.reject', ['id' => $change->id]) }}" style="display:inline;">
                                @csrf
                                @include('partials.selected-blog-field')
                                <button class="btn-secondary" type="submit">反映しない</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <p>確認待ちはありません。</p>
    @endif
    </section>

    <section class="panel">
    <h2>今日の時点で対象になる記事：{{ count($targets) }}件</h2>
    <p class="text-muted">
        記事の再評価を有効にしていれば、このうち{{ min(count($targets), $remaining) }}件を次の実行で再評価します（今日の残り {{ $remaining }}件）。
        今すぐまとめて評価する場合は、<a href="{{ route('ai.batches.create', ['mode' => 'quality_diagnosis', 'target' => 'needs_reevaluation']) }}">まとめて実行</a>から実行できます。
    </p>
    <div style="overflow-x:auto;">
        <table class="data">
            <thead><tr><th>理由</th><th>記事</th><th>最新の評価</th><th>評価日</th></tr></thead>
            <tbody>
                @forelse ($targets as $row)
                    <tr>
                        <td>{{ $row['reason']->label() }}@if (! empty($row['priority_notes']))<br><span class="text-error" style="font-size:90%;">優先：{{ implode('・', $row['priority_notes']) }}</span>@endif</td>
                        <td><a href="{{ route('articles.show', ['type' => $row['article'] instanceof \App\Models\Post ? 'posts' : 'pages', 'id' => $row['article']->id]) }}">{{ $row['article']->title_raw }}</a></td>
                        <td>{{ $row['evaluation']?->score !== null ? number_format($row['evaluation']->score, 1) . '点' : '-' }}</td>
                        <td>{{ $row['evaluation'] ? \App\Support\DisplayTime::format($row['evaluation']->created_at) : '-' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4">対象の記事はありません。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    </section>

@endsection
