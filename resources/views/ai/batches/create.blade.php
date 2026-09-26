{{--
    まとめて実行（D-25）：実行モード・対象・モデルを選び、対象の記事と費用の目安を確かめてから実行する
--}}

@extends('layouts.app')

@section('content')

    <h1>AIのまとめて実行：{{ $mode->label() }}</h1>

    <p>
        <a href="{{ route('ai.batches.index') }}">まとめて実行の一覧</a>
        ・<a href="{{ route('ai.settings.edit') }}">AIの設定（自動の再評価）</a>
    </p>

    @include('partials.flash')

    <p>
        実行モード：
        @foreach (\App\Http\Controllers\Ai\AiBatchController::MODES as $option)
            @if ($option === $mode)
                <strong>{{ $option->label() }}</strong>
            @else
                <a href="{{ route('ai.batches.create', ['mode' => $option->value]) }}">{{ $option->label() }}</a>
            @endif
        @endforeach
    </p>

    <p style="color:#666;">
        選んだ記事に、1記事ずつAPI実行します（Queueの処理で順に動きます。ローカルでは <code>php artisan queue:work</code>）。
        結果は記事ごとのAI実行記録に残ります。
        @if ($mode === \App\Enums\AiMode::Revision)
            記事改修の結果は編集案になり、WordPressへの反映は、編集案ごとに人が確認して行います（D-07-01）。
            改修の後に、できた編集案を品質診断し、改修前後の点数を記録します。
            作業中の編集案がある記事は、人の作業を上書きしないため改修しません。
        @elseif ($mode === \App\Enums\AiMode::ManagementSuggestion)
            記事種類・キーワード・検索意図の案を作ります。案は<a href="{{ route('management-suggestions.index') }}">管理情報の案の確認</a>の画面で、人が確認して登録します（自動では登録しません）。
        @endif
    </p>

    @unless ($api['configured'])
        <p style="color:#b00;">APIキーが設定されていないため、まとめて実行はできません（.env の OPENAI_API_KEY）。</p>
    @endunless

    {{-- 1. 条件を選んで、対象を確認する --}}
    <form method="GET" action="{{ route('ai.batches.create') }}">
        <input type="hidden" name="mode" value="{{ $mode->value }}">
        <p>
            <label>対象
                <select name="target">
                    @foreach ($targetOptions as $option)
                        <option value="{{ $option->value }}" @selected($option === $target)>{{ $option->label() }}</option>
                    @endforeach
                </select>
            </label>
            <label>点数の基準（「基準に満たない記事」のとき）
                <input type="number" name="below_score" value="{{ $belowScore }}" min="0" max="100" step="0.1" style="width:70px;">点未満
            </label>
            <label>件数の上限（空なら全て）
                <input type="number" name="limit" value="{{ $limit }}" min="1" style="width:70px;">件
            </label>
        </p>
        <p>
            <label>モデル
                <select name="model" id="batch-model">
                    @foreach ($api['models'] as $name => $price)
                        <option value="{{ $name }}" data-efforts="{{ implode(',', $price['efforts']) }}" @selected($model === $name)>{{ $name }}（入力 ${{ $price['input'] }}・出力 ${{ $price['output'] }} / 1Mトークン）</option>
                    @endforeach
                </select>
            </label>
            <label>推論の深さ
                <select name="reasoning_effort" id="batch-effort">
                    @foreach (['none' => 'none（推論なし）', 'low' => 'low', 'medium' => 'medium', 'high' => 'high'] as $value => $label)
                        <option value="{{ $value }}" @selected($effort === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        </p>
        <button type="submit">対象を確認する</button>
    </form>

    {{-- 2. 対象と費用の目安 --}}
    <h2>対象：{{ count($targets) }}件</h2>
    <p>
        @if ($averageCost !== null)
            費用の目安：約${{ number_format($averageCost * count($targets), 2) }}（{{ $model }} の過去の{{ $mode->label() }}の平均 ${{ number_format($averageCost, 4) }} × {{ count($targets) }}件）。
        @else
            費用の目安：{{ $model }} で{{ $mode->label() }}を実行したことがないため、計算できません。
        @endif
        今月の費用の目安：${{ number_format($api['spent'], 2) }} ／ 上限 ${{ number_format($api['budget'], 2) }}（上限に近づいたら、残りの記事は実行せずに止めます）。
    </p>

    @if ($targets !== [] && $api['configured'])
        <form method="POST" action="{{ route('ai.batches.store') }}" onsubmit="return confirm('{{ count($targets) }}件を {{ $model }}（{{ $effort }}）で実行しますか？（料金がかかります）');">
            @csrf
            @include('partials.selected-blog-field')
            <input type="hidden" name="mode" value="{{ $mode->value }}">
            <input type="hidden" name="target" value="{{ $target->value }}">
            <input type="hidden" name="below_score" value="{{ $belowScore }}">
            <input type="hidden" name="limit" value="{{ $limit }}">
            <input type="hidden" name="model" value="{{ $model }}">
            <input type="hidden" name="reasoning_effort" value="{{ $effort }}">
            @if ($mode === \App\Enums\AiMode::QualityDiagnosis)
                {{-- 品質診断の後に、基準に満たない記事の編集案を続けて作る（D-26） --}}
                <fieldset style="max-width:800px;">
                    <legend>診断の後の編集案の作成</legend>
                    <p>
                        <input type="hidden" name="follow_up_revision" value="0">
                        <label><input type="checkbox" name="follow_up_revision" value="1" checked> 全ての記事の診断が終わったら、基準に満たない記事の編集案を自動で作る</label>
                    </p>
                    <p>
                        <label>基準 <input type="number" name="follow_up_below_score" value="{{ $acceptance }}" min="0" max="100" step="0.1" style="width:70px;">点未満、または必須条件を満たさない記事</label>
                    </p>
                    <p>
                        <label>モデル
                            <select name="follow_up_model">
                                @foreach ($api['models'] as $name => $price)
                                    <option value="{{ $name }}" @selected($revisionDefaults['model'] === $name)>{{ $name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>推論の深さ
                            <select name="follow_up_reasoning_effort">
                                @foreach (['none', 'low', 'medium', 'high'] as $value)
                                    <option value="{{ $value }}" @selected($revisionDefaults['effort'] === $value)>{{ $value }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>改修範囲
                            <select name="revision_scope">
                                @foreach ($scopes as $value => $label)
                                    <option value="{{ $value }}" @selected($value === 'auto')>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                    </p>
                    <p style="color:#666;">
                        作業中の編集案がある記事は、人の作業を上書きしないため改修しません。作った編集案は、人が確認してから反映します。
                        費用の上限や取り消しで診断が途中で止まった場合は、編集案を作りません。
                    </p>
                </fieldset>
            @endif
            @if ($mode === \App\Enums\AiMode::Revision)
                <p>
                    <label>改修範囲
                        <select name="revision_scope">
                            @foreach ($scopes as $value => $label)
                                <option value="{{ $value }}" @selected($value === 'auto')>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                </p>
            @endif
            <p><button type="submit">{{ count($targets) }}件を実行する</button></p>
        </form>
    @endif

    <div style="overflow-x:auto;">
        <table border="1" cellpadding="4" cellspacing="0">
            <thead><tr><th>記事</th><th>最新の評価</th><th>評価日</th>@if ($target === \App\Enums\AiBatchTarget::NeedsReevaluation)<th>理由</th>@endif</tr></thead>
            <tbody>
                @forelse ($targets as $row)
                    <tr>
                        <td><a href="{{ route('articles.show', ['type' => $row['article'] instanceof \App\Models\Post ? 'posts' : 'pages', 'id' => $row['article']->id]) }}">{{ $row['article']->title_raw }}</a></td>
                        <td>{{ $row['evaluation']?->score !== null ? number_format($row['evaluation']->score, 1) . '点' : '-' }}</td>
                        <td>{{ $row['evaluation'] ? \App\Support\DisplayTime::format($row['evaluation']->created_at) : '-' }}</td>
                        @if ($target === \App\Enums\AiBatchTarget::NeedsReevaluation)<td>{{ $row['reason']?->label() }}</td>@endif
                    </tr>
                @empty
                    <tr><td colspan="4">対象の記事はありません（実行中・待機中の記事は除いています）。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <script>
        // モデルが対応していない推論の深さ（gpt-6-astra の none など）を選べないようにする
        (() => {
            const model = document.getElementById('batch-model');
            const effort = document.getElementById('batch-effort');
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
