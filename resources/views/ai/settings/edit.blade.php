{{--
    AIの設定：API実行の料金表（全ブログ共通。D-31-03）と、自動の再評価の今日の対象（D-25）
    条件による自動の再評価の設定は、メニューの「設定 → 定期実行 → 記事の再評価」のポップアップ（ai/settings/reevaluation-modal。D-64）
--}}

@extends('layouts.app')

@section('content')

    <h1>AIの設定（{{ $blog->display_name }}）</h1>

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

    {{-- API実行の料金表（全ブログ共通。D-31-03） --}}
    <section class="panel">
    <h2 id="prices">API実行の料金表（全ブログ共通）</h2>
    <p class="text-muted">
        費用の目安と月の上限の判定に使う料金です。毎日（日本時間4:30）、OpenAIの公式のページと照合します（AIは使わないため、料金はかかりません）。
        値上がりは自動で反映し、値下がりは下で確認してから反映します。実際の請求はOpenAIの画面で確認してください。
    </p>

    @if ($latestPriceCheck && ! $latestPriceCheck->succeeded())
        <div class="text-error">
            <p><strong>{{ \App\Support\DisplayTime::format($latestPriceCheck->created_at) }} の照合で、読み取れなかった料金があります。</strong>今の料金表のまま計算しています。公式のページの形が変わった可能性があるため、料金を確認してください。</p>
            <ul>@foreach ((array) $latestPriceCheck->messages as $message)<li>{{ $message }}</li>@endforeach</ul>
        </div>
    @endif

    @if ($pendingPrices->isNotEmpty())
        <h3 class="text-warn">値下がり（確認待ち）：{{ $pendingPrices->count() }}件</h3>
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
    @endif

    <table class="data" style="margin-top:8px;">
        <thead><tr><th>モデル</th><th>入力</th><th>キャッシュ済みの入力</th><th>キャッシュの書き込み</th><th>出力</th><th>長い入力</th><th>公式のページと照合した日時</th></tr></thead>
        <tbody>
            @foreach ($priceModels as $name => $price)
                <tr>
                    <td>{{ $name }}</td>
                    <td>${{ $price['input'] }}</td>
                    <td>${{ $price['cached_input'] }}</td>
                    <td>${{ $price['cache_write'] }}</td>
                    <td>${{ $price['output'] }}</td>
                    <td>{{ number_format($price['long_context']['threshold_tokens']) }}トークン超：入力×{{ $price['long_context']['input_multiplier'] }}・出力×{{ $price['long_context']['output_multiplier'] }}</td>
                    <td>{{ ($checked = $storedPrices->get($name)?->checked_at) ? \App\Support\DisplayTime::format($checked) : '-' }}</td>
                </tr>
            @endforeach
            @foreach ($imagePriceModels as $name => $price)
                <tr>
                    <td>{{ $name }}（画像）</td>
                    <td colspan="5">文章の入力 ${{ $price['text_input'] }}・画像の入力 ${{ $price['image_input'] }}・画像の出力 ${{ $price['image_output'] }}</td>
                    <td>{{ ($checked = $storedPrices->get($name)?->checked_at) ? \App\Support\DisplayTime::format($checked) : '-' }}</td>
                </tr>
            @endforeach
            <tr>
                <td>Web検索</td>
                <td colspan="5">1回 ${{ $webSearchPrice }}</td>
                <td>{{ ($checked = $storedPrices->get(\App\Models\AiPrice::WEB_SEARCH)?->checked_at) ? \App\Support\DisplayTime::format($checked) : '-' }}</td>
            </tr>
        </tbody>
    </table>
    <p class="text-muted">料金は1Mトークンあたりの米ドル（標準の処理）。キャッシュされていない入力は、キャッシュの書き込みの料金で計算します。</p>

    {{-- 音声の操作のモデルの料金（D-58・D-68。料金表の値で、毎日の公式のページとの照合の対象。D-68-02） --}}
    <h3>音声の操作のモデル</h3>
    @php
        $voice = config('blogos.voice');
        $voicePrices = app(\App\Services\Voice\VoicePrices::class);
        $inUse = fn (bool $used, string $modes) => $used ? "（使用中：方式{$modes}）" : '';
        $checked = fn (string $name) => ($at = $voicePrices->checkedAt($name)) ? \App\Support\DisplayTime::format($at) : '-';
    @endphp
    <table class="data">
        <thead><tr><th>モデル</th><th>使い道</th><th>料金</th><th>公式のページと照合した日時</th></tr></thead>
        <tbody>
            @foreach (\App\Services\Voice\VoicePrices::transcribeModels() as $name)
                <tr>
                    <td>{{ $name }}</td>
                    <td>聞き取り（話した声を文字にする）{{ $inUse($name === $voice['transcribe_model'], 'A') }}{{ $inUse($name === $voice['realtime']['transcription_model'], 'B・C の会話の文字') }}</td>
                    <td>1分 ${{ $voicePrices->transcribePerMinute($name) }}</td>
                    <td>{{ $checked($name) }}</td>
                </tr>
            @endforeach
            @foreach (\App\Services\Voice\VoicePrices::ttsModels() as $name)
                @php $tts = $voicePrices->tts($name); @endphp
                <tr>
                    <td>{{ $name }}</td>
                    <td>返事の声（文字を声にする）{{ $inUse($name === $voice['tts_model'], 'A') }}</td>
                    <td>
                        文字の入力 ${{ $tts['text_input'] }}・音声の出力 ${{ $tts['audio_output'] }}（1Mトークンあたり）<br>
                        1分 約${{ round($tts['per_minute'], 4) }}（音声の出力 1分{{ number_format($voice['prices']['tts_audio_tokens_per_minute']) }}トークン。話す時間は、文字数から見積もる：1秒{{ $voice['chars_per_second'] }}文字）
                    </td>
                    <td>{{ $checked($name) }}</td>
                </tr>
            @endforeach
            @foreach (\App\Services\Voice\VoicePrices::realtimeModels() as $name)
                @php $price = $voicePrices->realtime($name); @endphp
                <tr>
                    <td>{{ $name }}</td>
                    <td>リアルタイム会話（聞き取り・判断・返事の声）{{ $inUse($name === $voice['realtime']['models']['d'], 'B') }}{{ $inUse($name === $voice['realtime']['models']['e'], 'C') }}</td>
                    <td>
                        音声：入力 ${{ $price['audio_input'] }}・キャッシュ済みの入力 ${{ $price['audio_cached_input'] }}・出力 ${{ $price['audio_output'] }}<br>
                        文字：入力 ${{ $price['text_input'] }}・キャッシュ済みの入力 ${{ $price['text_cached_input'] }}・出力 ${{ $price['text_output'] }}（1Mトークンあたり）
                    </td>
                    <td>{{ $checked($name) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <p class="text-muted">
        方式 A の判断（{{ $voice['text_model'] }}）は、上の表の文章のモデルの料金で計算します。
        音声の操作のモデルの料金も、ほかのモデルと同じく毎日公式のページと照合します（聞き取りとリアルタイム会話は料金のページ、返事の声はモデルのページ）。
    </p>

    <form method="POST" action="{{ route('ai.prices.check') }}">
        @csrf
        @include('partials.selected-blog-field')
        <button class="btn-secondary" type="submit">今すぐ公式のページと照合する</button>
        @if ($latestPriceCheck)
            <span class="text-muted">最後の照合：{{ \App\Support\DisplayTime::format($latestPriceCheck->created_at) }}（{{ $latestPriceCheck->succeeded() ? '照合できた' : '読み取れなかった料金あり' }}）</span>
        @endif
    </form>

    @if ($priceHistory->isNotEmpty())
        <details style="margin-top:8px;">
            <summary>料金表の変更の記録（新しい順、{{ $priceHistory->count() }}件）</summary>
            <table class="data">
                <thead><tr><th>日時</th><th>項目</th><th>変更前</th><th>変更後</th><th>状態</th><th>確認した人</th></tr></thead>
                <tbody>
                    @foreach ($priceHistory as $change)
                        <tr>
                            <td>{{ \App\Support\DisplayTime::format($change->decided_at ?? $change->created_at) }}</td>
                            <td>{{ \App\Services\Ai\AiPriceCheckService::label($change->price_key, $change->field) }}</td>
                            <td>{{ $change->old_value ?? '-' }}</td>
                            <td>{{ $change->new_value }}</td>
                            <td>{{ $change->status->label() }}</td>
                            <td>{{ $change->decider?->name ?? '（自動）' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </details>
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
