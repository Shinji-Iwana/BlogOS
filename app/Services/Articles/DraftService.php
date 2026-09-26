<?php

namespace App\Services\Articles;

use App\Enums\ChangeSource;
use App\Enums\DraftOrigin;
use App\Enums\DraftState;
use App\Enums\PushResourceType;
use App\Enums\RevisionScope;
use App\Models\AiGeneration;
use App\Models\ArticleDraft;
use App\Models\Blog;
use App\Models\Page;
use App\Models\Post;
use App\Repositories\AiGenerationRepository;
use App\Repositories\ArticleDraftRepository;
use App\Services\Ai\AiException;
use App\Services\Ai\AiOutputParser;
use App\Services\Push\PushException;
use App\Support\LineDiff;
use App\Support\Slug;

/**
 * 編集案の作成・編集・状態の変更（BLOGOS_DATABASE.md 9-1、ARCHITECTURE 17-5）。
 * WordPressへの反映は App\Services\Push\ArticlePushService が行う。
 */
class DraftService
{
    /**
     * 画面で編集できる列
     */
    public const EDITABLE_COLUMNS = [
        'title_raw', 'content_raw', 'excerpt_raw', 'meta_description', 'slug', 'status',
        'wordpress_category_ids', 'wordpress_tag_ids', 'wordpress_featured_media_id', 'revision_scope',
    ];

    public function __construct(
        protected ArticleDraftRepository $drafts,
        protected AiGenerationRepository $generations,
        protected AiOutputParser $parser,
    ) {
    }

