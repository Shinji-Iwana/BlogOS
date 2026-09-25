<?php

namespace App\Repositories;

use App\Enums\SyncIssueType;
use App\Models\SyncIssue;
use Illuminate\Database\Eloquent\Collection;

class SyncIssueRepository
{
    /**
     * 問題を記録する。
     *
     * 同じ対象・同じ種類の未解決の問題が既にある場合は、新しい行を作らず、
     * 既存の行の検出日時と内容を更新する（毎日の同期で同じ問題が増え続けることを防ぐ。D-15-08）。
     *
     * @param array<string, mixed> $attributes message・sync_run_id・error_status・error_body・post_id・page_id など
     */
    public function record(
        int $blogId,
        SyncIssueType $type,
        ?string $resourceType,
        ?string $resourceKey,
        array $attributes
    ): SyncIssue {
        $existing = SyncIssue::unresolved()
            ->where('blog_id', $blogId)
            ->where('issue_type', $type)
            ->where('resource_type', $resourceType)
            ->where('resource_key', $resourceKey)
            ->first();

        if ($existing !== null) {
            $existing->update(array_merge($attributes, ['last_detected_at' => now()]));

            return $existing;
        }

        return SyncIssue::create(array_merge($attributes, [
            'blog_id'           => $blogId,
            'issue_type'        => $type,
            'resource_type'     => $resourceType,
            'resource_key'      => $resourceKey,
            'first_detected_at' => now(),
            'last_detected_at'  => now(),
        ]));
    }

    public function unresolvedForBlog(int $blogId): Collection
    {
        return SyncIssue::unresolved()
            ->where('blog_id', $blogId)
            ->orderByDesc('last_detected_at')
            ->get();
    }

    public function resolvedForBlog(int $blogId, int $limit): Collection
    {
        return SyncIssue::with('resolvedBy')
            ->where('blog_id', $blogId)
            ->whereNotNull('resolved_at')
            ->orderByDesc('resolved_at')
            ->limit($limit)
            ->get();
    }

    /**
     * 反映記録・編集案に関する未解決の問題をまとめて解決済みにする（反映の問題を画面で解消したとき）
     *
     * @param array<int, SyncIssueType> $types
     */
    public function resolveRelated(int $blogId, array $types, ?int $pushOperationId, ?int $draftId, ?int $userId, string $resolution): int
    {
        // 対象を指定しない場合に、全ての問題を解決済みにしてしまわないため
        if ($pushOperationId === null && $draftId === null) {
            return 0;
        }

        $issues = SyncIssue::unresolved()
            ->where('blog_id', $blogId)
            ->whereIn('issue_type', $types)
            ->where(function ($query) use ($pushOperationId, $draftId) {
                $query->when($pushOperationId !== null, fn ($q) => $q->orWhere('wordpress_push_operation_id', $pushOperationId))
                    ->when($draftId !== null, fn ($q) => $q->orWhere('article_draft_id', $draftId));
            })
            ->get();

        foreach ($issues as $issue) {
            $this->resolve($issue, $userId, $resolution);
        }

        return $issues->count();
    }

    public function countUnresolvedForDraft(int $draftId, SyncIssueType $type): int
    {
        return SyncIssue::unresolved()->where('article_draft_id', $draftId)->where('issue_type', $type)->count();
    }

    public function countUnresolved(int $blogId): int
    {
        return SyncIssue::unresolved()->where('blog_id', $blogId)->count();
    }

    public function findForBlog(int $blogId, int $id): ?SyncIssue
    {
        return SyncIssue::where('blog_id', $blogId)->find($id);
    }

    public function resolve(SyncIssue $issue, ?int $userId, string $resolution): void
    {
        $issue->update([
            'resolved_at' => now(),
            'resolved_by' => $userId,
            'resolution'  => $resolution,
        ]);
    }
}
