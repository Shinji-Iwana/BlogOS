{{--
    AI実行記録（手動実行では、指示文のコピーと回答の貼り付け）。D-07-04、D-07-06、D-07-07
--}}

@extends('layouts.app')

@section('content')

    @php $article = $generation->post ?? $generation->page; @endphp

    <h1>AI実行記録 #{{ $generation->id }}：{{ $generation->purpose->label() }}（{{ $generation->status->label() }}）</h1>

    <p>
        <a href="{{ route('ai.generations.index') }}">AI実行記録の一覧</a>
        @if ($generation->draft) ・<a href="{{ route('drafts.edit', ['id' => $generation->draft->id]) }}">対象の編集案 #{{ $generation->draft->id }}</a>@endif
        @if ($article) ・<a href="{{ route('articles.show', ['type' => $generation->post ? 'posts' : 'pages', 'id' => $article->id]) }}">対象の記事「{{ $article->title_raw }}」</a>@endif
        @if ($generation->material) ・<a href="{{ route('materials.edit', ['id' => $generation->material->id]) }}">対象の教材「{{ $generation->material->name }}」</a>@endif
        @if ($generation->image) ・<a href="{{ route('images.show', ['id' => $generation->image->id]) }}">対象の画像「{{ $generation->image->title }}」</a>@endif
    </p>

    @include('partials.flash')

    @php $isApi = $generation->execution_method === \App\Enums\AiExecutionMethod::Api; @endphp

    @if ($generation->error)
        <p class="text-error">失敗しました：{{ $generation->error }}@unless ($isApi)（回答を直して、もう一度貼り付けられます）@endunless</p>
    @endif

    {{-- API実行：実行中は、10秒ごとに画面を読み込み直す --}}
    @if ($isApi && $generation->status === \App\Enums\AiGenerationStatus::Running)
        <meta http-equiv="refresh" content="10">
        <p>
            <strong>OpenAI API で実行中です</strong>（{{ $generation->model }}・推論の深さ {{ $generation->reasoning_effort }}）。数分かかることがあります。この画面は10秒ごとに更新されます。<br>
            @if ($generation->started_at === null)
                <span class="text-error">まだ処理が始まっていません。Queueの処理が動いているか確認してください（ローカルでは <code>php artisan queue:work</code>）。</span>
            @else
                処理の開始：{{ \App\Support\DisplayTime::format($generation->started_at) }}
            @endif
        </p>
    @endif

    {{-- API実行：失敗したら、同じ指示文でもう一度実行できる（料金がかかる） --}}
    @if ($isApi && $generation->status === \App\Enums\AiGenerationStatus::Failed && $generation->purpose === \App\Enums\AiMode::ImageGeneration)
        <p>画像の生成は、<a href="{{ route('images.show', ['id' => $generation->image_id]) }}">画像の画面</a>から作り直してください。</p>
    @elseif ($isApi && $generation->status === \App\Enums\AiGenerationStatus::Failed && filled($generation->output))
        {{-- 回答はあるが取り込めなかった場合：保存済みの回答で取り込み直す（APIは呼ばない） --}}
        <form method="POST" action="{{ route('ai.generations.reprocess', ['id' => $generation->id]) }}" style="margin-bottom:8px;">
            @csrf
            @include('partials.selected-blog-field')
            <button class="btn-secondary" type="submit">保存済みの回答を、もう一度取り込む</button>
            （APIは呼ばないため、料金はかかりません。取り込みの仕組みを直した後などに使います）
        </form>
    @endif

    @if ($isApi && $generation->status === \App\Enums\AiGenerationStatus::Failed && $generation->purpose !== \App\Enums\AiMode::ImageGeneration)
        <form method="POST" action="{{ route('ai.generations.retry', ['id' => $generation->id]) }}" onsubmit="return confirm('同じ指示文で、もう一度API実行しますか？（料金がかかります）');">
            @csrf
            @include('partials.selected-blog-field')
            <button type="submit">同じ指示文で、もう一度API実行する</button>
            （@include('partials.ai-cost-line')）
        </form>
    @endif

    {{-- 結果 --}}
    @if ($generation->status === \App\Enums\AiGenerationStatus::Succeeded)
        <section class="panel">
        <h2>結果</h2>
        @foreach ($generation->evaluations as $evaluation)
            <p>
                AIの評価：<a href="{{ route('evaluations.show', ['id' => $evaluation->id]) }}"><strong>{{ $evaluation->score !== null ? number_format($evaluation->score, 1) . '点' : '-' }}</strong>（評価 #{{ $evaluation->id }}）</a>
                ・受け入れの目安：{{ $acceptance }}点（受け入れるかは人が判断します）
            </p>
        @endforeach
        @if ($generation->purpose === \App\Enums\AiMode::ManagementSuggestion)
            <p>管理情報の案を作りました：<a href="{{ route('management-suggestions.index') }}"><strong>管理情報の案の確認</strong></a>の画面で確認して登録してください。</p>
        @endif
        @if (in_array($generation->purpose, [\App\Enums\AiMode::MaterialResearch, \App\Enums\AiMode::MaterialDiscovery], true))
            <p>教材の案を作りました：<a href="{{ route('materials.suggestions.index') }}"><strong>教材の案の確認</strong></a>の画面で確認して登録してください。</p>
        @endif
        @if ($generation->image && $generation->purpose->isImageMode())
            <p>画像の案を作りました：<a href="{{ route('images.show', ['id' => $generation->image->id]) }}"><strong>画像「{{ $generation->image->title }}」</strong></a>の画面で確認してください（図解は、画面で PNG にします）。</p>
        @endif
        @if ($generation->purpose === \App\Enums\AiMode::TopicPlanning)
            <p>記事の企画の案を作りました：<a href="{{ route('topics.index') }}"><strong>記事の企画</strong></a>の画面で確認してください。</p>
        @endif
        @if ($generation->purpose === \App\Enums\AiMode::MaterialReview)
            <p>教材の見直しの結果を作りました：<a href="{{ route('materials.reviews.index') }}"><strong>記事の教材の見直し</strong></a>の画面で確認してください。</p>
        @endif
        @foreach ($generation->createdDrafts as $createdDraft)
            <p>編集案に取り込みました：<a href="{{ route('drafts.edit', ['id' => $createdDraft->id]) }}"><strong>編集案 #{{ $createdDraft->id }}「{{ $createdDraft->title_raw }}」</strong></a>（内容を確認し、必要なら直してから、反映の確認へ進んでください）</p>
        @endforeach
        </section>
    @endif

    {{-- 手動実行：回答の貼り付け --}}
    @if (! $isApi && in_array($generation->status, [\App\Enums\AiGenerationStatus::WaitingOutput, \App\Enums\AiGenerationStatus::Failed], true))
        <section class="panel">
        <h2>1. 指示文をコピーして、ChatGPT等で実行する</h2>
        <p>
            <button class="btn-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('prompt').value).then(() => { this.textContent = 'コピーしました'; })">指示文をコピー</button>
            （{{ number_format(mb_strlen($generation->input)) }}文字）
        </p>
        <textarea id="prompt" rows="12" readonly class="mono" style="width:100%; font-size:12px;">{{ $generation->input }}</textarea>
        </section>

        <section class="panel">
        <h2>2. 回答と、使ったモデルを貼り付ける</h2>
        <form method="POST" action="{{ route('ai.generations.submit', ['id' => $generation->id]) }}">
            @csrf
            @include('partials.selected-blog-field')
            <p>
                <label>利用プラン
                    <input type="text" name="service_plan" list="service-plans" value="{{ old('service_plan', $generation->service_plan) }}" style="width:180px;">
                </label>
                <label>モデル（画面に表示されたモデル名）
                    <input type="text" name="model" list="models" value="{{ old('model', $generation->model) }}" style="width:180px;" required>
                </label>
                <label>推論の深さ
                    <input type="text" name="reasoning_effort" list="efforts" value="{{ old('reasoning_effort', $generation->reasoning_effort) }}" style="width:100px;">
                </label>
                <datalist id="service-plans">@foreach ($manual['service_plans'] as $value)<option value="{{ $value }}">@endforeach</datalist>
                <datalist id="models">@foreach ($manual['models'] as $value)<option value="{{ $value }}">@endforeach</datalist>
                <datalist id="efforts">@foreach ($manual['efforts'] as $value)<option value="{{ $value }}">@endforeach</datalist>
            </p>
            <p><textarea name="output" rows="16" class="mono" style="width:100%; font-size:12px;" placeholder="AIの回答を、そのまま貼り付けてください">{{ old('output', $generation->output) }}</textarea></p>
            <button type="submit">回答を取り込む</button>
        </form>

        <form method="POST" action="{{ route('ai.generations.cancel', ['id' => $generation->id]) }}" style="margin-top:8px;">
            @csrf
            @include('partials.selected-blog-field')
            <button class="btn-danger" type="submit">この実行を取り消す</button>
        </form>
        </section>
    @endif

    <section class="panel">
    <h2>記録</h2>
    <table class="data">
        <tr><th style="text-align:left;">実行方式</th><td>{{ $generation->execution_method->label() }}</td></tr>
        <tr><th style="text-align:left;">提供元・利用プラン・モデル・推論の深さ</th><td>{{ $generation->provider ?? '-' }}・{{ $generation->service_plan ?? '-' }}・{{ $generation->model ?? '-' }}・{{ $generation->reasoning_effort ?? '-' }}</td></tr>
        @if ($isApi)
            <tr><th style="text-align:left;">トークン数</th><td>
                @if ($generation->input_tokens !== null)
                    入力 {{ number_format($generation->input_tokens) }}（うちキャッシュ {{ number_format((int) $generation->cached_input_tokens) }}）・出力 {{ number_format($generation->output_tokens) }}（うち推論 {{ number_format((int) $generation->reasoning_tokens) }}）
                @else - @endif
            </td></tr>
            @if ($generation->use_web_search)
                <tr><th style="text-align:left;">Web検索</th><td>使う（検索した回数：{{ $generation->web_search_calls ?? 0 }}回）</td></tr>
            @endif
            <tr><th style="text-align:left;">費用の目安</th><td>{{ $generation->estimated_cost !== null ? '$' . number_format($generation->estimated_cost, 4) : '-' }}（設定の料金表による計算。実際の請求はOpenAIの画面で確認）</td></tr>
        @endif
        <tr><th style="text-align:left;">テンプレート</th><td>{{ $generation->template_key }} {{ $generation->template_version }}</td></tr>
        <tr><th style="text-align:left;">品質基準</th><td>共通基準 {{ $generation->quality_common_version }}{{ $generation->quality_profile ? '、' . $generation->quality_profile . ' ' . $generation->quality_profile_version : '' }}</td></tr>
        @if ($generation->revision_scope)<tr><th style="text-align:left;">改修範囲</th><td>{{ $generation->revision_scope->label() }}</td></tr>@endif
        <tr><th style="text-align:left;">人が提供した情報</th><td style="white-space:pre-wrap;">@foreach ((array) $generation->parameters as $label => $value)@continue(in_array($label, \App\Services\Ai\PromptBuilder::HIDDEN_PARAMETERS, true)){{ $label }}：{{ $value }}
    </section>
@endforeach</td></tr>
        <tr><th style="text-align:left;">日時</th><td>{{ \App\Support\DisplayTime::format($generation->created_at) }}〜{{ \App\Support\DisplayTime::format($generation->completed_at) }}（{{ $generation->requester?->name ?? '-' }}）</td></tr>
    </table>

    @if ($generation->status !== \App\Enums\AiGenerationStatus::WaitingOutput)
        <details><summary>指示文（{{ number_format(mb_strlen($generation->input)) }}文字）</summary><pre style="white-space:pre-wrap; word-break:break-all; max-height:400px; overflow:auto;">{{ $generation->input }}</pre></details>
        @if ($generation->output)
            <details @if (in_array($generation->purpose, [\App\Enums\AiMode::SeoAnalysis, \App\Enums\AiMode::Structure], true)) open @endif><summary>回答</summary><pre style="white-space:pre-wrap; word-break:break-all; max-height:600px; overflow:auto;">{{ $generation->output }}</pre></details>
        @endif
    @endif

@endsection