    /**
     * 既存記事の編集案を作る。記事の現在の内容（DB）を写し、その版を編集の起点とする（D-01-08）。
     *
     * @throws PushException 作業中の編集案が既にある場合
     */
    public function createFromArticle(Post|Page $article, ?RevisionScope $scope, ?int $userId, ?int $aiGenerationId = null): ArticleDraft
    {
        if ($article->wordpress_deleted_at !== null) {
            throw new PushException('WordPress側で削除された記事には、編集案を作れません。');
        }

        if ($existing = $this->drafts->activeFor($article)) {
            throw new PushException("この記事には、作業中の編集案（#{$existing->id}）が既にあります。");
        }

        $attributes = [
            'blog_id'                     => $article->blog_id,
            'target_type'                 => $article instanceof Post ? PushResourceType::Post : PushResourceType::Page,
            'base_wordpress_modified_gmt' => $article->wordpress_modified_gmt,
            'title_raw'                   => $article->title_raw,
            'content_raw'                 => $article->content_raw,
            'excerpt_raw'                 => $article->excerpt_raw,
            // 記事に設定した説明を写す。未設定なら空（AIOSEOの自動の説明のまま）
            'meta_description'            => $article->meta_description_raw,
            // 日本語のスラッグは、読める形で編集案に写す（WordPressに送ると、WordPressが符号化する。D-29）
            'slug'                        => Slug::display($article->slug),
            'status'                      => $article->status,
            'wordpress_featured_media_id' => (int) $article->wordpress_featured_media_id,
            'revision_scope'              => $scope ?? RevisionScope::Minor,
        ];

        if ($article instanceof Post) {
            $article->loadMissing(['categories', 'tags']);
            $attributes['post_id'] = $article->id;
            $attributes['wordpress_category_ids'] = $article->categories->pluck('wordpress_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            $attributes['wordpress_tag_ids'] = $article->tags->pluck('wordpress_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        } else {
            $attributes['page_id'] = $article->id;
        }

        return $this->drafts->create($attributes + $this->aiOrigin($aiGenerationId), $aiGenerationId ? ChangeSource::Ai : ChangeSource::BlogosManual, $userId);
    }

    /**
     * 新規記事の編集案を作る。反映に成功するまで、記事は編集案としてだけ存在する（ARCHITECTURE 17-5）
     */
    public function createNew(Blog $blog, PushResourceType $type, ?int $userId, ?int $aiGenerationId = null): ArticleDraft
    {
        return $this->drafts->create([
            'blog_id'        => $blog->id,
            'target_type'    => $type,
            'status'         => 'draft',
            'revision_scope' => RevisionScope::Full,
        ] + $this->aiOrigin($aiGenerationId), $aiGenerationId ? ChangeSource::Ai : ChangeSource::BlogosManual, $userId);
    }

    /**
     * AIの出力を編集案に取り込む（変更元 ai。D-07-01 の段階1：編集案の作成）。
     * この時点では人の修正はないため、修正の記録を初期化する。
     *
     * @throws PushException
     */
    public function applyAiOutput(ArticleDraft $draft, array $values, AiGeneration $generation, ?int $userId): void
    {
        $this->ensureEditable($draft);

        $this->drafts->update($draft, array_intersect_key($values, array_flip(self::EDITABLE_COLUMNS)) + ['ai_generation_id' => $generation->id], ChangeSource::Ai, $userId);
        $this->drafts->setAiEditStats($draft, false, 0.0);
    }

    /**
     * @throws PushException
     */
    public function update(ArticleDraft $draft, array $values, ?int $userId): array
    {
        $this->ensureEditable($draft);

        $changed = $this->drafts->update(
            $draft,
            array_intersect_key($values, array_flip(self::EDITABLE_COLUMNS)),
            ChangeSource::BlogosManual,
            $userId
        );

        // AIの出力をもとにした編集案は、人が修正したことと、修正の量を記録する（D-07-07）
        if ($changed !== [] && $draft->ai_generation_id !== null) {
            $this->drafts->setAiEditStats($draft, true, $this->editRatio($draft));
        }

        return $changed;
    }

    /**
     * 修正の量：AIが出力した本文と、現在の本文の行の違いの割合（0：修正なし 〜 1：全て書き換え）。
     * 比べられない場合（出力を読み取れない・長すぎる）は NULL
     */
    protected function editRatio(ArticleDraft $draft): ?float
    {
        $output = $this->generations->findForBlog($draft->blog_id, (int) $draft->ai_generation_id)?->output;

        try {
            $aiContent = $output !== null ? $this->parser->article($output)['本文'] : null;
        } catch (AiException $e) {
            $aiContent = null;
        }

        if ($aiContent === null) {
            return null;
        }

        $diff = LineDiff::compute($aiContent, (string) $draft->content_raw);
        if ($diff === null || $diff === []) {
            return $diff === [] ? 0.0 : null;
        }

        $changed = count(array_filter($diff, fn ($row) => $row['type'] !== 'same'));

        return round($changed / count($diff), 4);
    }

    /**
     * @return array<string, mixed>
     */
    protected function aiOrigin(?int $aiGenerationId): array
    {
        return $aiGenerationId === null ? [] : ['origin' => DraftOrigin::Ai, 'ai_generation_id' => $aiGenerationId];
    }

    /**
     * 状態を変える（作業中 ⇔ 確認待ち、破棄）
     *
     * @throws PushException
     */
    public function changeState(ArticleDraft $draft, DraftState $state, ?int $userId): void
    {
        $allowed = match ($state) {
            DraftState::Review, DraftState::Editing, DraftState::Discarded => $draft->state->isActive(),
            default                                                          => false,
        };

        if (! $allowed) {
            throw new PushException('この状態には変更できません。');
        }
        if ($draft->isLocked()) {
            throw new PushException('結果が確定していない反映記録があるため、状態を変更できません。');
        }

        $this->drafts->changeState($draft, $state, ChangeSource::BlogosManual, $userId);
    }

    /**
     * @throws PushException
     */
    protected function ensureEditable(ArticleDraft $draft): void
    {
        if (! $draft->state->isActive()) {
            throw new PushException('反映済み・破棄した編集案は編集できません。');
        }
        if ($draft->isLocked()) {
            throw new PushException('結果が確定していない反映記録があるため、編集できません。');
        }
    }
}
