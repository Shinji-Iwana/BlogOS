{{--
    ブログごとのAIの設定：条件による自動の再評価（D-25）
--}}

@extends('layouts.app')

@section('content')

    <h1>AIの設定（{{ $blog->display_name }}）</h1>

    <p>
        <a href="{{ route('home') }}">トップページに戻る</a>
        ・<a href="{{ route('ai.batches.index') }}">まとめて実行の一覧</a>
    </p>

    @include('partials.flash')

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
    <p style="color:#666;">1日に自動で再評価するのは{{ $config['daily_limit'] }}件まで（超えた分は翌日以降）。月の費用の上限に近づいたら止めます。</p>

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

        <button type="submit">保存する</button>
        @if ($setting->exists)
            <span style="color:#666;">最終更新：{{ \App\Support\DisplayTime::format($setting->updated_at) }}</span>
        @endif
    </form>

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
                        <td>{{ $row['reason']->label() }}</td>
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
