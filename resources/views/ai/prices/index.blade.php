{{--
    OpenAI API料金表情報（API実行の料金表。全ブログ共通。D-31-03。メニューの「情報 → OpenAI API料金表情報」。D-63-28）

    以前はAIの設定の画面の「API実行の料金表」。料金表は全ブログ共通のため、ブログを選んでいなくても開ける。
    値下がりの確認待ち（反映する・反映しない）は、AIの設定の画面に残す。照合は、定期実行とメニューの「設定 → 即時実行 → OpenAI API料金表との同期」。
--}}

@extends('layouts.app')

@section('content')

    <h1>OpenAI API料金表情報</h1>

    <p><a href="{{ route('home') }}">トップページに戻る</a></p>

    <p class="text-muted">
        費用の目安と月の上限の判定に使う料金です（全ブログ共通）。毎日、定期実行で OpenAI の公式のページと照合します（AIは使わないため、料金はかかりません。すぐ照合するときは、メニューの「設定 → 即時実行 → OpenAI API料金表との同期」）。
        値上がりは自動で反映し、値下がりは <a href="{{ route('ai.settings.edit') }}#prices">AIの設定</a>の画面で確認してから反映します。実際の請求はOpenAIの画面で確認してください。
        変更の記録は、メニューの「履歴 → <a href="{{ route('ai.prices.history') }}">OpenAI API料金表との同期履歴</a>」にあります。
    </p>

    @if ($latestPriceCheck)
        <p class="text-muted">最後の照合：{{ \App\Support\DisplayTime::format($latestPriceCheck->created_at) }}（{{ $latestPriceCheck->succeeded() ? '照合できた' : '読み取れなかった料金あり' }}）</p>
    @endif

    @if ($latestPriceCheck && ! $latestPriceCheck->succeeded())
        <div class="text-error">
            <p><strong>{{ \App\Support\DisplayTime::format($latestPriceCheck->created_at) }} の照合で、読み取れなかった料金があります。</strong>今の料金表のまま計算しています。公式のページの形が変わった可能性があるため、料金を確認してください。</p>
            <ul>@foreach ((array) $latestPriceCheck->messages as $message)<li>{{ $message }}</li>@endforeach</ul>
        </div>
    @endif

    <section class="panel">
    <h2>文章・画像のモデルと Web 検索</h2>
    <div style="overflow-x:auto;">
    <table class="data">
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
    </div>
    <p class="text-muted">料金は1Mトークンあたりの米ドル（標準の処理）。キャッシュされていない入力は、キャッシュの書き込みの料金で計算します。</p>

    </section>

    <section class="panel">
    {{-- 音声の操作のモデルの料金（D-58・D-68。料金表の値で、毎日の公式のページとの照合の対象。D-68-02） --}}
    <h2>音声の操作のモデル</h2>
    @php
        $voice = config('blogos.voice');
        $voicePrices = app(\App\Services\Voice\VoicePrices::class);
        $inUse = fn (bool $used, string $modes) => $used ? "（使用中：方式{$modes}）" : '';
        $checked = fn (string $name) => ($at = $voicePrices->checkedAt($name)) ? \App\Support\DisplayTime::format($at) : '-';
    @endphp
    <div style="overflow-x:auto;">
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
    </div>
    <p class="text-muted">
        方式 A の判断（{{ $voice['text_model'] }}）は、上の表の文章のモデルの料金で計算します。
        音声の操作のモデルの料金も、ほかのモデルと同じく毎日公式のページと照合します（聞き取りとリアルタイム会話は料金のページ、返事の声はモデルのページ）。
    </p>
    </section>

@endsection
