{{--
    カテゴリを指定して、AIで新しい教材の候補を探す（D-30）
--}}

@extends('layouts.app')

@section('content')

    <h1>AIで新しい教材の候補を探す（{{ $blog->display_name }}）</h1>

    <p>
        <a href="{{ route('materials.index') }}">教材の一覧に戻る</a>
        ・<a href="{{ route('materials.suggestions.index') }}">教材の案の確認</a>
    </p>

    @include('partials.flash')

    <p class="text-muted">
        カテゴリの記事のタイトルをAIに渡し、そのカテゴリの読者に紹介できそうな教材を探します。登録済みの教材は除きます。
        候補は「教材の案の確認」で内容を確かめ、アフィリエイトのリンク（もしも・Udemy）を作って貼り付けてから登録します。
        書籍は、楽天ブックスAPIで探した本の一覧もAIに渡します{{ $rakuten ? '' : '（楽天ウェブサービスのアプリIDが設定されていないため、今は使いません）' }}。
    </p>

    <form method="POST" action="{{ route('materials.discover.store') }}">
        @csrf
        @include('partials.selected-blog-field')

        <p>
            <label>カテゴリ
                <select name="category_id" required>
                    <option value="">（選んでください）</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected((int) old('category_id', $selected) === $category->id)>{{ $category->name }}（{{ $category->posts_count }}記事）</option>
                    @endforeach
                </select>
            </label>
        </p>
        <p>
            <label>教材の種類
                <select name="kind">
                    <option value="">すべて</option>
                    @foreach (\App\Enums\MaterialKind::cases() as $option)
                        <option value="{{ $option->value }}" @selected(old('kind') === $option->value)>{{ $option->label() }}</option>
                    @endforeach
                </select>
            </label>
            <label>探す数 <input type="number" name="count" value="{{ old('count', 5) }}" min="1" max="10" style="width:60px;"></label>
            <label>書籍を探す語句（任意） <input type="text" name="words" value="{{ old('words') }}" placeholder="例：JavaScript" style="width:200px;"></label>
        </p>
        <p class="text-muted">「書籍を探す語句」は、楽天ブックスAPIで書名を探す語句です。空なら、カテゴリ名で探します（カテゴリ名が「Event（イベント操作）」のような場合は、「JavaScript イベント」などと入れてください）。</p>
        <p>
            <label>補足（探したい教材の条件など。任意）<br>
                <textarea name="notes" rows="3" style="width:100%; max-width:800px;" placeholder="例：初心者向けを中心に。資格の対策本も含める">{{ old('notes') }}</textarea>
            </label>
        </p>

        <fieldset style="max-width:800px;">
            <legend>実行方式</legend>
            @include('materials.partials.method', ['webSearch' => true, 'prefix' => 'discover'])
            @if ($api['configured'])
                <p class="text-muted">@include('partials.ai-cost-line')。</p>
            @endif
        </fieldset>

        <p><button type="submit">候補を探す</button></p>
    </form>

@endsection
