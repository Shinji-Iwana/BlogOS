{{--
    ブログごとのAIの設定：条件による自動の再評価（D-25）
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

    <h2>条件による自動の再評価</h2>
    <p>
        有効にすると、毎日（日本時間6:00）、次の条件に当てはまる公開中の記事を、自動で品質診断します（API実行・料金がかかります）。
        下の「診断の後の編集案の作成」を有効にしていれば、診断の後に、基準に満たない記事の編集案も作ります。WordPressへの反映は自動では行いません。
    </p>
    <ol>
        <li>まだ評価していない記事、または評価した後に記事が更新された</li>
        <li>品質基準・品質診断のテンプレートのバージョンが変わった</li>
        <li>この記事へのリンクの数が、評価したときから変わった</li>
        <li>アクセスが落ちた：直近{{ $config['traffic']['window_days'] }}日（Googleの数値が確定しない直近{{ $config['traffic']['lag_days'] }}日を除く）のクリック数・表示回数が、その前の{{ $config['traffic']['window_days'] }}日より{{ $config['traffic']['drop_ratio'] * 100 }}%以上減った（前回の評価から{{ $config['traffic']['cooldown_days'] }}日以上たった記事だけ）</li>
        <li>前回の評価から{{ $config['periodic_days'] }}日が過ぎた</li>
    </ol>
    <p style="color:#666;">1日に自動で再評価するのは{{ $config['daily_limit'] }}件まで（超えた分は翌日以降）。OpenAI の残高が足りなくなる見込みになったら止めます。</p>

    @unless ($configured)
        <p style="color:#b00;">APIキーが設定されていないため、有効にしても実行されません（.env の OPENAI_API_KEY）。</p>
    @endunless

    <form method="POST" action="{{ route('ai.settings.update') }}">
        @csrf
        @method('PUT')
        @include('partials.selected-blog-field')
        <p>
            <input type="hidden" name="auto_reevaluation_enabled" value="0">
            <label><input type="checkbox" name="auto_reevaluation_enabled" value="1" @checked(old('auto_reevaluation_enabled', $setting->auto_reevaluation_enabled))> 自動の再評価を有効にする</label>
        </p>
        <p>
            <label>モデル
                <select name="auto_model" id="auto-model">
                    @foreach ($models as $name => $price)
                        <option value="{{ $name }}" data-efforts="{{ implode(',', $price['efforts']) }}" @selected(old('auto_model', $setting->auto_model) === $name)>{{ $name }}（入力 ${{ $price['input'] }}・出力 ${{ $price['output'] }} / 1Mトークン）</option>
                    @endforeach
                </select>
            </label>
            <label>推論の深さ
                <select name="auto_reasoning_effort" id="auto-effort">
                    @foreach (['none' => 'none（推論なし）', 'low' => 'low', 'medium' => 'medium', 'high' => 'high'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('auto_reasoning_effort', $setting->auto_reasoning_effort) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        </p>
        <h3>診断の後の編集案の作成</h3>
        <p>
            <input type="hidden" name="auto_revision_enabled" value="0">
            <label><input type="checkbox" name="auto_revision_enabled" value="1" @checked(old('auto_revision_enabled', $setting->auto_revision_enabled))> 自動の再評価の後、基準（{{ $revision['below_score'] }}点）未満、または必須条件を満たさない記事の編集案を自動で作る</label>
        </p>
        <p>
            <label>モデル
                <select name="auto_revision_model">
                    @foreach ($models as $name => $price)
                        <option value="{{ $name }}" @selected(old('auto_revision_model', $setting->auto_revision_model ?? $revision['model']) === $name)>{{ $name }}</option>
                    @endforeach
                </select>
            </label>
            <label>推論の深さ
                <select name="auto_revision_reasoning_effort">
                    @foreach (['none', 'low', 'medium', 'high'] as $value)
                        <option value="{{ $value }}" @selected(old('auto_revision_reasoning_effort', $setting->auto_revision_reasoning_effort ?? $revision['effort']) === $value)>{{ $value }}</option>
                    @endforeach
                </select>
            </label>
            <label>改修範囲
                <select name="auto_revision_scope">
                    @foreach ($scopeOptions as $value => $label)
                        <option value="{{ $value }}" @selected(old('auto_revision_scope', $setting->auto_revision_scope ?? 'auto') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        </p>
        <p style="color:#666;">改修の後に、できた編集案も品質診断し、改修前後の点数を記録します（まとめて実行の画面・編集案の画面で確認できます）。</p>
        <p style="color:#666;">作業中の編集案がある記事は、人の作業を上書きしないため改修しません。作った編集案は、人が確認してから反映します（WordPressへの反映は自動では行いません）。</p>

        <h2>教材の定期チェック</h2>
        <p>
            <input type="hidden" name="material_check_enabled" value="0">
            <label><input type="checkbox" name="material_check_enabled" value="1" @checked(old('material_check_enabled', $setting->material_check_enabled))> 教材の定期チェックを有効にする</label>
        </p>
        <p style="color:#666;">
            毎日（日本時間6:30）、前回の調査から{{ $materialCheck['interval_months'] }}か月が過ぎた教材を、1日に{{ $materialCheck['daily_limit'] }}件まで、AIで調べ直します（API実行・Web検索を使う・料金がかかります。モデルは「教材の調査」の標準：{{ $materialDefaults['model'] }}・{{ $materialDefaults['effort'] }}）。
            新しい版・後継の講座などは「新しい教材の候補」、情報の変化は「教材の情報の案」になり、<a href="{{ route('materials.suggestions.index') }}">教材の案の確認</a>で人が確認して登録します。
            今日調べる教材：{{ $materialDue }}件。
        </p>

        <button type="submit">保存する</button>
        @if ($setting->exists)
            <span style="color:#666;">最終更新：{{ \App\Support\DisplayTime::format($setting->updated_at) }}</span>
        @endif
    </form>

    {{-- API実行の料金表（全ブログ共通。D-31-03） --}}
    <h2 id="prices">API実行の料金表（全ブログ共通）</h2>
    <p style="color:#666;">
        費用の目安と月の上限の判定に使う料金です。毎日（日本時間4:30）、OpenAIの公式のページと照合します（AIは使わないため、料金はかかりません）。
        値上がりは自動で反映し、値下がりは下で確認してから反映します。実際の請求はOpenAIの画面で確認してください。
    </p>

    @if ($latestPriceCheck && ! $latestPriceCheck->succeeded())
        <div style="color:#b00;">
            <p><strong>{{ \App\Support\DisplayTime::format($latestPriceCheck->created_at) }} の照合で、読み取れなかった料金があります。</strong>今の料金表のまま計算しています。公式のページの形が変わった可能性があるため、料金を確認してください。</p>
            <ul>@foreach ((array) $latestPriceCheck->messages as $message)<li>{{ $message }}</li>@endforeach</ul>
        </div>
    @endif

    @if ($pendingPrices->isNotEmpty())
        <h3 style="color:#b60;">値下がり（確認待ち）：{{ $pendingPrices->count() }}件</h3>
        <p style="color:#666;">公式のページで値下がりを確かめてから、反映してください（ページの読み違いで料金が安くなると、使いすぎを止められなくなるため、自動では反映しません）。</p>
        <table border="1" cellpadding="4" cellspacing="0">
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
                                <button type="submit">反映しない</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <table border="1" cellpadding="4" cellspacing="0" style="margin-top:8px;">
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
    <p style="color:#666;">料金は1Mトークンあたりの米ドル（標準の処理）。キャッシュされていない入力は、キャッシュの書き込みの料金で計算します。</p>

    <form method="POST" action="{{ route('ai.prices.check') }}">
        @csrf
        @include('partials.selected-blog-field')
        <button type="submit">今すぐ公式のページと照合する</button>
        @if ($latestPriceCheck)
            <span style="color:#666;">最後の照合：{{ \App\Support\DisplayTime::format($latestPriceCheck->created_at) }}（{{ $latestPriceCheck->succeeded() ? '照合できた' : '読み取れなかった料金あり' }}）</span>
        @endif
    </form>

    @if ($priceHistory->isNotEmpty())
        <details style="margin-top:8px;">
            <summary>料金表の変更の記録（新しい順、{{ $priceHistory->count() }}件）</summary>
            <table border="1" cellpadding="4" cellspacing="0">
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

    <h2>今日の時点で対象になる記事：{{ count($targets) }}件</h2>
    <p style="color:#666;">
        有効にしていれば、このうち{{ min(count($targets), $remaining) }}件を次の実行で再評価します（今日の残り {{ $remaining }}件）。
        今すぐまとめて評価する場合は、<a href="{{ route('ai.batches.create', ['mode' => 'quality_diagnosis', 'target' => 'needs_reevaluation']) }}">まとめて実行</a>から実行できます。
    </p>
    <div style="overflow-x:auto;">
        <table border="1" cellpadding="4" cellspacing="0">
            <thead><tr><th>理由</th><th>記事</th><th>最新の評価</th><th>評価日</th></tr></thead>
            <tbody>
                @forelse ($targets as $row)
                    <tr>
                        <td>{{ $row['reason']->label() }}@if (! empty($row['priority_notes']))<br><span style="color:#b00; font-size:90%;">優先：{{ implode('・', $row['priority_notes']) }}</span>@endif</td>
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

    <script>
        (() => {
            const model = document.getElementById('auto-model');
            const effort = document.getElementById('auto-effort');
            const sync = () => {
                const allowed = model.selectedOptions[0].dataset.efforts.split(',');
                [...effort.options].forEach(o => { o.disabled = ! allowed.includes(o.value); });
                if (effort.selectedOptions[0].disabled) { effort.value = allowed.includes('medium') ? 'medium' : allowed[0]; }
            };
            model.addEventListener('change', sync);
            sync();
        })();
    </script>

@endsection
