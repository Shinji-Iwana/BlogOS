<?php

namespace App\Repositories;

use App\Enums\ChangeSource;
use App\Enums\KeywordType;
use App\Enums\RelationType;
use App\Models\ArticleKeyword;
use App\Models\ArticleManagement;
use App\Models\ArticleRelation;
use App\Models\Histories\ArticleManagementHistory;
use App\Models\Page;
use App\Models\Post;
use BackedEnum;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 記事の管理情報（article_managements）・キーワード（article_keywords）・記事同士の関係（article_relations）と、
 * その履歴（article_management_histories）。BLOGOS_DATABASE.md 9-2〜9-4。
 *
 * キーワードと関係の変更は、管理情報の履歴に keywords / relations として記録する。
 */
class ArticleManagementRepository
{
    public function findFor(Post|Page $article): ?ArticleManagement
    {
        return ArticleManagement::where(ArticleContentRepository::articleColumn($article), $article->id)->first();
    }

    /**
     * @return Collection<int, ArticleKeyword>
     */
    public function keywordsFor(Post|Page $article): Collection
    {
        return ArticleKeyword::where(ArticleContentRepository::articleColumn($article), $article->id)
            ->orderByRaw("keyword_type = 'main' desc")
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, ArticleRelation>
     */
    public function relationsFor(Post|Page $article): Collection
    {
        return ArticleRelation::with(['relatedPost', 'relatedPage'])
            ->where(ArticleContentRepository::articleColumn($article), $article->id)
            ->orderBy('relation_type')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, ArticleManagementHistory>
     */
    public function historiesFor(ArticleManagement $management): Collection
    {
        return $management->histories()->orderByDesc('changed_at')->orderByDesc('id')->limit(300)->get();
    }

    /**
     * 管理情報とキーワードを保存し、変更した項目ごとに履歴を記録する。
     *
     * @param array<string, mixed> $values 管理情報の列の値
     * @param string|null $mainKeyword メインキーワード
     * @param array<int, string> $subKeywords サブキーワード
     */
    public function save(Post|Page $article, array $values, ?string $mainKeyword, array $subKeywords, ChangeSource $source, ?int $userId): ArticleManagement
    {
        return DB::transaction(function () use ($article, $values, $mainKeyword, $subKeywords, $source, $userId) {
            $column = ArticleContentRepository::articleColumn($article);
            $changeSetId = (string) Str::uuid();

            $management = $this->findFor($article);
            $isNew = $management === null;
            $management ??= new ArticleManagement(['blog_id' => $article->blog_id, $column => $article->id]);

            $management->fill(array_intersect_key($values, array_flip(ArticleManagement::TRACKED_COLUMNS)));

            $changes = [];
            if (! $isNew) {
                foreach (array_keys($management->getDirty()) as $field) {
                    $changes[$field] = [$this->stringify($management->getOriginal($field)), $this->stringify($management->getAttribute($field))];
                }
            }

            $management->save();

            if ($isNew) {
                $this->writeHistory($management, $changeSetId, '__created', null, null, $source, $userId);
            }

            // キーワード
            $before = $this->keywordSnapshot($article);
            ArticleKeyword::where($column, $article->id)->delete();

            $keywords = [];
            if (filled($mainKeyword)) {
                $keywords[trim($mainKeyword)] = KeywordType::Main;
            }
            foreach ($subKeywords as $keyword) {
                $keyword = trim($keyword);
                if ($keyword !== '' && ! isset($keywords[$keyword])) {
                    $keywords[$keyword] = KeywordType::Sub;
                }
            }
            foreach ($keywords as $keyword => $type) {
                ArticleKeyword::create([
                    'blog_id'      => $article->blog_id,
                    $column        => $article->id,
                    'keyword'      => mb_substr($keyword, 0, 191),
                    'keyword_type' => $type,
                ]);
            }

            $after = $this->keywordSnapshot($article);
            if ($before !== $after) {
                $changes['keywords'] = [$isNew && $before === '[]' ? null : $before, $after];
            }

            foreach ($changes as $field => [$old, $new]) {
                if ($old !== $new) {
                    $this->writeHistory($management, $changeSetId, $field, $old, $new, $source, $userId);
                }
            }

            return $management;
        });
    }

    /**
     * 記事同士の関係を追加する
     */
    public function addRelation(Post|Page $article, Post|Page $related, RelationType $type, int $sortOrder, ?int $userId): void
    {
        $this->changeRelations($article, $userId, function () use ($article, $related, $type, $sortOrder) {
            ArticleRelation::create([
                'blog_id'                                                  => $article->blog_id,
                ArticleContentRepository::articleColumn($article)          => $article->id,
                'related_' . ArticleContentRepository::articleColumn($related) => $related->id,
                'relation_type'                                            => $type,
                'sort_order'                                               => $sortOrder,
            ]);
        });
    }

    public function removeRelation(Post|Page $article, int $relationId, ?int $userId): bool
    {
        $relation = ArticleRelation::where(ArticleContentRepository::articleColumn($article), $article->id)->find($relationId);

        if ($relation === null) {
            return false;
        }

        $this->changeRelations($article, $userId, fn () => $relation->delete());

        return true;
    }

    /**
     * 関係を変更し、変更前後の一覧を履歴に記録する（管理情報の行がなければ作る）
     */
    protected function changeRelations(Post|Page $article, ?int $userId, callable $change): void
    {
        DB::transaction(function () use ($article, $userId, $change) {
            $management = $this->findFor($article);
            if ($management === null) {
                $management = ArticleManagement::create([
                    'blog_id'                                         => $article->blog_id,
                    ArticleContentRepository::articleColumn($article) => $article->id,
                ]);
                $this->writeHistory($management, (string) Str::uuid(), '__created', null, null, ChangeSource::BlogosManual, $userId);
            }

            $before = $this->relationSnapshot($article);
            $change();
            $after = $this->relationSnapshot($article);

            if ($before !== $after) {
                $this->writeHistory($management, (string) Str::uuid(), 'relations', $before, $after, ChangeSource::BlogosManual, $userId);
            }
        });
    }

    protected function keywordSnapshot(Post|Page $article): string
    {
        return json_encode(
            ArticleKeyword::where(ArticleContentRepository::articleColumn($article), $article->id)
                ->orderBy('id')
                ->get()
                ->map(fn (ArticleKeyword $k) => ['keyword' => $k->keyword, 'type' => $k->keyword_type->value])
                ->all(),
            JSON_UNESCAPED_UNICODE
        );
    }

    protected function relationSnapshot(Post|Page $article): string
    {
        return json_encode(
            ArticleRelation::where(ArticleContentRepository::articleColumn($article), $article->id)
                ->orderBy('relation_type')->orderBy('sort_order')->orderBy('id')
                ->get()
                ->map(fn (ArticleRelation $r) => array_filter([
                    'type'      => $r->relation_type->value,
                    'post_id'   => $r->related_post_id,
                    'page_id'   => $r->related_page_id,
                    'sort'      => $r->sort_order,
                ], fn ($v) => $v !== null))
                ->all(),
            JSON_UNESCAPED_UNICODE
        );
    }

    protected function writeHistory(ArticleManagement $management, string $changeSetId, string $field, ?string $old, ?string $new, ChangeSource $source, ?int $userId): void
    {
        ArticleManagementHistory::create([
            'blog_id'               => $management->blog_id,
            'article_management_id' => $management->id,
            'change_set_id'         => $changeSetId,
            'field'                 => $field,
            'old_value'             => $old,
            'new_value'             => $new,
            'source'                => $source,
            'user_id'               => $userId,
            'changed_at'            => now(),
        ]);
    }

    protected function stringify(mixed $value): ?string
    {
        return match (true) {
            $value === null              => null,
            is_array($value)             => json_encode($value, JSON_UNESCAPED_UNICODE),
            $value instanceof BackedEnum => (string) $value->value,
            default                      => (string) $value,
        };
    }
}
