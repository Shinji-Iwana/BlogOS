{{--
    カテゴリ・タグ・メディアの情報の更新と削除（WordPressへ反映する。WORDPRESS_API 21-3・22章）

    画面を開いた時点の値を base として送り、反映の直前にWordPressの最新の値と比べて競合を確認する。
    スラッグを変えると URL が変わるため、警告を出し、確認のチェックと確認の画面を経て反映する（D-53）。
--}}

@extends('layouts.app')

@section('content')

    @php $label = \App\Services\Push\TermPushService::label($record); @endphp

    <h1>{{ ['categories' => 'カテゴリ', 'tags' => 'タグ', 'media' => 'メディア'][$type] }}：{{ $label }}</h1>

    <p>
        @if ($type === 'categories')<a href="{{ route('categories.index') }}">カテゴリの一覧に戻る</a>・@endif
        <a href="{{ route('database.wordpress-records.show', ['table' => $type, 'id' => $record->id]) }}">DBの値と変更履歴</a>
    </p>

    @include('partials.flash')

    @if ($record->wordpress_deleted_at)
        <p class="text-error">WordPress側で既に削除されています。</p>
    @else
        <section class="panel">
        <h2>情報を更新する</h2>
        <form method="POST" action="{{ route('terms.update', ['type' => $type, 'id' => $record->id]) }}" id="term-form">
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
                            <input type="text" name="values[{{ $field }}]" value="{{ old("values.{$field}", $field === 'slug' ? \App\Support\Slug::display($current[$field]) : $current[$field]) }}" style="width:100%; max-width:500px;">
                        @endif
                    </label>
                </p>

                {{-- スラッグを変えると URL が変わる（D-53） --}}
                @if ($field === 'slug' && in_array($type, ['categories', 'tags'], true))
                    <div class="text-warn" id="slug-warning" style="max-width:700px;">
                        <p style="margin:0 0 4px;"><strong>スラッグを変えると、URL が変わります。</strong></p>
                        <ul style="margin-top:0;">
                            @if ($type === 'categories')
                                <li>カテゴリのページ：{{ $record->link }}</li>
                                <li>このカテゴリと子カテゴリの記事 <strong>{{ $slugImpact['count'] }}件</strong> の URL（記事の URL にカテゴリのスラッグが入るため）
                                    @if ($slugImpact['example'])
                                        <br>例：{{ $slugImpact['example']->link }}
                                        <br>　→ <span id="slug-example-after" data-template="{{ $slugImpact['example']->link }}">（スラッグを変えると、ここに変わった後の URL を出します）</span>
                                    @endif
                                </li>
                            @else
                                <li>タグのページ：{{ $record->link }}</li>
                            @endif
                            <li>検索エンジンの評価の引き継ぎ・外部のサイトやブックマークからのリンク・Search Console の記録に影響します。記事の中の内部リンクは古い URL のままになります（「内部リンクの確認」で見つかります）。</li>
                        </ul>
                        <p style="margin:0;"><label><input type="checkbox" name="slug_change_confirmed" value="1" id="slug-change-confirmed"> URL が変わることを理解したうえで変更します（スラッグを変えるときだけ必要）</label></p>
                        @error('slug_change_confirmed')<p class="text-error" style="margin:4px 0 0;">{{ $message }}</p>@enderror
                    </div>
                @endif
            @endforeach

            <p><label><input type="checkbox" name="approved" value="1"> 変更した項目をWordPressへ反映することを承認します</label></p>
            <button type="submit">WordPressへ反映する</button>
        </form>
        </section>

        @if (in_array($type, ['categories', 'tags'], true))
            <script>
                // スラッグを変えたときだけ警告を強め、変わった後の URL の例を出し、反映の前に確認する（D-53）
                (function () {
                    const form = document.getElementById('term-form');
                    const input = form.querySelector('[name="values[slug]"]');
                    const original = @json((string) \App\Support\Slug::display($current['slug'] ?? ''));
                    const count = @json($slugImpact['count'] ?? null);
                    const after = document.getElementById('slug-example-after');
                    const warning = document.getElementById('slug-warning');
                    const changed = () => input.value.trim().toLowerCase() !== original.toLowerCase();
                    const escape = (text) => text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

                    const update = () => {
                        warning.classList.toggle('text-error', changed());
                        warning.classList.toggle('text-warn', ! changed());
                        if (after && changed()) {
                            // 例の URL の中の、このカテゴリのスラッグの部分（読める形・符号化した形のどちらでも）を置き換える
                            const pattern = new RegExp('/(' + escape(original) + '|' + escape(encodeURIComponent(original)) + ')/', 'i');
                            after.textContent = after.dataset.template.replace(pattern, '/' + encodeURIComponent(input.value.trim()) + '/');
                        } else if (after) {
                            after.textContent = '（スラッグを変えると、ここに変わった後の URL を出します）';
                        }
                    };
                    input.addEventListener('input', update);
                    update();

                    form.addEventListener('submit', (event) => {
                        if (! changed()) {
                            return;
                        }
                        if (! document.getElementById('slug-change-confirmed').checked) {
                            event.preventDefault();
                            alert('スラッグを変えると URL が変わります。影響を確認し、「URL が変わることを理解したうえで変更します」にチェックを入れてください。');
                            return;
                        }
                        const message = 'スラッグを「' + original + '」から「' + input.value.trim() + '」に変えます。\n'
                            + (count !== null ? 'このカテゴリと子カテゴリの記事 ' + count + '件の URL が変わります。\n' : 'タグのページの URL が変わります。\n')
                            + '本当に変更して、WordPress へ反映しますか？';
                        if (! confirm(message)) {
                            event.preventDefault();
                        }
                    });
                })();
            </script>
        @endif

        <section class="panel">
        <h2>完全に削除する</h2>
        <p class="text-error">
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
            <button class="btn-danger" type="submit">完全に削除する</button>
        </form>
        </section>
    @endif

@endsection
