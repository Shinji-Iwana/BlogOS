{{--
    記事の詳細（業務画面）。記事の内容（DB）、管理情報・キーワード・関係、内部リンク、反映記録
--}}

@extends('layouts.app')

@section('content')

    <h1>{{ $article->title_raw ?: '（タイトルなし）' }}</h1>

    <p>
        <a href="{{ route('articles.index', ['type' => $type]) }}">一覧に戻る</a>
        ・<a href="{{ route('database.wordpress-records.show', ['table' => $type, 'id' => $article->id]) }}">DBの値と変更履歴</a>
        @if ($article->link)
            ・<a href="{{ $article->link }}" target="_blank" rel="noopener noreferrer">WordPressで見る</a>
        @endif
    </p>

    @include('partials.flash')

    @if ($article->wordpress_deleted_at)
        <p style="color:#b00;"><strong>WordPress側で完全に削除されています。</strong></p>
    @endif

    <table border="1" cellpadding="4" cellspacing="0">
        <tr><th style="text-align:left;">種類</th><td>{{ $isPost ? '投稿' : '固定ページ' }}（WordPress ID：{{ $article->wordpress_id }}）</td></tr>
        <tr><th style="text-align:left;">ステータス</th><td>{{ $article->status }}</td></tr>
        <tr><th style="text-align:left;">スラッグ</th><td>{{ $article->slug }}</td></tr>
        <tr><th style="text-align:left;">公開日・更新日</th><td>{{ \App\Support\DisplayTime::format($article->wordpress_date_gmt) }} ・ {{ \App\Support\DisplayTime::format($article->wordpress_modified_gmt) }}</td></tr>
        @if ($isPost)
            <tr><th style="text-align:left;">カテゴリ・タグ</th><td>{{ $article->categories->pluck('name')->implode('、') ?: 'なし' }} ／ {{ $article->tags->pluck('name')->implode('、') ?: 'なし' }}</td></tr>
        @endif
        <tr><th style="text-align:left;">本文の文字数</th><td>{{ mb_strlen((string) $article->content_raw) }}</td></tr>
        <tr><th style="text-align:left;">メタディスクリプション（AIOSEO）</th><td>
            @if ($article->meta_description_raw !== null)
                {{ $article->meta_description_raw }}（{{ mb_strlen($article->meta_description_raw) }}文字）
            @elseif ($article->meta_description_rendered !== null)
                <strong style="color:#b00;">未設定</strong>（AIOSEOが本文の冒頭から自動で作る説明：{{ \Illuminate\Support\Str::limit($article->meta_description_rendered, 160) }}）
            @else
                取得していません（AIOSEOが無効か、まだ同期していません）
            @endif
        </td></tr>
    </table>

    {{-- 編集案と、ゴミ箱・削除 --}}
    <h2>編集</h2>
    @if ($activeDraft)
        <p>作業中の編集案があります：<a href="{{ route('drafts.edit', ['id' => $activeDraft->id]) }}">編集案 #{{ $activeDraft->id }}（{{ $activeDraft->state->label() }}）</a></p>
    @elseif (! $article->wordpress_deleted_at)
        <form method="POST" action="{{ route('drafts.store') }}">
            @csrf
            @include('partials.selected-blog-field')
            <input type="hidden" name="article_type" value="{{ $type }}">
            <input type="hidden" name="article_id" value="{{ $article->id }}">
            <select name="revision_scope">
                @foreach (\App\Enums\RevisionScope::cases() as $scope)
                    <option value="{{ $scope->value }}">{{ $scope->label() }}</option>
                @endforeach
            </select>
            <button type="submit">この記事の編集案を作る</button>
        </form>
    @endif

    @if (! $article->wordpress_deleted_at && ! $activeDraft)
        <p>
            @if ($article->status !== 'trash')
                <a href="{{ route('articles.trash.confirm', ['type' => $type, 'id' => $article->id]) }}">ゴミ箱へ移動する</a> ・
            @endif
            <a href="{{ route('articles.trash.confirm', ['type' => $type, 'id' => $article->id, 'force' => 1]) }}" style="color:#b00;">完全に削除する</a>
        </p>
    @endif

    {{-- 品質評価とBlogOSのAI機能（D-06-02、D-07-01〜D-07-03） --}}
    <h2>品質評価</h2>
    @php
        $targetKey = ($isPost ? 'posts:' : 'pages:') . $article->id;
        $confirmed = $evaluations->firstWhere('is_confirmed', true);
    @endphp
    <p>
        人が確定した最新の評価：
        @if ($confirmed)
            <a href="{{ route('evaluations.show', ['id' => $confirmed->id]) }}"><strong>{{ number_format($confirmed->score, 1) }}点</strong></a>
            ・{{ \App\Services\Quality\ScoreCalculator::verdict($confirmed->score, $confirmed->required_conditions_passed) }}
            （{{ \App\Support\DisplayTime::format($confirmed->confirmed_at, 'Y-m-d') }}）
        @else
            なし
        @endif
    </p>
    <p>
        <a href="{{ route('ai.generations.create', ['mode' => 'quality_diagnosis', 'target' => $targetKey]) }}">AIで品質診断する</a>
        ・<a href="{{ route('evaluations.create', ['target' => $targetKey]) }}">人が評価する</a>
        ・<a href="{{ route('ai.generations.create', ['mode' => 'revision', 'target' => $targetKey]) }}">AIで改修案を作る</a>
        ・<a href="{{ route('ai.generations.create', ['mode' => 'seo_analysis', 'target' => $targetKey]) }}">AIでSEO分析する</a>
    </p>
    @if ($evaluations->isNotEmpty())
        <table border="1" cellpadding="4" cellspacing="0">
            <thead><tr><th>#</th><th>日時</th><th>主体</th><th>対象</th><th>点数</th><th>必須条件</th><th>確定</th></tr></thead>
            <tbody>
                @foreach ($evaluations as $evaluation)
                    <tr>
                        <td><a href="{{ route('evaluations.show', ['id' => $evaluation->id]) }}">{{ $evaluation->id }}</a></td>
                        <td>{{ \App\Support\DisplayTime::format($evaluation->created_at, 'Y-m-d H:i') }}</td>
                        <td>{{ $evaluation->evaluator_type->label() }}</td>
                        <td>{{ $evaluation->article_draft_id ? '編集案 #' . $evaluation->article_draft_id : '記事' }}</td>
                        <td>{{ $evaluation->score !== null ? number_format($evaluation->score, 1) : '-' }}</td>
                        <td>{{ match ($evaluation->required_conditions_passed) { true => '○', false => '×', default => '未確定' } }}</td>
                        <td>{{ $evaluation->is_confirmed ? '確定' : '' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
    @if ($generations->isNotEmpty())
        <p>AI実行記録：
            @foreach ($generations as $generation)
                <a href="{{ route('ai.generations.show', ['id' => $generation->id]) }}">#{{ $generation->id }} {{ $generation->purpose->label() }}（{{ $generation->status->label() }}）</a>@if (! $loop->last)・@endif
            @endforeach
        </p>
    @endif

    {{-- Googleの指標（保存済みのデータ。D-21-02〜D-21-04） --}}
    <h2>Googleの指標（{{ $googlePeriod[0]->toDateString() }}〜{{ $googlePeriod[1]->toDateString() }}、括弧内はその前の28日）</h2>
    @php
        $ga = $google['ga4']; $gaPrev = $googlePrevious['ga4'];
        $sc = $google['search_console']; $scPrev = $googlePrevious['search_console'];
        $ad = $google['adsense']; $adPrev = $googlePrevious['adsense'];
    @endphp
    <table border="1" cellpadding="4" cellspacing="0">
        <tr><th style="text-align:left;">表示回数（GA4）</th><td>{{ number_format((int) $ga->views) }}（{{ number_format((int) $gaPrev->views) }}）</td></tr>
        <tr><th style="text-align:left;">セッション</th><td>{{ number_format((int) $ga->sessions) }}（{{ number_format((int) $gaPrev->sessions) }}）</td></tr>
        <tr><th style="text-align:left;">検索のクリック・表示回数</th><td>{{ number_format((int) $sc->clicks) }}・{{ number_format((int) $sc->impressions) }}（{{ number_format((int) $scPrev->clicks) }}・{{ number_format((int) $scPrev->impressions) }}）</td></tr>
        <tr><th style="text-align:left;">平均掲載順位</th><td>{{ $sc->position !== null ? round($sc->position, 1) : '-' }}（{{ $scPrev->position !== null ? round($scPrev->position, 1) : '-' }}）</td></tr>
        <tr><th style="text-align:left;">収益（AdSense）</th><td>{{ $ad->earnings !== null ? number_format($ad->earnings, 2) . ' ' . $ad->currency_code : '-' }}（{{ $adPrev->earnings !== null ? number_format($adPrev->earnings, 2) : '-' }}）</td></tr>
    </table>

    @if ($google['channels']->isNotEmpty())
        <p>流入元：
            @foreach ($google['channels'] as $channel)
                {{ $channel->channel_group }} {{ number_format($channel->views) }}@if (! $loop->last)・@endif
            @endforeach
        </p>
    @endif

    <h3>検索クエリ（クリックの多い順、最大50件）</h3>
    @php
        $queryTexts = $google['queries']->pluck('query')->map(fn ($q) => mb_strtolower($q))->all();
    @endphp
    @if ($keywords->isNotEmpty())
        <p>
            登録しているキーワードの出現：
            @foreach ($keywords as $keyword)
                @php $found = collect($queryTexts)->contains(fn ($q) => str_contains($q, mb_strtolower($keyword->keyword))); @endphp
                {{ $keyword->keyword }}（{{ $found ? '検索クエリにあり' : 'なし' }}）@if (! $loop->last)・@endif
            @endforeach
        </p>
    @endif
    <div style="overflow-x:auto;">
        <table border="1" cellpadding="4" cellspacing="0">
            <thead><tr><th>検索クエリ</th><th>クリック</th><th>表示回数</th><th>平均掲載順位</th></tr></thead>
            <tbody>
                @forelse ($google['queries'] as $query)
                    <tr>
                        <td>{{ $query->query }}</td>
                        <td>{{ number_format($query->clicks) }}</td>
                        <td>{{ number_format($query->impressions) }}</td>
                        <td>{{ $query->position !== null ? round($query->position, 1) : '-' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4">データがありません。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- 管理情報（D-08-02） --}}
    <h2>管理情報</h2>
    <form method="POST" action="{{ route('articles.management.update', ['type' => $type, 'id' => $article->id]) }}">
        @csrf
        @method('PUT')
        @include('partials.selected-blog-field')

        <p>
            記事種類：
            @if ($articleTypes['types'] !== [])
                <select name="article_type">
                    <option value="">（未設定）</option>
                    @foreach ($articleTypes['types'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('article_type', $management?->article_type) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            @else
                <input type="text" name="article_type" value="{{ old('article_type', $management?->article_type) }}" maxlength="50">
            @endif

            細分類：
            @if ($articleTypes['subtypes'] !== [])
                <select name="article_subtype">
                    <option value="">（なし）</option>
                    @foreach ($articleTypes['subtypes'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('article_subtype', $management?->article_subtype) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            @else
                <input type="text" name="article_subtype" value="{{ old('article_subtype', $management?->article_subtype) }}" maxlength="50">
            @endif

            作業状態：
            <select name="work_status">
                @foreach ($workStatuses as $workStatus)
                    <option value="{{ $workStatus->value }}" @selected(old('work_status', $management?->work_status?->value ?? 'not_started') === $workStatus->value)>{{ $workStatus->label() }}</option>
                @endforeach
            </select>
        </p>
        <p>
            <label>主の検索意図<br><textarea name="main_search_intent" rows="2" style="width:100%; max-width:700px;">{{ old('main_search_intent', $management?->main_search_intent) }}</textarea></label>
        </p>
        <p>
            <label>副の検索意図（1行に1つ）<br><textarea name="sub_search_intents" rows="3" style="width:100%; max-width:700px;">{{ old('sub_search_intents', implode("\n", $management?->sub_search_intents ?? [])) }}</textarea></label>
        </p>
        <p>
            <label>メインキーワード<br><input type="text" name="main_keyword" value="{{ old('main_keyword', $keywords->firstWhere('keyword_type', \App\Enums\KeywordType::Main)?->keyword) }}" maxlength="191" style="width:100%; max-width:400px;"></label>
        </p>
        <p>
            <label>サブキーワード（1行に1つ）<br><textarea name="sub_keywords" rows="3" style="width:100%; max-width:700px;">{{ old('sub_keywords', $keywords->where('keyword_type', \App\Enums\KeywordType::Sub)->pluck('keyword')->implode("\n")) }}</textarea></label>
        </p>
        <p>
            <label>メモ<br><textarea name="memo" rows="3" style="width:100%; max-width:700px;">{{ old('memo', $management?->memo) }}</textarea></label>
        </p>
        <button type="submit">管理情報を保存する</button>
    </form>

    {{-- 記事同士の関係（D-08-04） --}}
    <h2>記事同士の関係</h2>
    <table border="1" cellpadding="4" cellspacing="0">
        <thead><tr><th>関係</th><th>記事</th><th>並び順</th><th>本文からのリンク</th><th></th></tr></thead>
        <tbody>
            @php
                $linkedPostIds = $outbound->pluck('target_post_id')->filter()->all();
                $linkedPageIds = $outbound->pluck('target_page_id')->filter()->all();
            @endphp
            @forelse ($relations as $relation)
                @php $related = $relation->relatedPost ?? $relation->relatedPage; @endphp
                <tr>
                    <td>{{ $relation->relation_type->label() }}</td>
                    <td>
                        @if ($related)
                            <a href="{{ route('articles.show', ['type' => $relation->relatedPost ? 'posts' : 'pages', 'id' => $related->id]) }}">{{ $related->title_raw ?: '（タイトルなし）' }}</a>
                        @endif
                    </td>
                    <td>{{ $relation->sort_order }}</td>
                    <td>
                        {{-- 設計上の関係と実際の内部リンクを比べ、リンク漏れを見つける（DATABASE 9-4） --}}
                        @if (in_array($relation->related_post_id, $linkedPostIds, true) || in_array($relation->related_page_id, $linkedPageIds, true))
                            あり
                        @else
                            <strong style="color:#b00;">なし</strong>
                        @endif
                    </td>
                    <td>
                        <form method="POST" action="{{ route('articles.relations.destroy', ['type' => $type, 'id' => $article->id, 'relationId' => $relation->id]) }}" style="display:inline;">
                            @csrf
                            @method('DELETE')
                            @include('partials.selected-blog-field')
                            <button type="submit">削除</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5">登録されていません。</td></tr>
            @endforelse
        </tbody>
    </table>

    <form method="GET" action="{{ route('articles.show', ['type' => $type, 'id' => $article->id]) }}" style="margin-top:8px;">
        関係を追加する記事を探す：
        <select name="related_type">
            <option value="posts" @selected($relatedType === 'posts')>投稿</option>
            <option value="pages" @selected($relatedType === 'pages')>固定ページ</option>
        </select>
        <input type="text" name="related_q" value="{{ $relatedQuery }}" placeholder="タイトル・スラッグ">
        <button type="submit">探す</button>
    </form>

    @if ($relatedQuery !== '')
        @forelse ($candidates as $candidate)
            <form method="POST" action="{{ route('articles.relations.store', ['type' => $type, 'id' => $article->id]) }}" style="margin:4px 0;">
                @csrf
                @include('partials.selected-blog-field')
                <input type="hidden" name="related_type" value="{{ $relatedType }}">
                <input type="hidden" name="related_id" value="{{ $candidate->id }}">
                {{ $candidate->title_raw ?: '（タイトルなし）' }}（{{ $candidate->status }}）
                <select name="relation_type">
                    @foreach ($relationTypes as $relationType)
                        <option value="{{ $relationType->value }}">{{ $relationType->label() }}</option>
                    @endforeach
                </select>
                <input type="number" name="sort_order" value="0" min="0" style="width:60px;">
                <button type="submit">追加</button>
            </form>
        @empty
            <p>見つかりませんでした。</p>
        @endforelse
    @endif

    {{-- 内部リンク（本文から抽出） --}}
    <h2>この記事からのリンク（{{ $outbound->count() }}件）</h2>
    <ul>
        @forelse ($outbound as $link)
            <li>
                {{ $link->anchor_text ?: '（テキストなし）' }} →
                @if ($link->targetPost || $link->targetPage)
                    <a href="{{ route('articles.show', ['type' => $link->targetPost ? 'posts' : 'pages', 'id' => ($link->targetPost ?? $link->targetPage)->id]) }}">{{ ($link->targetPost ?? $link->targetPage)->title_raw }}</a>
                @else
                    <span style="color:#b00;">{{ $link->target_url }}（記事が見つかりません）</span>
                @endif
            </li>
        @empty
            <li>ありません。</li>
        @endforelse
    </ul>

    <h2>この記事へのリンク（{{ $inbound->count() }}件）</h2>
    <ul>
        @forelse ($inbound as $link)
            @php $source = $link->sourcePost ?? $link->sourcePage; @endphp
            <li>
                @if ($source)
                    <a href="{{ route('articles.show', ['type' => $link->sourcePost ? 'posts' : 'pages', 'id' => $source->id]) }}">{{ $source->title_raw }}</a>
                @endif
                （{{ $link->anchor_text }}）
            </li>
        @empty
            <li>ありません（孤立している記事の可能性があります）。</li>
        @endforelse
    </ul>

    <h2>本文中の画像（{{ $media->count() }}件）</h2>
    <ul>
        @forelse ($media as $item)
            <li>{{ $item->source_url }} @if ($item->media)（メディア：{{ $item->media->title_raw }}）@endif</li>
        @empty
            <li>ありません。</li>
        @endforelse
    </ul>

    {{-- 反映記録 --}}
    <h2>反映記録</h2>
    <ul>
        @forelse ($operations as $operation)
            <li>
                <a href="{{ route('push-operations.show', ['id' => $operation->id]) }}">#{{ $operation->id }}</a>
                {{ $operation->operation->label() }}・{{ $operation->state->label() }}・{{ \App\Support\DisplayTime::format($operation->created_at) }}（{{ $operation->approver?->name ?? '-' }}）
            </li>
        @empty
            <li>ありません。</li>
        @endforelse
    </ul>

    {{-- 管理情報の変更履歴 --}}
    <h2>管理情報の変更履歴</h2>
    <div style="overflow-x:auto;">
        <table border="1" cellpadding="4" cellspacing="0">
            <thead><tr><th>日時</th><th>項目</th><th>変更前</th><th>変更後</th></tr></thead>
            <tbody>
                @forelse ($histories as $history)
                    <tr>
                        <td>{{ \App\Support\DisplayTime::format($history->changed_at) }}</td>
                        <td>{{ $history->field }}</td>
                        <td>{{ \Illuminate\Support\Str::limit((string) $history->old_value, 200) }}</td>
                        <td>{{ \Illuminate\Support\Str::limit((string) $history->new_value, 200) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4">ありません。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

@endsection
