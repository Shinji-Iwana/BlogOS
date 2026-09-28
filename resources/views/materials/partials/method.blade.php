{{--
    教材のAI実行の実行方式（手動／API）・モデル・推論の深さ・Web検索（D-30）

    $api：configured・models・defaults（省略可）・webSearch（省略可）・spent・budget
    $method：標準の実行方式
    $apiOnly：API実行だけ（実行方式を選ばせない）
    $webSearch：Web検索を選べるか
    $prefix：同じ画面に複数置く場合の、要素のIDの接頭辞
--}}
@php
    $prefix ??= 'm';
    $defaults = $api['defaults'] ?? ['model' => array_key_first($api['models']), 'effort' => 'medium'];
    $selectedMethod = old('execution_method', $api['configured'] ? $method : 'manual');
@endphp
@unless ($apiOnly ?? false)
    <p>
        <label><input type="radio" name="execution_method" value="manual" @checked($selectedMethod === 'manual')> 手動実行（指示文をChatGPT等に貼り付けて、回答を貼り付けます）</label><br>
        <label><input type="radio" name="execution_method" value="api" @checked($selectedMethod === 'api') @disabled(! $api['configured'])> API実行（料金がかかります）</label>
        @unless ($api['configured'])<span style="color:#b00;">（APIキーが設定されていません）</span>@endunless
    </p>
@endunless
@if ($api['configured'])
    <p>
        <label>モデル
            <select name="model" id="{{ $prefix }}-model">
                @foreach ($api['models'] as $name => $price)
                    <option value="{{ $name }}" data-efforts="{{ implode(',', $price['efforts']) }}" @selected(old('model', $defaults['model']) === $name)>{{ $name }}</option>
                @endforeach
            </select>
        </label>
        <label>推論の深さ
            <select name="reasoning_effort" id="{{ $prefix }}-effort">
                @foreach (['none', 'low', 'medium', 'high'] as $value)
                    <option value="{{ $value }}" @selected(old('reasoning_effort', $defaults['effort']) === $value)>{{ $value }}</option>
                @endforeach
            </select>
        </label>
        @if ($webSearch ?? false)
            <input type="hidden" name="web_search" value="0">
            <label><input type="checkbox" name="web_search" value="1" @checked(old('web_search', true))> Web検索を使う（API実行だけ。検索1回ごとに約${{ $api['webSearch']['cost_per_call'] }}、1回の実行で{{ $api['webSearch']['max_calls'] }}回まで）</label>
        @endif
    </p>
    <script>
        (() => {
            const model = document.getElementById('{{ $prefix }}-model');
            const effort = document.getElementById('{{ $prefix }}-effort');
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
