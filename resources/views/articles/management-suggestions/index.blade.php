{{--
    AIが作った記事の管理情報の案の確認（D-27-03）：直してから、チェックした案をまとめて登録・不採用にする
--}}

@extends('layouts.app')

@section('content')

    <h1>管理情報の案の確認（{{ $blog->display_name }}）：{{ $rows->count() }}件</h1>

    <p>
        <a href="{{ route('home') }}">トップページに戻る</a>
        ・<a href="{{ route('ai.batches.create', ['mode' => 'management_suggestion']) }}">管理情報の案をまとめて作る</a>
    </p>

    @include('partials.flash')

    <p style="color:#666;">
        AIが作った記事種類・キーワード・検索意図の案です。必要なら直してから、チェックした案を登録してください（登録するまで、記事の管理情報は変わりません）。
        上の段が今の登録内容、入力欄がAIの案です。サブキーワード・副の検索意図は1行に1つです。
    </p>

    @if ($rows->isEmpty())
        <p>確認待ちの案はありません。</p>
    @else
        <form method="POST" action="{{ route('management-suggestions.review') }}">
            @csrf
            @include('partials.selected-blog-field')

            <p>
                <label><input type="checkbox" onclick="document.querySelectorAll('.suggestion-check').forEach(c => c.checked = this.checked)" checked> すべて選ぶ</label>
                <button type="submit" name="action" value="accept">チェックした案を登録する</button>
                <button type="submit" name="action" value="reject" onclick="return confirm('チェックした案を不採用にしますか？');">チェックした案を不採用にする</button>
            </p>

            <div style="overflow-x:auto;">
                <table border="1" cellpadding="4" cellspacing="0">
                    <thead><tr><th></th><th>記事</th><th>記事種類・細分類</th><th>メインキーワード</th><th>サブキーワード</th><th>主の検索意図</th><th>副の検索意図</th><th>AIの理由</th></tr></thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php
                                $s = $row['suggestion'];
                                $m = $row['management'];
                                $main = $row['keywords']->firstWhere('keyword_type', \App\Enums\KeywordType::Main)?->keyword;
                                $subs = $row['keywords']->where('keyword_type', \App\Enums\KeywordType::Sub)->pluck('keyword')->implode('、');
                                $name = "items[{$s->id}]";
                            @endphp
                            <tr>
                                <td rowspan="2"><input type="checkbox" class="suggestion-check" name="selected[]" value="{{ $s->id }}" checked></td>
                                <td rowspan="2">
                                    @if ($row['article'])
                                        <a href="{{ route('articles.show', ['type' => $s->post_id ? 'posts' : 'pages', 'id' => $row['article']->id]) }}">{{ $row['article']->title_raw }}</a>
                                    @else - @endif
                                </td>
                                <td style="color:#666;">今：{{ $types['types'][$m?->article_type] ?? $m?->article_type ?? '未設定' }}{{ $m?->article_subtype ? '（' . ($types['subtypes'][$m->article_subtype] ?? $m->article_subtype) . '）' : '' }}</td>
                                <td style="color:#666;">今：{{ $main ?? '未設定' }}</td>
                                <td style="color:#666;">今：{{ $subs ?: '未設定' }}</td>
                                <td style="color:#666;">今：{{ $m?->main_search_intent ?: '未設定' }}</td>
                                <td style="color:#666;">今：{{ $m?->sub_search_intents ? implode('／', $m->sub_search_intents) : '未設定' }}</td>
                                <td rowspan="2" style="max-width:260px;">{{ $s->reason }}</td>
                            </tr>
                            <tr>
                                <td>
                                    @if ($types['types'] !== [])
                                        <select name="{{ $name }}[article_type]">
                                            <option value="">（なし）</option>
                                            @foreach ($types['types'] as $value => $label)
                                                <option value="{{ $value }}" @selected(old("items.{$s->id}.article_type", $s->article_type) === $value)>{{ $label }}</option>
                                            @endforeach
                                        </select><br>
                                        <select name="{{ $name }}[article_subtype]">
                                            <option value="">（なし）</option>
                                            @foreach ($types['subtypes'] as $value => $label)
                                                <option value="{{ $value }}" @selected(old("items.{$s->id}.article_subtype", $s->article_subtype) === $value)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    @else
                                        <input type="text" name="{{ $name }}[article_type]" value="{{ old("items.{$s->id}.article_type", $s->article_type) }}" style="width:120px;"><br>
                                        <input type="text" name="{{ $name }}[article_subtype]" value="{{ old("items.{$s->id}.article_subtype", $s->article_subtype) }}" style="width:120px;">
                                    @endif
                                </td>
                                <td><input type="text" name="{{ $name }}[main_keyword]" value="{{ old("items.{$s->id}.main_keyword", $s->main_keyword) }}" style="width:160px;"></td>
                                <td><textarea name="{{ $name }}[sub_keywords]" rows="3" style="width:180px;">{{ old("items.{$s->id}.sub_keywords", implode("\n", (array) $s->sub_keywords)) }}</textarea></td>
                                <td><textarea name="{{ $name }}[main_search_intent]" rows="3" style="width:200px;">{{ old("items.{$s->id}.main_search_intent", $s->main_search_intent) }}</textarea></td>
                                <td><textarea name="{{ $name }}[sub_search_intents]" rows="3" style="width:200px;">{{ old("items.{$s->id}.sub_search_intents", implode("\n", (array) $s->sub_search_intents)) }}</textarea></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </form>
    @endif

@endsection
