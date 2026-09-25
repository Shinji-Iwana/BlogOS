{{--
    編集案の編集（ARCHITECTURE 17-5）

    保存するのはBlogOSのDBだけで、WordPressには送らない。反映は「反映の確認へ」から、人が承認して行う。
--}}

@extends('layouts.app')

@section('content')

    <h1>編集案 #{{ $draft->id }}（{{ $draft->state->label() }}）</h1>

    <p>
        <a href="{{ route('drafts.index') }}">編集案の一覧</a>
        @if ($article)
            ・対象：<a href="{{ route('articles.show', ['type' => $draft->post_id ? 'posts' : 'pages', 'id' => $article->id]) }}">{{ $article->title_raw ?: '（タイトルなし）' }}</a>
        @else
            ・新規の{{ $draft->target_type->label() }}
        @endif
    </p>

    @include('partials.flash')

    @if ($conflicts > 0)
        <p style="color:#b00;"><strong>WordPress側で記事が変更されています（競合）。</strong> <a href="{{ route('drafts.conflict.show', ['id' => $draft->id]) }}">競合の解消へ</a></p>
    @endif

    @if ($locked)
        <p style="color:#b00;"><strong>結果が確定していない反映記録があるため、編集・反映できません。</strong> <a href="{{ route('push-operations.index') }}">反映記録を確認する</a></p>
    @endif

    @php $editable = $draft->state->isActive() && ! $locked; @endphp

    @if ($draft->base_wordpress_modified_gmt)
        <p style="color:#666;">編集の起点：WordPressの {{ \App\Support\DisplayTime::format($draft->base_wordpress_modified_gmt) }} の版</p>
    @endif

    <form method="POST" action="{{ route('drafts.update', ['id' => $draft->id]) }}">
        @csrf
        @method('PUT')
        @include('partials.selected-blog-field')

        <fieldset @disabled(! $editable) style="border:none; padding:0;">
            <p><label>タイトル<br><input type="text" name="title_raw" value="{{ old('title_raw', $draft->title_raw) }}" style="width:100%; max-width:800px;"></label></p>
            <p><label>スラッグ<br><input type="text" name="slug" value="{{ old('slug', $draft->slug) }}" style="width:100%; max-width:400px;"></label></p>
            <p>
                <label>ステータス（反映後の状態）
                    <select name="status">
                        @foreach ($statuses as $value => $label)
                            <option value="{{ $value }}" @selected(old('status', $draft->status) === $value)>{{ $label }}（{{ $value }}）</option>
                        @endforeach
                    </select>
                </label>
                <label>改修範囲
                    <select name="revision_scope">
                        @foreach ($revisionScopes as $scope)
                            <option value="{{ $scope->value }}" @selected(old('revision_scope', $draft->revision_scope?->value ?? 'minor') === $scope->value)>{{ $scope->label() }}</option>
                        @endforeach
                    </select>
                </label>
                <label>アイキャッチ画像のメディアID（0 でなし）
                    <input type="number" name="wordpress_featured_media_id" value="{{ old('wordpress_featured_media_id', $draft->wordpress_featured_media_id ?? 0) }}" min="0" style="width:100px;">
                </label>
            </p>

            @if ($categories->isNotEmpty() || $tags->isNotEmpty())
                <p>
                    カテゴリ：
                    @foreach ($categories as $category)
                        <label style="white-space:nowrap;"><input type="checkbox" name="wordpress_category_ids[]" value="{{ $category->wordpress_id }}" @checked(in_array($category->wordpress_id, old('wordpress_category_ids', $draft->wordpress_category_ids ?? []))) > {{ $category->name }}</label>
                    @endforeach
                </p>
                <p>
                    タグ：
                    @foreach ($tags as $tag)
                        <label style="white-space:nowrap;"><input type="checkbox" name="wordpress_tag_ids[]" value="{{ $tag->wordpress_id }}" @checked(in_array($tag->wordpress_id, old('wordpress_tag_ids', $draft->wordpress_tag_ids ?? []))) > {{ $tag->name }}</label>
                    @endforeach
                </p>
            @endif

            <p><label>抜粋<br><textarea name="excerpt_raw" rows="3" style="width:100%; max-width:800px;">{{ old('excerpt_raw', $draft->excerpt_raw) }}</textarea></label></p>
            <p><label>本文（WordPressの編集画面の「コードエディター」の内容と同じ形式）<br><textarea name="content_raw" rows="30" style="width:100%; font-family:monospace; font-size:13px;">{{ old('content_raw', $draft->content_raw) }}</textarea></label></p>

            <button type="submit">保存する（BlogOSのDBだけ）</button>
        </fieldset>
    </form>

    @if ($editable)
        <h2>状態と反映</h2>
        <p>
            <a href="{{ route('drafts.push.confirm', ['id' => $draft->id]) }}"><strong>反映の確認へ</strong></a>（WordPressに送る内容を確認してから、承認して反映します）
        </p>
        <form method="POST" action="{{ route('drafts.state', ['id' => $draft->id]) }}" style="display:inline;">
            @csrf
            @include('partials.selected-blog-field')
            @if ($draft->state === \App\Enums\DraftState::Editing)
                <input type="hidden" name="state" value="review">
                <button type="submit">確認待ちにする</button>
            @else
                <input type="hidden" name="state" value="editing">
                <button type="submit">作業中に戻す</button>
            @endif
        </form>
        <form method="POST" action="{{ route('drafts.state', ['id' => $draft->id]) }}" style="display:inline;" onsubmit="return confirm('この編集案を破棄しますか？（行は残ります）');">
            @csrf
            @include('partials.selected-blog-field')
            <input type="hidden" name="state" value="discarded">
            <button type="submit">破棄する</button>
        </form>
    @endif

    <h2>変更履歴</h2>
    <div style="overflow-x:auto;">
        <table border="1" cellpadding="4" cellspacing="0">
            <thead><tr><th>日時</th><th>変更元</th><th>項目</th><th>変更前</th><th>変更後</th></tr></thead>
            <tbody>
                @forelse ($histories as $history)
                    <tr>
                        <td>{{ \App\Support\DisplayTime::format($history->changed_at) }}</td>
                        <td>{{ $history->source->value }}{{ $history->wordpress_push_operation_id ? '（反映 #' . $history->wordpress_push_operation_id . '）' : '' }}</td>
                        <td>{{ $history->field }}</td>
                        <td>{{ \Illuminate\Support\Str::limit((string) $history->old_value, 150) }}</td>
                        <td>{{ \Illuminate\Support\Str::limit((string) $history->new_value, 150) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5">ありません。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

@endsection
