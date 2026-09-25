<?php

namespace App\Services\Sync\Resources;

use App\Enums\SyncIssueType;
use App\Models\Page;
use App\Models\Post;
use App\Models\WordPressRecord;
use App\Repositories\ArticleDraftRepository;
use App\Repositories\SyncIssueRepository;
use App\Repositories\WordPressRecordRepository;
use App\Services\Articles\ContentExtractionService;
use App\Services\Sync\SyncContext;

/**
 * 記事（投稿・固定ページ）の同期に共通の処理。
 *
 * * 作業中の編集案がある記事で、WordPress側の版（modified_gmt）が編集案の基準の版と異なる場合は、
 *   DBを更新せず、競合として記録する（D-01-04、WORDPRESS_API 15-4）
 * * 詳細を取得した記事は、本文から内部リンク・本文中のメディアを抽出し直す（WORDPRESS_API 15-5）
 */
abstract class ArticleSyncer extends TwoStageSyncer
{
    public function __construct(
        WordPressRecordRepository $records,
        SyncIssueRepository $issues,
        protected ArticleDraftRepository $drafts,
        protected ContentExtractionService $extraction,
    ) {
        parent::__construct($records, $issues);
    }

    protected function hold(?WordPressRecord $existing, array $item, SyncContext $context): bool
    {
        /** @var Post|Page|null $existing */
        if ($existing === null) {
            return false;
        }

        $draft = $this->drafts->activeFor($existing);

        if ($draft === null || $this->sameModified($draft->base_wordpress_modified_gmt, $item['modified_gmt'] ?? null)) {
            return false;
        }

        $this->issues->record($context->blog->id, SyncIssueType::Conflict, $this->key(), (string) $existing->wordpress_id, [
            'sync_run_id'      => $context->runId(),
            'post_id'          => $existing instanceof Post ? $existing->id : null,
            'page_id'          => $existing instanceof Page ? $existing->id : null,
            'article_draft_id' => $draft->id,
            'message'          => '作業中の編集案がある記事が、WordPress側で変更されました。DBは更新していません。競合の解消の画面で対応を選んでください。',
        ]);

        return true;
    }

    protected function afterSave(WordPressRecord $record, array $item, SyncContext $context): array
    {
        /** @var Post|Page $record */
        $this->extraction->extract($record, $context->blog);

        return [];
    }
}
