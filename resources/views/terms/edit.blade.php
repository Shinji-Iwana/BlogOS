{{--
    カテゴリ・タグ・メディアの情報の更新と削除（WordPressへ反映する。WORDPRESS_API 21-3・22章）

    画面を開いた時点の値を base として送り、反映の直前にWordPressの最新の値と比べて競合を確認する。
--}}

@extends('layouts.app')

@section('content')

    @php $label = \App\Services\Push\TermPushService::label($record); @endphp

    <h1>{{ ['categories' => 'カテゴリ', 'tags' => 'タグ', 'media' => 'メディア'][$type] }}：{{ $label }}</h1>

    <p><a href="{{ route('database.wordpress-records.show', ['table' => $type, 'id' => $record->id]) }}">DBの値と変更履歴に戻る</a></p>

    @include('partials.flash')

    @if ($record->wordpress_deleted_at)
        <p style="color:#b00;">WordPress側で既に削除されています。</p>
    @else
        <h2>情報を更新する</h2>
        <form method="POST" action="{{ route('terms.update', ['type' => $type, 'id' => $record->id]) }}">
            @csrf
            @method('PUT')
            @include('partials.selected-blog-field')

            @foreach ($fields as $field)
                <input type="hidden" name="base[{{ $field }}]" value="{{ $current[$field] }}">

                <p>
                    <label>{{ ['name' => '名前', 'slug' => 'スラッグ', 'description' => '説明', 'parent' => '親カテゴリ', 'title' => 'タイトル', 'alt_text' => '代替テキスト', 'caption' => 'キャプション'][$field] }}<br>
                        @if ($field === 'parent')
                            <select name="values[parent]">
                                <option value="0">（なし）</option>
                                @foreach ($parents as $parent)
                                    <option value="{{ $parent->wordpress_id }}" @selected((int) old('values.parent', $current['parent']) === (int) $parent->wordpress_id)>{{ $parent->name }}</option>
                                @endforeach
                            </select>
                        @elseif (in_array($field, ['description', 'caption'], true))
                            <textarea name="values[{{ $field }}]" rows="3" style="width:100%; max-width:700px;">{{ old("values.{$field}", $current[$field]) }}</textarea>
                        @else
                            <input type="text" name="values[{{ $field }}]" value="{{ old("values.{$field}", $current[$field]) }}" style="width:100%; max-width:500px;">
                        @endif
                    </label>
                </p>
            @endforeach

            <p><label><input type="checkbox" name="approved" value="1"> 変更した項目をWordPressへ反映することを承認します</label></p>
            <button type="submit">WordPressへ反映する</button>
        </form>

        <h2>完全に削除する</h2>
        <p style="color:#b00;">
            WordPressから完全に削除します（ゴミ箱はなく、元に戻せません）。
            関連している投稿：{{ $linkedPostCount }}件
            @if ($type === 'categories')（WordPressは、関連していた投稿のカテゴリを付け替えます）@endif
        </p>
        <form method="POST" action="{{ route('terms.destroy', ['type' => $type, 'id' => $record->id]) }}">
            @csrf
            @method('DELETE')
            @include('partials.selected-blog-field')
            <p><label>確認のため、名前（{{ $label }}）を入力してください：<br><input type="text" name="confirm_name" autocomplete="off"></label></p>
            <p><label><input type="checkbox" name="confirmed" value="1"> 元に戻せないことを確認しました</label></p>
            <button type="submit">完全に削除する</button>
        </form>
    @endif

@endsection
