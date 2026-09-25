<?php

namespace App\Repositories;

use App\Enums\PushOperationType;
use App\Enums\PushResourceType;
use App\Enums\PushState;
use App\Models\WordPressPushOperation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * 反映記録（wordpress_push_operations）。状態の進み方は ARCHITECTURE 13-5・13-6。
 */
class WordPressPushOperationRepository
{
    /**
     * @param array<string, mixed> $targets post_id / page_id / category_id / tag_id / media_id / article_draft_id
     */
    public function create(
        int $blogId,
        PushResourceType $resourceType,
        PushOperationType $operation,
        array $targets,
        array $requestSummary,
        ?array $baseValues,
        ?int $approvedBy
    ): WordPressPushOperation {
        return WordPressPushOperation::create(array_merge($targets, [
            'uuid'            => (string) Str::uuid(),
            'blog_id'         => $blogId,
            'resource_type'   => $resourceType,
            'operation'       => $operation,
            'state'           => PushState::Pending,
            'request_summary' => $requestSummary,
            'base_values'     => $baseValues,
            'approved_by'     => $approvedBy,
        ]));
    }

    public function markSent(WordPressPushOperation $operation): void
    {
        $operation->update(['state' => PushState::Sent, 'sent_at' => now()]);
    }

    /**
     * WordPressの応答を受信した直後に、最優先で保存する（WORDPRESS_API 23章 1）
     */
    public function markWpSucceeded(WordPressPushOperation $operation, ?int $wordpressId, ?string $modifiedGmt): void
    {
        $operation->update([
            'state'                 => PushState::WpSucceeded,
            'wordpress_id'          => $wordpressId,
            'response_modified_gmt' => $modifiedGmt ? str_replace('T', ' ', $modifiedGmt) : null,
            'wp_succeeded_at'       => now(),
        ]);
    }

    /**
     * @param array<string, mixed> $targets 新規作成で作られた記事など（post_id / page_id）
     */
    public function markCompleted(WordPressPushOperation $operation, array $targets = [], ?string $message = null): void
    {
        $operation->update(array_merge($targets, [
            'state'        => PushState::Completed,
            'completed_at' => now(),
            'message'      => $message ?? $operation->message,
        ]));
    }

    public function markFailed(WordPressPushOperation $operation, string $message, ?int $errorStatus = null, ?string $errorBody = null): void
    {
        $operation->update([
            'state'        => PushState::Failed,
            'failed_at'    => now(),
            'message'      => $message,
            'error_status' => $errorStatus,
            'error_body'   => $errorBody,
        ]);
    }

    public function markUnknown(WordPressPushOperation $operation, string $message, ?int $errorStatus = null, ?string $errorBody = null): void
    {
        $operation->update([
            'state'        => PushState::Unknown,
            'message'      => $message,
            'error_status' => $errorStatus,
            'error_body'   => $errorBody,
        ]);
    }

    public function findForBlog(int $blogId, int $id): ?WordPressPushOperation
    {
        return WordPressPushOperation::with(['draft', 'post', 'page', 'category', 'tag', 'media', 'approver'])->where('blog_id', $blogId)->find($id);
    }

    /**
     * @return Collection<int, WordPressPushOperation>
     */
    public function listForBlog(int $blogId, int $limit = 200): Collection
    {
        return WordPressPushOperation::with(['draft', 'post', 'page', 'category', 'tag', 'media', 'approver'])
            ->where('blog_id', $blogId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, WordPressPushOperation>
     */
    public function inState(int $blogId, PushState $state): Collection
    {
        return WordPressPushOperation::with('draft')->where('blog_id', $blogId)->where('state', $state)->orderBy('id')->get();
    }

    /**
     * 「送信中」のまま一定時間を過ぎたもの（処理が途中で止まった）を「結果が不明」にする
     */
    public function markStaleSentAsUnknown(int $blogId, int $staleSeconds): int
    {
        $stale = WordPressPushOperation::where('blog_id', $blogId)
            ->where('state', PushState::Sent)
            ->where('sent_at', '<', Carbon::now()->subSeconds($staleSeconds))
            ->get();

        foreach ($stale as $operation) {
            $this->markUnknown($operation, '送信した後、処理が途中で止まりました。WordPress側に反映されたかを確認してください。');
        }

        return $stale->count();
    }
}
