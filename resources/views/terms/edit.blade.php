{{--
    カテゴリ・タグ・メディアの詳細と、情報の更新・削除（WordPressへ反映する。WORDPRESS_API 21-3・22章）

    「詳細」パネル（項目名と値の表）の題名の行の右端に、編集（鉛筆）と削除（ごみ箱）のアイコンを置く（D-128-01。BLOGOS_UI 2-5）。
    ・編集：編集のポップアップ。画面を開いた時点の値を base として送り、反映の直前にWordPressの最新の値と比べて競合を確認する。
      スラッグを変えると URL が変わるため、スラッグの入力が今の値から変わったときだけ、警告と確認のチェックを出す（D-53・D-128-02）。
      「承認します」が入っていないとき、またはスラッグの確認のチェックが出ていて入っていないときは、「更新」を押せない（サーバーでも確かめる）。
      入力の誤りや反映できなかったときは、ポップアップを開いたままにして誤りを出す（ブログの登録と同じ形。_form。D-128-03）。
    ・削除：共通の削除のポップアップ（partials/delete-modal。D-125-01）。確認のため WordPress ID を入力させる（D-128-04）。
--}}

@extends('layouts.app')

@section('content')

    @php
        $label = \App\Services\Push\TermPushService::label($record);
        $typeName = ['categories' => 'カテゴリ', 'tags' => 'タグ', 'media' => 'メディア'][$type];
        $fieldLabels = ['name' => '名前', 'slug' => 'スラッグ', 'description' => '説明', 'parent' => '親カテゴリ', 'title' => 'タイトル', 'alt_text' => '代替テキスト', 'caption' => 'キャプション'];
        // 並び：カテゴリは親カテゴリを先頭に（確認画面 mock-term-modal）。タグ・メディアは項目の順
        $order = $type === 'categories' ? ['parent', 'name', 'slug', 'description'] : $fields;
        $hasSlugWarning = in_array('slug', $fields, true) && in_array($type, ['categories', 'tags'], true);
        $termEditFailed = old('_form') === 'term-edit' && $errors->any();
    @endphp

    <h1>{{ $typeName }}：{{ $label }}</h1>

    <p>
        @if ($type === 'categories')<a href="{{ route('categories.index') }}">カテゴリの一覧に戻る</a> / @endif
        <a href="{{ route('database.wordpress-records.show', ['table' => $type, 'id' => $record->id]) }}">DBの値と変更履歴</a>
    </p>

    {{-- 編集のポップアップの誤りは、ポップアップの中に出す（D-128-03） --}}
    @unless ($termEditFailed)
        @include('partials.flash')
    @endunless

    @if ($record->wordpress_deleted_at)
        <p class="text-error">WordPress側で既に削除されています。</p>
    @endif

    {{-- 詳細（項目名と値の表）。WordPress側で削除済みなら、編集・削除のアイコンは出さない（D-128-01） --}}
    <section class="panel">
    @include('partials.panel-title', [
        'title'       => '詳細',
        'editModal'   => $record->wordpress_deleted_at ? null : 'term-edit-modal',
        'deleteModal' => $record->wordpress_deleted_at ? null : 'term-delete-modal',
    ])
    <table class="data detail-table">
        @foreach ($order as $field)
            @php
                $value = match ($field) {
                    'parent' => $record->parent?->pathName() ?? '',
                    'slug'   => (string) \App\Support\Slug::display($current['slug'] ?? ''),
                    default  => (string) ($current[$field] ?? ''),
                };
            @endphp
            <tr>
                <th>{{ $fieldLabels[$field] }}</th>
                <td @if (in_array($field, ['description', 'caption'], true)) style="white-space:pre-wrap;" @endif>@if ($value === '')<span class="text-muted">（なし）</span>@else{{ $value }}@endif
                    @if ($field === 'slug' && $record->link)<br>{{ \App\Support\UrlLink::html($record->link) }}@endif
                </td>
            </tr>
        @endforeach
    </table>
    </section>

    @unless ($record->wordpress_deleted_at)
        {{-- 編集のポップアップ（編集のモード。送るボタン「更新」は WordPress を変える。BLOGOS_UI 4-2・4-4）。ironman のパネルの切り抜きに閉じ込められないよう、パネルの外に置く --}}
        <div id="term-edit-modal" class="blog-switch-modal site-modal" data-modal-mode="edit" @if ($termEditFailed) data-modal-autoopen @endif>
            <div class="blog-switch-dialog">
                @include('partials.modal-title', ['title' => $typeName])

                @if ($termEditFailed)
                    <ul class="text-error">
                        @foreach ($errors->all() as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                @endif

                <form method="POST" action="{{ route('terms.update', ['type' => $type, 'id' => $record->id]) }}" id="term-form">
                    @csrf
                    @method('PUT')
                    @include('partials.selected-blog-field')
                    <input type="hidden" name="_form" value="term-edit">
                    @foreach ($fields as $field)
                        <input type="hidden" name="base[{{ $field }}]" value="{{ $current[$field] }}">
                    @endforeach

                    @foreach ($order as $field)
                        @continue($field === 'slug' && in_array('name', $fields, true))
                        @if ($field === 'name' && in_array('slug', $fields, true))
                            {{-- 名前とスラッグは、左右に半分ずつ（狭い画面では縦。D-104-01） --}}
                            <div class="modal-field-row">
                                <label class="modal-field">
                                    <span class="modal-label">名前</span>
                                    <input type="text" name="values[name]" value="{{ old('values.name', $current['name']) }}">
                                </label>
                                <label class="modal-field">
                                    <span class="modal-label">スラッグ</span>
                                    <input type="text" name="values[slug]" value="{{ old('values.slug', \App\Support\Slug::display($current['slug'])) }}">
                                </label>
                            </div>

                            {{-- スラッグを変えると URL が変わる（D-53）。スラッグの入力が今の値から変わったときだけ出す（D-128-02。下の JavaScript） --}}
                            @if ($hasSlugWarning)
                                <div class="text-error" id="slug-warning" style="margin-bottom:12px; display:none;">
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
                                    <p style="margin:0;"><label><input type="checkbox" name="slug_change_confirmed" value="1" id="slug-change-confirmed" @checked(old('slug_change_confirmed'))> URL が変わることを理解したうえで変更します（スラッグを変えるときだけ必要）</label></p>
                                </div>
                            @endif
                        @else
                            <label class="modal-field">
                                <span class="modal-label">{{ $fieldLabels[$field] }}</span>
                                @if ($field === 'parent')
                                    <select name="values[parent]">
                                        <option value="0">（なし）</option>
                                        @foreach ($parents as $parent)
                                            <option value="{{ $parent->wordpress_id }}" @selected((int) old('values.parent', $current['parent']) === (int) $parent->wordpress_id)>{{ $parent->name }}</option>
                                        @endforeach
                                    </select>
                                @elseif (in_array($field, ['description', 'caption'], true))
                                    <textarea name="values[{{ $field }}]" rows="3">{{ old("values.{$field}", $current[$field]) }}</textarea>
                                @else
                                    <input type="text" name="values[{{ $field }}]" value="{{ old("values.{$field}", $field === 'slug' ? \App\Support\Slug::display($current[$field]) : $current[$field]) }}">
                                @endif
                            </label>
                        @endif
                    @endforeach

                    <p><label><input type="checkbox" name="approved" value="1" @checked($termEditFailed && old('approved'))> 変更した項目をWordPressへ反映することを承認します</label></p>
                    <div class="blog-switch-actions">
                        @include('partials.modal-submit', ['mode' => \App\Support\ModalMode::EDIT, 'submitIcon' => \App\Support\ButtonIcon::WORDPRESS])
                        <button type="button" class="btn-secondary" data-modal-close>キャンセル</button>
                    </div>
                </form>
            </div>
        </div>

        <script>
            // 編集のポップアップの「更新」を押せる条件と、スラッグの警告（D-53・D-128-02）。サーバーでも同じことを確かめる。
            // ・スラッグの警告と確認のチェックは、編集のモードで、スラッグの入力が今の値から変わったときだけ出す（新規のモードでは出さない）
            // ・「承認します」が入っていない、またはスラッグの確認のチェックが出ていて入っていないときは、「更新」を押せない
            // ・送るときのブラウザの確認は出さない（ポップアップの上で確かめているため。D-128-02）
            (function () {
                const modal = document.getElementById('term-edit-modal');
                const form = document.getElementById('term-form');
                const submit = form.querySelector('[data-modal-submit]');
                const approved = form.querySelector('[name="approved"]');
                const input = form.querySelector('[name="values[slug]"]');
                const warning = document.getElementById('slug-warning');
                const slugCheck = document.getElementById('slug-change-confirmed');
                const after = document.getElementById('slug-example-after');
                const original = @json((string) \App\Support\Slug::display($current['slug'] ?? ''));
                const escape = (text) => text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                // 大文字と小文字・前後の空白の違いは、変わっていないとみなす（サーバーの Slug::same と同じ）
                const slugChanged = () => input !== null && warning !== null && modal.dataset.modalMode === 'edit'
                    && input.value.trim().toLowerCase() !== original.toLowerCase();

                const update = () => {
                    const changed = slugChanged();
                    if (warning) {
                        warning.style.display = changed ? '' : 'none';
                        if (! changed) {
                            slugCheck.checked = false;
                        }
                    }
                    if (after && changed) {
                        // 例の URL の中の、このカテゴリのスラッグの部分（読める形・符号化した形のどちらでも）を置き換える
                        const pattern = new RegExp('/(' + escape(original) + '|' + escape(encodeURIComponent(original)) + ')/', 'i');
                        after.textContent = after.dataset.template.replace(pattern, '/' + encodeURIComponent(input.value.trim()) + '/');
                    } else if (after) {
                        after.textContent = '（スラッグを変えると、ここに変わった後の URL を出します）';
                    }
                    submit.disabled = ! approved.checked || (changed && ! slugCheck.checked);
                };
                form.addEventListener('input', update);
                form.addEventListener('change', update);
                update();
            })();
        </script>

        {{-- 削除：共通の削除のポップアップ（D-125-01・D-128-04）。表は、カテゴリは親カテゴリ・名前・スラッグ、タグは名前・スラッグ、メディアはタイトル --}}
        @include('partials.delete-modal', [
            'id'           => 'term-delete-modal',
            'title'        => $typeName . ' #' . $record->wordpress_id,
            'rows'         => match ($type) {
                'categories' => ['親カテゴリ' => $record->parent?->pathName() ?? '', '名前' => $current['name'], 'スラッグ' => (string) \App\Support\Slug::display($current['slug'])],
                'tags'       => ['名前' => $current['name'], 'スラッグ' => (string) \App\Support\Slug::display($current['slug'])],
                default      => ['タイトル' => $current['title']],
            },
            'note'         => 'WordPressから完全に削除します。ゴミ箱はなく、元に戻せません。',
            'notices'      => ['関連している投稿：' . $linkedPostCount . '件' . ($type === 'categories' ? '（WordPressは、関連していた投稿のカテゴリを付け替えます）' : '')],
            'action'       => route('terms.destroy', ['type' => $type, 'id' => $record->id]),
            'confirmValue' => $record->wordpress_id,
            'hidden'       => ['_method' => 'DELETE'],
        ])
    @endunless

@endsection
