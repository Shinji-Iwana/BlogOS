{{--
    AIが作った教材の案の確認（D-30）

    教材の情報の案：今の値と案を並べ、写す項目にチェックして写す（値は直せる）
    新しい教材の候補：値を直し、アフィリエイトのリンクを付けて登録する
--}}

@extends('layouts.app')

@section('content')

    @php
        $isResearch = $suggestion->type === \App\Enums\MaterialSuggestionType::Research;
        $display = fn ($source, $field) => \App\Http\Controllers\Materials\MaterialSuggestionController::display($source, $field, $categories->all());
        $data = $suggestion->data;
        $old = fn ($field, $default) => old("values.{$field}", $default);
    @endphp

    <h1>{{ $suggestion->type->label() }}：{{ $suggestion->name }}</h1>

    <p>
        <a href="{{ route('materials.suggestions.index') }}">教材の案の一覧に戻る</a>
        @if ($material) ・教材：<a href="{{ route('materials.edit', ['id' => $material->id]) }}">{{ $material->name }}</a>@endif
        @if ($suggestion->generation) ・<a href="{{ route('ai.generations.show', ['id' => $suggestion->ai_generation_id]) }}">AI実行記録 #{{ $suggestion->ai_generation_id }}</a>@endif
    </p>

    @include('partials.flash')

    <p>種類：{{ $suggestion->kind->label() }}
        @if ($suggestion->relatedMaterial)・<span style="color:#b60;">「{{ $suggestion->relatedMaterial->name }}」の新しい版・後継（登録すると、前の版として関連づけます）</span>@endif
    </p>
    @if ($suggestion->reason)
        <p style="white-space:pre-wrap; max-width:900px;"><strong>AIの理由：</strong>{{ $suggestion->reason }}</p>
    @endif
    @if (! empty($data['sources']))
        <p><strong>根拠のURL：</strong>@foreach ($data['sources'] as $url)<br><a href="{{ $url }}" target="_blank" rel="noopener noreferrer">{{ $url }}</a>@endforeach</p>
    @endif
    <p style="color:#666;">AIの調べた内容は、間違っていることがあります。根拠のURLや販売サイトで確かめてから登録してください。</p>

    <form method="POST" action="{{ $isResearch ? route('materials.suggestions.apply', ['id' => $suggestion->id]) : route('materials.suggestions.register', ['id' => $suggestion->id]) }}">
        @csrf
        @include('partials.selected-blog-field')

        @unless ($isResearch)
            <h2>アフィリエイトのリンク</h2>
            <p style="color:#666;">もしも・Udemyの管理画面でリンクを作って貼り付けてください（URL、または &lt;a href="…"&gt; を含むHTML）。</p>
            <table border="1" cellpadding="4" cellspacing="0" style="max-width:900px;">
                @if ($suggestion->kind === \App\Enums\MaterialKind::Book)
                    <tr><th style="text-align:left;">Amazonのリンク（もしも）</th><td><textarea name="amazon_url" rows="2" style="width:600px;">{{ old('amazon_url') }}</textarea></td></tr>
                    <tr><th style="text-align:left;">楽天のリンク（もしも）</th><td><textarea name="rakuten_url" rows="2" style="width:600px;">{{ old('rakuten_url') }}</textarea></td></tr>
                @else
                    <tr><th style="text-align:left;">アフィリエイトのリンク</th><td><textarea name="affiliate_url" rows="2" style="width:600px;">{{ old('affiliate_url') }}</textarea></td></tr>
                @endif
            </table>
        @endunless

        <h2>{{ $isResearch ? '写す項目を選ぶ' : '教材の情報' }}</h2>
        <div style="overflow-x:auto;">
            <table border="1" cellpadding="4" cellspacing="0">
                <thead>
                    <tr>
                        @if ($isResearch)<th><input type="checkbox" onclick="document.querySelectorAll('.apply-check').forEach(c => c.checked = this.checked)"></th>@endif
                        <th>項目</th>
                        @if ($isResearch)<th>今の値</th>@endif
                        <th>{{ $isResearch ? 'AIの案（直せます）' : '値（直せます）' }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($fields as $field => $label)
                        @php
                            $proposed = $data[$field] ?? null;
                            $current = $isResearch ? $display($material, $field) : '';
                            $proposedText = $display($suggestion, $field);
                            // 写す項目の初期値：案があり、今の値と違う項目
                            $checked = filled($proposed) && $proposedText !== $current;
                        @endphp
                        @continue($field === 'cost_note' || $field === 'duration_note' ? ! $suggestion->kind?->hasCost() : false)
                        @continue(in_array($field, ['isbn', 'amazon_product_url', 'rakuten_product_url'], true) && $suggestion->kind !== \App\Enums\MaterialKind::Book)
                        <tr>
                            @if ($isResearch)<td><input type="checkbox" class="apply-check" name="apply[]" value="{{ $field }}" @checked(in_array($field, (array) old('apply', $checked ? [$field] : []), true))></td>@endif
                            <th style="text-align:left; vertical-align:top;">{{ $label }}</th>
                            @if ($isResearch)<td style="max-width:320px; white-space:pre-wrap; color:#666; vertical-align:top;">{{ $current }}</td>@endif
                            <td style="vertical-align:top;">
                                @switch ($field)
                                    @case ('category_ids')
                                        <select name="values[category_ids][]" multiple size="5" style="min-width:260px;">
                                            @foreach ($categories as $id => $name)
                                                <option value="{{ $id }}" @selected(in_array($id, array_map('intval', (array) $old('category_ids', $proposed ?? [])), true))>{{ $name }}</option>
                                            @endforeach
                                        </select>
                                        @break
                                    @case ('levels')
                                        @foreach (\App\Models\Material::LEVELS as $value => $name)
                                            <label><input type="checkbox" name="values[levels][]" value="{{ $value }}" @checked(in_array($value, (array) $old('levels', $proposed ?? []), true))> {{ $name }}</label>
                                        @endforeach
                                        @break
                                    @case ('scenes')
                                        @foreach (\App\Models\Material::SCENES as $value => $name)
                                            <label style="display:inline-block; margin-right:6px;"><input type="checkbox" name="values[scenes][]" value="{{ $value }}" @checked(in_array($value, (array) $old('scenes', $proposed ?? []), true))> {{ $name }}</label>
                                        @endforeach
                                        @break
                                    @case ('published_on')
                                        <input type="date" name="values[published_on]" value="{{ $old('published_on', $proposed) }}">
                                        @break
                                    @default
                                        @if (in_array($field, $listFields, true) || in_array($field, ['summary', 'target_readers', 'not_for'], true))
                                            <textarea name="values[{{ $field }}]" rows="{{ in_array($field, ['summary', 'merits', 'cautions', 'sources'], true) ? 4 : 2 }}" style="width:480px;">{{ $old($field, $proposedText) }}</textarea>
                                        @else
                                            <input type="text" name="values[{{ $field }}]" value="{{ $old($field, $proposedText) }}" style="width:480px;">
                                        @endif
                                @endswitch
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p><button type="submit">{{ $isResearch ? 'チェックした項目を教材に写す' : '教材として登録する' }}</button></p>
    </form>

    <form method="POST" action="{{ route('materials.suggestions.reject', ['id' => $suggestion->id]) }}" onsubmit="return confirm('この案を不採用にしますか？');">
        @csrf
        @include('partials.selected-blog-field')
        <button type="submit">この案を不採用にする</button>
    </form>

@endsection
