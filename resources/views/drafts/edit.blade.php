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
        ・<a href="{{ route('drafts.preview', ['id' => $draft->id]) }}" target="_blank"><strong>プレビュー（変更前と比較）</strong></a>
    </p>

    @include('partials.flash')

    @if ($conflicts > 0)
        <p class="text-error"><strong>WordPress側で記事が変更されています（競合）。</strong> <a href="{{ route('drafts.conflict.show', ['id' => $draft->id]) }}">競合の解消へ</a></p>
    @endif

    @if ($locked)
        <p class="text-error"><strong>結果が確定していない反映記録があるため、編集・反映できません。</strong> <a href="{{ route('push-operations.index') }}">反映記録を確認する</a></p>
    @endif

    @php $editable = $draft->state->isActive() && ! $locked; @endphp

    <p>
        作成元：{{ $draft->origin->label() }}
        @if ($draft->ai_generation_id)
            （<a href="{{ route('ai.generations.show', ['id' => $draft->ai_generation_id]) }}">AI実行記録 #{{ $draft->ai_generation_id }}</a>
            ・人の修正：{{ $draft->human_edited ? 'あり' : 'なし' }}{{ $draft->edit_ratio !== null && $draft->human_edited ? '（修正の量 ' . round($draft->edit_ratio * 100) . '%）' : '' }}）
        @endif
        @if ($editable)
            ・<a href="{{ route('ai.generations.create', ['mode' => 'quality_diagnosis', 'target' => 'drafts:' . $draft->id]) }}">AIで品質診断する</a>
            ・<a href="{{ route('evaluations.create', ['target' => 'drafts:' . $draft->id]) }}">人が評価する</a>
            ・<a href="{{ route('ai.generations.create', ['mode' => 'revision', 'target' => 'drafts:' . $draft->id]) }}">AIで改修案を作る</a>
        @endif
    </p>
    @if ($evaluations->isNotEmpty() || $articleEvaluation)
        <p>
            @if ($articleEvaluation)
                改修前（記事の最新の評価）：<a href="{{ route('evaluations.show', ['id' => $articleEvaluation->id]) }}">{{ $articleEvaluation->evaluator_type->label() }} {{ $articleEvaluation->score !== null ? number_format($articleEvaluation->score, 1) . '点' : '-' }}</a>
                @if ($evaluations->isNotEmpty()) ／ @endif
            @endif
            @if ($evaluations->isNotEmpty())
                この編集案の評価：
                @foreach ($evaluations as $evaluation)
                    <a href="{{ route('evaluations.show', ['id' => $evaluation->id]) }}">#{{ $evaluation->id }} {{ $evaluation->evaluator_type->label() }} {{ $evaluation->score !== null ? number_format($evaluation->score, 1) . '点' : '-' }}{{ $evaluation->is_confirmed ? '（確定）' : '' }}</a>@if (! $loop->last)・@endif
                @endforeach
            @endif
        </p>
    @endif

    {{-- 指摘 → 改修での対応 → 改修後の確認（D-47） --}}
    @if ($revisionFindings->isNotEmpty())
        @php
            $allItems = $qualityStandard->allItems();
            $before = $revisionFindings->first()->sourceEvaluation;
            $after = $revisionFindings->pluck('checkEvaluation')->filter()->sortByDesc('id')->first();
            $checked = $revisionFindings->whereNotNull('check_status');
        @endphp
        <section class="panel">
        <h2 data-code="FINDINGS">指摘と対応</h2>
        <p class="text-muted">
            記事改修に渡した指摘（番号付き）と、改修での対応、改修後の品質診断での確認です。
            @if ($checked->isEmpty() && $editable)
                改修の結果は、<a href="{{ route('ai.generations.create', ['mode' => 'quality_diagnosis', 'target' => 'drafts:' . $draft->id]) }}">AIで品質診断する</a>と確かめられます（指摘ごとに、解消したかを判定します）。
            @endif
        </p>
        @if ($before || $after)
            <table class="data" style="margin-bottom:8px;">
                <thead><tr><th></th><th>点数</th>@foreach ($qualityStandard->axes as $axis)<th>{{ $axis['label'] }}</th>@endforeach</tr></thead>
                <tbody>
                    @foreach (['改修前' => $before, '改修後' => $after] as $name => $evaluation)
                        <tr>
                            <th style="text-align:left;">{{ $name }}</th>
                            <td>@if ($evaluation)<a href="{{ route('evaluations.show', ['id' => $evaluation->id]) }}">{{ $evaluation->score !== null ? number_format($evaluation->score, 1) . '点' : '-' }}</a>@else - @endif</td>
                            @foreach (array_keys($qualityStandard->axes) as $axisKey)
                                <td>{{ isset($evaluation?->axis_scores[$axisKey]) ? number_format((float) $evaluation->axis_scores[$axisKey], 1) . '%' : '-' }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
        <p>
            解消 {{ $revisionFindings->where('check_status', 'resolved')->count() }}件・一部解消 {{ $revisionFindings->where('check_status', 'partial')->count() }}件・未解消 {{ $revisionFindings->where('check_status', 'unresolved')->count() }}件
            ・未確認 {{ $revisionFindings->whereNull('check_status')->count() }}件（全 {{ $revisionFindings->count() }}件）
        </p>
        <div style="overflow-x:auto;">
            <table class="data" style="font-size:90%;">
                <thead><tr><th>番号</th><th>項目（改修前）</th><th>指摘</th><th>改修での対応</th><th>改修後</th></tr></thead>
                <tbody>
                    @foreach ($revisionFindings as $finding)
                        <tr @if ($finding->check_status === 'unresolved') class="row-danger" @elseif ($finding->check_status === 'partial') class="row-attention" @endif>
                            <td>{{ $finding->number }}</td>
                            <td style="max-width:220px;">{{ $allItems[$finding->item_key]['label'] ?? $qualityStandard->required[$finding->item_key]['label'] ?? $finding->item_key }}（{{ $finding->judgment->label() }}）</td>
                            <td style="max-width:360px;">
                                @if ($finding->location)<strong>どこが：</strong>{{ $finding->location }}<br>@endif
                                @if ($finding->problem)<strong>何が足りないか：</strong>{{ $finding->problem }}<br>@endif
                                @if ($finding->fix)<strong>どう直すか：</strong>{{ $finding->fix }}@endif
                            </td>
                            <td style="max-width:300px;">
                                {{ \App\Models\RevisionFinding::RESPONSES[$finding->response_status] ?? '（回答なし）' }}
                                @if ($finding->response_note)<br><span class="text-muted">{{ $finding->response_note }}</span>@endif
                            </td>
                            <td style="max-width:300px;">
                                {{ \App\Models\RevisionFinding::CHECKS[$finding->check_status] ?? '未確認' }}
                                @if ($finding->check_note)<br><span class="text-muted">{{ $finding->check_note }}</span>@endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($revisionFindings->whereIn('check_status', ['unresolved', 'partial'])->isNotEmpty() && $editable)
            <p>未解消・一部解消の指摘は、<a href="{{ route('ai.generations.create', ['mode' => 'revision', 'target' => 'drafts:' . $draft->id]) }}">もう一度AIで改修案を作る</a>と、改修後の評価の指摘として引き継がれます。</p>
        @endif
        </section>
    @endif

    {{-- 孤立記事：この記事をロードマップに載せる編集案（D-70-06） --}}
    @if ($roadmapDraft)
        <p class="text-warn">
            この記事は、ほかの記事からのリンクが足りません（孤立記事）。ロードマップの<a href="{{ route('drafts.edit', ['id' => $roadmapDraft->id]) }}">編集案 #{{ $roadmapDraft->id }}</a>で、この記事を載せる作業中です。
            その編集案も確かめて反映してください（反映の順番は問いません）。
        </p>
    @endif

    {{-- BlogOS の仕上げ：目印・画像の依頼・広告（D-34） --}}
    @if ($placeholders !== [] || $draftImages->isNotEmpty() || $draft->finish_notes)
        <fieldset class="panel" style="max-width:1000px;">
            <legend data-code="FINISH">画像・目印・BlogOS が入れたもの</legend>

            @if ($placeholders !== [])
                <p class="text-error">
                    <strong>置き換えられていない目印があるため、反映できません：</strong>{{ implode('、', $placeholders) }}<br>
                    画像は、画像の画面で作る（またはアップロードする）→ 確認済みにする → WordPress に登録する、の後に「目印を置き換え直す」を押してください。
                    使わない画像は、本文から目印を削除してください。
                </p>
            @endif

            @if ($draftImages->isNotEmpty())
                <table class="data">
                    <thead><tr><th>目印</th><th>種類</th><th>名前・依頼の内容</th><th>状態</th></tr></thead>
                    <tbody>
                        @foreach ($draftImages as $image)
                            <tr>
                                <td><code>[[画像:{{ $image->id }}]]</code></td>
                                <td>{{ $image->kind->label() }}</td>
                                <td style="max-width:480px;">
                                    <a href="{{ route('images.show', ['id' => $image->id]) }}">{{ $image->title }}</a>
                                    @if ($image->kind === \App\Enums\ImageKind::Screenshot && ! $image->hasFile())
                                        <br><span class="text-warn">撮影の依頼：{{ $image->description }}</span>
                                    @elseif ($image->description)
                                        <br><span class="text-muted">{{ \Illuminate\Support\Str::limit($image->description, 120) }}</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($image->media_id)
                                        WordPress に登録済み
                                    @elseif ($image->isReady())
                                        確認済み（WordPress に未登録）
                                    @elseif ($image->hasFile() || filled($image->svg_source))
                                        案（確認待ち）
                                    @else
                                        <span class="text-warn">{{ $image->kind === \App\Enums\ImageKind::Screenshot ? '撮影待ち' : '未作成' }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            @if ($draft->finish_notes)
                <p style="margin-bottom:0;">BlogOS の仕上げのお知らせ：</p>
                <ul style="margin-top:0;">
                    @foreach ($draft->finish_notes as $note)
                        <li>{{ $note }}</li>
                    @endforeach
                </ul>
            @endif

            @if ($editable)
                <form method="POST" action="{{ route('drafts.finish', ['id' => $draft->id]) }}">
                    @csrf
                    @include('partials.selected-blog-field')
                    <button type="submit" class="btn-secondary">目印を置き換え直す</button>
                    <span class="text-muted">（登録した画像・公開した記事を反映します。広告と広告を含むことの表示も、決まった位置に入れ直します）</span>
                </form>
            @endif
        </fieldset>
    @endif

    @if ($draft->base_wordpress_modified_gmt)
        <p class="text-muted">編集の起点：WordPressの {{ \App\Support\DisplayTime::format($draft->base_wordpress_modified_gmt) }} の版</p>
    @endif

    <form method="POST" action="{{ route('drafts.update', ['id' => $draft->id]) }}" class="panel" data-code="EDITOR">
        @csrf
        @method('PUT')
        @include('partials.selected-blog-field')

        <fieldset @disabled(! $editable) style="border:none; padding:0;">
            <p>
                <label>タイトル<br><input type="text" name="title_raw" value="{{ old('title_raw', $draft->title_raw) }}" style="width:100%; max-width:800px;" oninput="document.getElementById('title-count').textContent = this.value.length"></label><br>
                <span class="text-muted"><span id="title-count">{{ mb_strlen((string) old('title_raw', $draft->title_raw)) }}</span>文字（先頭{{ config('blogos.title_checks.title_key_chars') }}文字にメインキーワード、全体{{ config('blogos.title_checks.title_max_chars') }}文字程度まで）</span>
            </p>
            @if ($titleIssues !== [])
                <div class="text-warn" style="max-width:800px;">
                    タイトル・メタディスクリプションの確認（保存した内容で確認します）：
                    <ul style="margin-top:0;">
                        @foreach ($titleIssues as $issue)
                            <li>{{ $issue }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            <p><label>スラッグ<br><input type="text" name="slug" value="{{ old('slug', \App\Support\Slug::display($draft->slug)) }}" style="width:100%; max-width:400px;"></label></p>
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

            <p>
                <label>メタディスクリプション（AIOSEO。検索結果に表示される説明）<br>
                    <textarea name="meta_description" rows="3" style="width:100%; max-width:800px;" oninput="document.getElementById('meta-description-count').textContent = this.value.length">{{ old('meta_description', $draft->meta_description) }}</textarea>
                </label><br>
                <span class="text-muted"><span id="meta-description-count">{{ mb_strlen((string) old('meta_description', $draft->meta_description)) }}</span>文字。空にすると、AIOSEOが本文の冒頭から自動で作る説明になります。
                @if ($article?->meta_description_raw === null && $article?->meta_description_rendered)
                    （現在は未設定で、自動の説明：{{ \Illuminate\Support\Str::limit($article->meta_description_rendered, 120) }}）
                @endif
                </span>
            </p>
            <p><label>抜粋<br><textarea name="excerpt_raw" rows="3" style="width:100%; max-width:800px;">{{ old('excerpt_raw', $draft->excerpt_raw) }}</textarea></label></p>
            <p><label>本文（WordPressの編集画面の「コードエディター」の内容と同じ形式）<br><textarea name="content_raw" rows="30" class="mono" style="width:100%; font-size:13px;">{{ old('content_raw', $draft->content_raw) }}</textarea></label></p>

            <button type="submit">保存する（BlogOSのDBだけ）</button>
        </fieldset>
    </form>

    @if ($editable)
        <section class="panel">
        <h2 data-code="PUBLISH">状態と反映</h2>
        <p>
            <a href="{{ route('drafts.push.confirm', ['id' => $draft->id]) }}" class="button-link"><strong>反映の確認へ</strong></a>（WordPressに送る内容を確認してから、承認して反映します）
        </p>
        <form method="POST" action="{{ route('drafts.state', ['id' => $draft->id]) }}" style="display:inline;">
            @csrf
            @include('partials.selected-blog-field')
            @if ($draft->state === \App\Enums\DraftState::Editing)
                <input type="hidden" name="state" value="review">
                <button type="submit">確認待ちにする</button>
            @else
                <input type="hidden" name="state" value="editing">
                <button type="submit" class="btn-secondary">作業中に戻す</button>
            @endif
        </form>
        <form method="POST" action="{{ route('drafts.state', ['id' => $draft->id]) }}" style="display:inline;" onsubmit="return confirm('この編集案を破棄しますか？（行は残ります）');">
            @csrf
            @include('partials.selected-blog-field')
            <input type="hidden" name="state" value="discarded">
            <button type="submit" class="btn-danger">破棄する</button>
        </form>
        </section>
    @endif

    <section class="panel">
    <h2 data-code="HISTORY">変更履歴</h2>
    <div style="overflow-x:auto;">
        <table class="data">
            <thead><tr><th>日時</th><th>変更元</th><th>項目</th><th>変更前</th><th>変更後</th></tr></thead>
            <tbody>
                @forelse ($histories as $history)
                    <tr>
                        <td>{{ \App\Support\DisplayTime::format($history->changed_at) }}</td>
                        <td>{{ $history->source->value }}{{ $history->wordpress_push_operation_id ? '（反映 #' . $history->wordpress_push_operation_id . '）' : '' }}</td>
                        <td>{{ $history->field }}</td>
                        <td>{{ \Illuminate\Support\Str::limit((string) \App\Support\DisplayTime::value($history->field, $history->old_value), 150) }}</td>
                        <td>{{ \Illuminate\Support\Str::limit((string) \App\Support\DisplayTime::value($history->field, $history->new_value), 150) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5">ありません。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    </section>

@endsection
