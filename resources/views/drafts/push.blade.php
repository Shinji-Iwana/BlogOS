{{--
    反映の確認と承認（ARCHITECTURE 13-5・18-2）

    WordPressに送る項目と、現在の内容からの変更を示す。人が承認した場合だけ反映する。
    反映の後に記事が公開の状態になる場合は、さらに明示的な確認を求める（段階3の重大な操作）。
--}}

@extends('layouts.app')

@section('content')

    <h1>反映の確認：編集案 #{{ $draft->id }}</h1>

    <p><a href="{{ route('drafts.edit', ['id' => $draft->id]) }}">編集案に戻る</a></p>

    @include('partials.flash')

    <p>
        @if ($article)
            既存の{{ $draft->target_type->label() }}「{{ $article->title_raw }}」（WordPress ID：{{ $article->wordpress_id }}）を<strong>更新</strong>します。
        @else
            新しい{{ $draft->target_type->label() }}を<strong>作成</strong>します。
        @endif
    </p>

    @if ($locked)
        <p style="color:#b00;">結果が確定していない反映記録があるため、反映できません。</p>
    @elseif (! $draft->state->isActive())
        <p style="color:#b00;">この編集案は{{ $draft->state->label() }}のため、反映できません。</p>
    @elseif ($payload === [])
        <p>WordPressの現在の内容から変わっている項目がありません。</p>
    @else
        <h2>送る項目</h2>
        <table border="1" cellpadding="4" cellspacing="0">
            <thead><tr><th>項目</th><th>現在</th><th>反映後</th></tr></thead>
            <tbody>
                @foreach ($payload as $field => $value)
                    @continue($field === 'content')
                    <tr>
                        <td>{{ $field }}</td>
                        <td>{{ is_array($current[$field] ?? null) ? implode(', ', $current[$field]) : \Illuminate\Support\Str::limit((string) ($field === 'slug' ? \App\Support\Slug::display($current[$field] ?? '') : ($current[$field] ?? '')), 200) }}</td>
                        <td><strong>{{ is_array($value) ? implode(', ', $value) : \Illuminate\Support\Str::limit((string) $value, 200) }}</strong></td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if (array_key_exists('content', $payload))
            <h2>本文の変更（{{ mb_strlen((string) ($current['content'] ?? '')) }}文字 → {{ mb_strlen((string) $payload['content']) }}文字）</h2>
            @include('partials.line-diff', ['diff' => $contentDiff])
        @endif

        <form method="POST" action="{{ route('drafts.push.store', ['id' => $draft->id]) }}" style="margin-top:12px;">
            @csrf
            @include('partials.selected-blog-field')

            @if ($willBePublic)
                <p style="color:#b00;"><label><input type="checkbox" name="confirmed_public" value="1"> <strong>反映すると、記事が公開された状態になります（読者に見えます）。確認しました。</strong></label></p>
            @endif

            <p><label><input type="checkbox" name="approved" value="1"> 上の内容をWordPressへ反映することを承認します</label></p>
            <button type="submit">WordPressへ反映する</button>
        </form>
    @endif

@endsection
