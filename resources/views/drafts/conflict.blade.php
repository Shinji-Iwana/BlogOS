{{--
    競合の解消（WORDPRESS_API 21-2、D-15-03）

    WordPressの最新の内容と編集案の差分を示し、人が対応を選ぶ。自動で統合はしない。
--}}

@extends('layouts.app')

@section('content')

    <h1>競合の解消：編集案 #{{ $draft->id }}</h1>

    <p><a href="{{ route('drafts.edit', ['id' => $draft->id]) }}">編集案に戻る</a></p>

    @include('partials.flash')

    <p>
        編集の起点：{{ \App\Support\DisplayTime::format($draft->base_wordpress_modified_gmt) }} の版 ／
        WordPressの最新：{{ \App\Support\DisplayTime::format($latestModified) }} の版
    </p>

    <h2>項目の違い（WordPressの最新 → 編集案）</h2>
    <table border="1" cellpadding="4" cellspacing="0">
        <thead><tr><th>項目</th><th>WordPressの最新</th><th>編集案</th></tr></thead>
        <tbody>
            @foreach (['title', 'slug', 'status', 'excerpt', 'meta_description', 'featured_media', 'categories', 'tags'] as $field)
                @continue(! array_key_exists($field, $wordpress) && ! array_key_exists($field, $draftValues))
                @php
                    $left = $wordpress[$field] ?? null;
                    $right = $draftValues[$field] ?? null;
                    if ($field === 'slug') {
                        // スラッグは読める形で表示し、符号化の違いは同じとみなす（D-29）
                        [$left, $right] = [\App\Support\Slug::display($left), \App\Support\Slug::display($right)];
                    }
                @endphp
                <tr @if ($left !== $right) style="background:#fff8c5;" @endif>
                    <td>{{ $field }}</td>
                    <td>{{ is_array($left) ? implode(', ', $left) : \Illuminate\Support\Str::limit((string) $left, 200) }}</td>
                    <td>{{ is_array($right) ? implode(', ', $right) : \Illuminate\Support\Str::limit((string) $right, 200) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>本文の違い（- WordPressの最新 / + 編集案）</h2>
    @include('partials.line-diff', ['diff' => $contentDiff])

    <h2>対応を選ぶ</h2>

    <form method="POST" action="{{ route('drafts.conflict.resolve', ['id' => $draft->id]) }}" style="margin-bottom:12px;">
        @csrf
        @include('partials.selected-blog-field')
        <input type="hidden" name="action" value="take_in">
        <button type="submit">WordPressの変更を取り込む</button>
        （DBをWordPressの最新にし、編集の起点を最新にします。編集案は作業中のまま残るので、上の差分を見て編集案を直してください）
    </form>

    <form method="POST" action="{{ route('drafts.conflict.resolve', ['id' => $draft->id]) }}" style="margin-bottom:12px;">
        @csrf
        @include('partials.selected-blog-field')
        <input type="hidden" name="action" value="discard">
        <label><input type="checkbox" name="confirmed" value="1"> 編集案の変更を捨てます</label>
        <button type="submit">編集案を破棄する</button>
    </form>

    <form method="POST" action="{{ route('drafts.conflict.resolve', ['id' => $draft->id]) }}">
        @csrf
        @include('partials.selected-blog-field')
        <input type="hidden" name="action" value="overwrite">
        <label><input type="checkbox" name="confirmed" value="1"> <strong style="color:#b00;">WordPress側で行われた変更は失われます</strong></label>
        <button type="submit">編集案で上書きする（反映の確認へ進む）</button>
    </form>

@endsection
