{{--
    BlogOSのAI機能の実行（実行モードと、人が提供する情報の入力）

    AIに許可するのは、分析・提案と、編集案の作成まで（D-07-01）。WordPressへの反映は、編集案の画面から人が承認して行う。
--}}

@extends('layouts.app')

@section('content')

    <h1>AIの実行：{{ $mode->label() }}</h1>

    <p>
        <a href="{{ route('ai.generations.index') }}">AI実行記録の一覧</a>
        @if ($draft) ・対象：<a href="{{ route('drafts.edit', ['id' => $draft->id]) }}">編集案 #{{ $draft->id }}「{{ $draft->title_raw }}」</a>
        @elseif ($article) ・対象：<a href="{{ route('articles.show', ['type' => $article instanceof \App\Models\Post ? 'posts' : 'pages', 'id' => $article->id]) }}">{{ $article->title_raw }}</a>
        @endif
    </p>

    @include('partials.flash')

    <p>
        実行モード：
        @foreach ($modes as $option)
            @if ($option === $mode)
                <strong>{{ $option->label() }}</strong>
            @else
                <a href="{{ route('ai.generations.create', array_filter(['mode' => $option->value, 'target' => $target])) }}">{{ $option->label() }}</a>
            @endif
        @endforeach
    </p>

    <p style="color:#666;">
        @switch ($mode)
            @case (\App\Enums\AiMode::QualityDiagnosis) 品質基準に沿って採点します。結果はAIの評価（参考値）として保存し、人が見直して確定します。 @break
            @case (\App\Enums\AiMode::Revision) 改修案を作り、編集案に取り込みます（作業中の編集案があれば、その編集案を改修します）。 @break
            @case (\App\Enums\AiMode::NewArticle) 新しい記事の案を作り、新規の編集案にします。 @break
            @case (\App\Enums\AiMode::SeoAnalysis) SEOの観点で分析し、改善を提案します（記事は変更しません）。 @break
            @case (\App\Enums\AiMode::Structure) 記事の構成案を作ります（本文は書きません）。 @break
            @case (\App\Enums\AiMode::ManagementSuggestion) 記事種類・キーワード・検索意図の案を作ります。案は「管理情報の案の確認」の画面で、人が確認して登録します。 @break
        @endswitch
    </p>

    @if (in_array($mode, [\App\Enums\AiMode::QualityDiagnosis, \App\Enums\AiMode::Revision, \App\Enums\AiMode::SeoAnalysis, \App\Enums\AiMode::ManagementSuggestion], true) && $target === null)
        <p style="color:#b00;">このモードは、記事または編集案の画面から実行してください。</p>
    @else
        <form method="POST" action="{{ route('ai.generations.store') }}">
            @csrf
            @include('partials.selected-blog-field')
            <input type="hidden" name="mode" value="{{ $mode->value }}">
            <input type="hidden" name="target" value="{{ $target }}">

            @if ($mode === \App\Enums\AiMode::Revision)
                <p>
                    <label>改修範囲
                        <select name="revision_scope">
                            @foreach ($scopes as $scope)
                                <option value="{{ $scope->value }}" @selected(old('revision_scope', 'minor') === $scope->value)>{{ $scope->label() }}</option>
                            @endforeach
                        </select>
                    </label>
                    （全面改修は、人が指定した場合だけ行います。D-06-01）
                </p>
            @endif

            @if (in_array($mode, [\App\Enums\AiMode::NewArticle, \App\Enums\AiMode::Structure], true))
                @if ($mode === \App\Enums\AiMode::NewArticle)
                    <p><label>記事の種類 <select name="target_type"><option>投稿</option><option>固定ページ</option></select></label></p>
                @endif
                <p>
                    <label>記事種類
                        <select name="article_type">
                            <option value="">（指定しない）</option>
                            @foreach ($articleTypes['types'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('article_type') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>細分類
                        <select name="article_subtype">
                            <option value="">（指定しない）</option>
                            @foreach ($articleTypes['subtypes'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('article_subtype') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                </p>
            @endif

            @if (! in_array($mode, [\App\Enums\AiMode::QualityDiagnosis, \App\Enums\AiMode::ManagementSuggestion], true))
                <p><label>メインキーワード<br><input type="text" name="main_keyword" value="{{ old('main_keyword') }}" style="width:100%; max-width:400px;"></label></p>
                <p><label>サブキーワード（1行に1つ）<br><textarea name="sub_keywords" rows="2" style="width:100%; max-width:600px;">{{ old('sub_keywords') }}</textarea></label></p>
                <p><label>検索意図<br><textarea name="search_intent" rows="2" style="width:100%; max-width:600px;">{{ old('search_intent') }}</textarea></label></p>
                <p>
                    <label>補足：実体験・検証の結果・伝えたいこと・競合記事の情報など<br>
                        <textarea name="notes" rows="6" style="width:100%; max-width:800px;">{{ old('notes') }}</textarea>
                    </label><br>
                    <span style="color:#666;">AIは、ここに書かれた実体験・検証の結果だけを使います（経験していないことを事実として書かせないため。D-14-10）。</span>
                </p>
            @endif

            {{-- 実行方式（実行ごとに選べる。D-24） --}}
            @php
                $selectedMethod = old('execution_method', $method);
                $selectedModel = old('model', $api['defaults']['model']);
                $selectedEffort = old('reasoning_effort', $api['defaults']['effort']);
            @endphp
            <fieldset style="max-width:800px;">
                <legend>実行方式</legend>
                <p>
                    <label><input type="radio" name="execution_method" value="manual" @checked($selectedMethod === 'manual')> 手動実行（指示文をChatGPT等に貼り付けて実行し、回答を貼り付けます。追加の料金はかかりません）</label><br>
                    <label><input type="radio" name="execution_method" value="api" @checked($selectedMethod === 'api') @disabled(! $api['configured'])> API実行（BlogOSがOpenAI APIで実行し、結果を取り込みます。料金がかかります）</label>
                    @unless ($api['configured'])
                        <br><span style="color:#b00;">APIキーが設定されていないため、API実行は選べません（.env の OPENAI_API_KEY）。</span>
                    @endunless
                </p>
                @if ($api['configured'])
                    <p>
                        <label>モデル
                            <select name="model" id="api-model">
                                @foreach ($api['models'] as $name => $price)
                                    <option value="{{ $name }}" data-efforts="{{ implode(',', $price['efforts']) }}" @selected($selectedModel === $name)>{{ $name }}（入力 ${{ $price['input'] }}・出力 ${{ $price['output'] }} / 1Mトークン）</option>
                                @endforeach
                            </select>
                        </label>
                        <label>推論の深さ
                            <select name="reasoning_effort" id="api-effort">
                                @foreach (['none' => 'none（推論なし）', 'low' => 'low', 'medium' => 'medium', 'high' => 'high'] as $value => $label)
                                    <option value="{{ $value }}" @selected($selectedEffort === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                    </p>
                    <p style="color:#666;">
                        このモードの標準：{{ $api['defaults']['model'] }}・{{ $api['defaults']['effort'] }}。
                        今月の費用の目安：${{ number_format($api['spent'], 2) }} ／ 上限 ${{ number_format($api['budget'], 2) }}。
                        1回の出力（推論を含む）の上限：{{ number_format($api['maxOutput']) }}トークン。<br>
                        API実行は、Queueの処理（XServerではcronの queue:work、ローカルでは <code>php artisan queue:work</code>）で動きます。
                    </p>
                    <script>
                        // モデルが対応していない推論の深さ（gpt-6-astra の none など）を選べないようにする
                        (() => {
                            const model = document.getElementById('api-model');
                            const effort = document.getElementById('api-effort');
                            const sync = () => {
                                const allowed = model.selectedOptions[0].dataset.efforts.split(',');
                                [...effort.options].forEach(o => { o.disabled = ! allowed.includes(o.value); });
                                if (effort.selectedOptions[0].disabled) { effort.value = allowed.includes('medium') ? 'medium' : allowed[0]; }
                            };
                            model.addEventListener('change', sync);
                            sync();
                        })();
                    </script>
                @endif
            </fieldset>

            <p><button type="submit">指示文を作る（API実行では、そのまま実行します）</button></p>
        </form>
    @endif

@endsection
