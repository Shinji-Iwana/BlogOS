<?php

namespace App\Repositories;

use App\Enums\ChangeSource;
use App\Enums\DraftState;
use App\Models\ArticleDraft;
use App\Models\Histories\ArticleDraftHistory;
use App\Models\Page;
use App\Models\Post;
use BackedEnum;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 編集案（article_drafts）と、その履歴（article_draft_histories）。BLOGOS_DATABASE.md 9-1。
 */
class ArticleDraftRepository
{
    /**
     * 記事の作業中（editing / review）の編集案
     */
    public function activeFor(Post|Page $article): ?ArticleDraft
    {
        return ArticleDraft::active()
            ->where(ArticleContentRepository::articleColumn($article), $article->id)
            ->latest('id')
            ->first();
    }

    public function findForBlog(int $blogId, int $id): ?ArticleDraft
    {
        return ArticleDraft::with(['post', 'page'])->where('blog_id', $blogId)->find($id);
    }

    /**
     * @return Collection<int, ArticleDraft>
     */
    public function listForBlog(int $blogId, bool $includeClosed = false): Collection
    {
        return ArticleDraft::with(['post', 'page', 'creator'])
            ->where('blog_id', $blogId)
            ->when(! $includeClosed, fn ($query) => $query->active())
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(500)
            ->get();
    }

    /**
     * 編集案を作成し、__created の履歴を記録する
     */
    public function create(array $attributes, ChangeSource $source, ?int $userId): ArticleDraft
    {
        return DB::transaction(function () use ($attributes, $source, $userId) {
            $draft = ArticleDraft::create(array_merge($attributes, [
                'uuid'       => (string) Str::uuid(),
                'created_by' => $userId,
            ]));

            $this->writeHistory($draft, (string) Str::uuid(), '__created', null, null, $source, $userId);

            return $draft;
        });
    }

    /**
     * 編集案を更新し、変更した項目ごとに履歴を記録する（変更がなければ何もしない）。
     *
     * @return array<int, string> 変更した項目
     */
    public function update(ArticleDraft $draft, array $attributes, ChangeSource $source, ?int $userId, ?int $pushOperationId = null): array
    {
        return DB::transaction(function () use ($draft, $attributes, $source, $userId, $pushOperationId) {
            $draft->fill($attributes);

            $changes = [];
            foreach (array_keys($draft->getDirty()) as $column) {
                if (in_array($column, ['updated_at', 'created_at'], true)) {
                    continue;
                }
                $changes[$column] = [$this->stringify($draft->getOriginal($column)), $this->stringify($draft->getAttribute($column))];
            }

            $draft->save();

            $changeSetId = (string) Str::uuid();
            $changed = [];
            foreach ($changes as $field => [$old, $new]) {
                if ($old === $new) {
                    continue;
                }
                $this->writeHistory($draft, $changeSetId, $field, $old, $new, $source, $userId, $pushOperationId);
                $changed[] = $field;
            }

            return $changed;
        });
    }

    /**
     * AIの出力を人が修正したかと、修正の量（D-07-07）。内容の変更ではないため、履歴は記録しない
     */
    public function setAiEditStats(ArticleDraft $draft, bool $humanEdited, ?float $editRatio): void
    {
        $draft->forceFill(['human_edited' => $humanEdited, 'edit_ratio' => $editRatio])->saveQuietly();
    }

    public function changeState(ArticleDraft $draft, DraftState $state, ChangeSource $source, ?int $userId, ?int $pushOperationId = null): void
    {
        $attributes = ['state' => $state];

        if ($state === DraftState::Pushed) {
            $attributes['pushed_at'] = now();
        }
        if ($state === DraftState::Discarded) {
            $attributes['discarded_at'] = now();
        }

        $this->update($draft, $attributes, $source, $userId, $pushOperationId);
    }

    /**
     * @return Collection<int, ArticleDraftHistory>
     */
    public function histories(ArticleDraft $draft): Collection
    {
        return $draft->histories()->orderByDesc('changed_at')->orderByDesc('id')->limit(500)->get();
    }

    protected function writeHistory(
        ArticleDraft $draft,
        string $changeSetId,
        string $field,
        ?string $old,
        ?string $new,
        ChangeSource $source,
        ?int $userId,
        ?int $pushOperationId = null
    ): void {
        ArticleDraftHistory::create([
            'blog_id'                     => $draft->blog_id,
            'article_draft_id'            => $draft->id,
            'change_set_id'               => $changeSetId,
            'field'                       => $field,
            'old_value'                   => $old,
            'new_value'                   => $new,
            'source'                      => $source,
            'wordpress_push_operation_id' => $pushOperationId,
            'user_id'                     => $userId,
            'changed_at'                  => now(),
        ]);
    }

    protected function stringify(mixed $value): ?string
    {
        return match (true) {
            $value === null                        => null,
            is_bool($value)                        => $value ? 'true' : 'false',
            is_array($value)                       => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $value instanceof \DateTimeInterface   => $value->format('Y-m-d H:i:s'),
            $value instanceof BackedEnum           => (string) $value->value,
            default                                => (string) $value,
        };
    }
}
