{{--
    カテゴリの立ち上げ（D-41）：子カテゴリごとの ③記事の企画 → ④記事の編集案 → ⑤子ロードマップの編集案
--}}

@extends('layouts.app')

@section('content')

    <h1>カテゴリの立ち上げ：{{ $launch->parentCategory?->name }}（{{ \App\Models\CategoryLaunch::STATUSES[$launch->status] ?? $launch->status }}）</h1>

    <p><a href="{{ route('launches.index') }}">立ち上げの一覧</a>・<a href="{{ route('topics.index') }}">記事の企画</a>・<a href="{{ route('drafts.index') }}">編集案の一覧</a></p>

    @include('partials.flash')
    @include('partials.ai-credit-notice')

    <p style="color:#666;">
        子カテゴリごとに、③記事の企画（{{ $perChild }}件。Web検索ありで約 $0.05） → 案を採用 → ④記事の編集案をまとめて作る（API。1件約 $0.012 と図解） → 編集案を確認 → ⑤子ロードマップの編集案、の順に進めます。
        まだ公開していない記事へのリンクは、タイトルだけになります（公開されると、BlogOS がリンクに切り替える編集案を作ります）。
    </p>

    @foreach ($launch->children as $child)
        @php $p = $progress[$child->id]; $items = $articles[$child->id] ?? collect(); @endphp
        <fieldset style="max-width:1100px; margin-bottom:16px;">
            <legend>
                <strong>{{ $child->name }}</strong>（{{ $child->slug }}）
                {{ $child->isNew() ? '・新しい子カテゴリ（WordPress にはまだない）' : '・既存の子カテゴリ' }}
            </legend>
            @if ($child->scope)<p style="color:#666; margin-top:0;">{{ $child->scope }}</p>@endif
            <p><strong>{{ $p['step'] }}</strong>（確認待ちの案 {{ $p['pending'] }}件・採用 {{ $p['accepted'] }}件・作成中 {{ $p['running'] }}件・編集案 {{ $p['drafts'] }}件）</p>

            {{-- ③ 記事の企画 --}}
            <details @if ($items->isEmpty()) open @endif>
                <summary>③ 記事を企画する{{ $items->isNotEmpty() ? '（企画し直す）' : '' }}</summary>
                <form method="POST" action="{{ route('launches.children.plan', ['id' => $child->id]) }}">
                    @csrf
                    @include('partials.selected-blog-field')
                    @include('materials.partials.method', ['method' => 'api', 'webSearch' => true, 'prefix' => "plan-{$child->id}", 'api' => $api + ['defaults' => config('blogos.ai.api.defaults.topic_planning')]])
                    <button type="submit">記事を企画する</button>
                </form>
            </details>

            @if ($items->isNotEmpty())
                <table border="1" cellpadding="4" cellspacing="0" style="margin-top:8px;">
                    <thead><tr><th>記事の案</th><th>ステップ・優先度</th><th>状態</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($items as $item)
                            <tr>
                                <td style="max-width:440px;">
                                    {{ $item->title }}
                                    <br><span style="color:#666;">キーワード：{{ $item->main_keyword ?? '-' }}@if ($item->article_type)・{{ $types['types'][$item->article_type] ?? $item->article_type }}@endif</span>
                                    @if ($item->duplicate_note)<br><span style="color:#b00;">重複の可能性：{{ $item->duplicate_note }}</span>@endif
                                </td>
                                <td>{{ $item->roadmap_step }}<br>{{ \App\Models\TopicSuggestion::PRIORITIES[$item->priority] ?? '' }}</td>
                                <td>
                                    {{ $item->status->label() }}
                                    @if ($item->articleDraft)<br><a href="{{ route('drafts.edit', ['id' => $item->article_draft_id]) }}">編集案 #{{ $item->article_draft_id }}</a>@endif
                                </td>
                                <td style="white-space:nowrap;">
                                    @if ($item->status === \App\Enums\SuggestionStatus::Pending || ($item->article_draft_id === null && $item->article_generation_id === null))
                                        @foreach (['accept' => '採用', 'reject' => '見送り'] as $action => $label)
                                            <form method="POST" action="{{ route('topics.review', ['id' => $item->id]) }}" style="display:inline;">
                                                @csrf
                                                @include('partials.selected-blog-field')
                                                <input type="hidden" name="action" value="{{ $action }}">
                                                <button type="submit">{{ $label }}</button>
                                            </form>
                                        @endforeach
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            {{-- ④ 記事の編集案 --}}
            @if ($p['accepted'] > $p['drafts'] + $p['running'])
                <form method="POST" action="{{ route('launches.children.articles', ['id' => $child->id]) }}" style="margin-top:8px;" onsubmit="return confirm('採用した記事の案から、記事の編集案をまとめて作りますか？（API実行・料金がかかります）');">
                    @csrf
                    @include('partials.selected-blog-field')
                    @include('materials.partials.method', ['method' => 'api', 'apiOnly' => true, 'prefix' => "articles-{$child->id}", 'api' => $api])
                    <button type="submit" @disabled(! $api['configured'])>④ 採用した記事（{{ $p['accepted'] - $p['drafts'] - $p['running'] }}件）の編集案をまとめて作る</button>
                </form>
            @endif

            {{-- ⑤ 子ロードマップ --}}
            @if ($child->roadmapDraft)
                <p>⑤ 子ロードマップ：<a href="{{ route('drafts.edit', ['id' => $child->roadmap_draft_id]) }}">編集案 #{{ $child->roadmap_draft_id }}「{{ $child->roadmapDraft->title_raw }}」</a></p>
            @elseif ($p['accepted'] > 0 && $p['drafts'] === $p['accepted'])
                <form method="POST" action="{{ route('launches.children.roadmap', ['id' => $child->id]) }}" style="margin-top:8px;">
                    @csrf
                    @include('partials.selected-blog-field')
                    @include('materials.partials.method', ['method' => 'api', 'apiOnly' => true, 'prefix' => "roadmap-{$child->id}", 'api' => $api])
                    <button type="submit" @disabled(! $api['configured'])>⑤ 子ロードマップの編集案を作る</button>
                </form>
            @endif

            {{-- ⑥・⑦ 公開 --}}
            @php
                $postDrafts = $child->drafts->filter(fn ($d) => $d->target_type === \App\Enums\PushResourceType::Post);
                $unpublished = $postDrafts->filter(fn ($d) => $d->state->isActive());
                $ready = $unpublished->filter(fn ($d) => \App\Support\ArticlePlaceholders::remaining($d->content_raw) === []);
                // 初回は5記事、2回目からは残りすべてを、初めから選んでおく
                $checkedIds = ($postDrafts->count() === $unpublished->count() ? $ready->take(5) : $ready)->pluck('id')->all();
                $roadmap = $child->roadmapDraft;
            @endphp
            @if ($unpublished->isNotEmpty() || ($roadmap && $roadmap->state->isActive()))
                <form method="POST" action="{{ route('launches.children.publish', ['id' => $child->id]) }}" style="margin-top:8px;"
                      onsubmit="return confirm('選んだ記事と子ロードマップを、WordPress に公開しますか？{{ $child->isNew() ? '（子カテゴリ「' . $child->name . '」も WordPress に作ります）' : '' }}');">
                    @csrf
                    @include('partials.selected-blog-field')
                    <p style="margin-bottom:0;"><strong>⑥・⑦ 公開</strong>（初回は5記事をまとめて公開し、残りは確認できたものから公開します。公開すると、同じ子カテゴリの記事・ロードマップのリンクが順に切り替わります）</p>
                    <ul style="list-style:none; margin-top:0;">
                        @foreach ($postDrafts as $draft)
                            @php $remaining = \App\Support\ArticlePlaceholders::remaining($draft->content_raw); @endphp
                            <li>
                                @if ($draft->state->isActive())
                                    <label><input type="checkbox" name="drafts[]" value="{{ $draft->id }}" @checked(in_array($draft->id, $checkedIds, true))> {{ $draft->title_raw }}</label>
                                @else
                                    ✓ {{ $draft->title_raw }}（{{ $draft->state->label() }}）
                                @endif
                                ・<a href="{{ route('drafts.edit', ['id' => $draft->id]) }}">編集案 #{{ $draft->id }}</a>
                                @if ($draft->state->isActive() && $remaining !== [])<span style="color:#b00;">（目印が残っているため公開できません：{{ implode('、', $remaining) }}）</span>@endif
                            </li>
                        @endforeach
                        @if ($roadmap && $roadmap->state->isActive())
                            <li><label><input type="checkbox" name="roadmap" value="1" checked> 子ロードマップ「{{ $roadmap->title_raw }}」（記事の後に公開し、公開した記事をリンクにします）</label></li>
                        @endif
                    </ul>
                    <p style="color:#666;">
                        @if ($child->isNew())子カテゴリ「{{ $child->name }}」（{{ $child->slug }}）を WordPress に作ります。@endif
                        子ロードマップを公開するときに、親ロードマップのページがなければ、WordPress の下書き（非公開）として先に作ります（URL を /{{ $launch->parentCategory?->slug }}/{{ $child->slug }}.html にするため）。
                    </p>
                    <button type="submit">選んだものを公開する</button>
                </form>
            @elseif ($postDrafts->isNotEmpty())
                <p>⑥・⑦ 公開：すべて公開しました。</p>
            @endif
        </fieldset>
    @endforeach

    <h2>子カテゴリを加える</h2>
    <form method="POST" action="{{ route('launches.children.store', ['id' => $launch->id]) }}">
        @csrf
        @include('partials.selected-blog-field')
        @include('launches.partials.child-options', ['options' => $options])
        <p><button type="submit">加える</button></p>
    </form>

    <h2>状態</h2>
    <form method="POST" action="{{ route('launches.status', ['id' => $launch->id]) }}">
        @csrf
        @include('partials.selected-blog-field')
        <select name="status">
            @foreach (\App\Models\CategoryLaunch::STATUSES as $value => $label)
                <option value="{{ $value }}" @selected($launch->status === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <button type="submit">変える</button>
    </form>

@endsection
