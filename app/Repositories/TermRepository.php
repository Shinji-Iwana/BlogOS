<?php

namespace App\Repositories;

use App\Models\Category;
use App\Models\Media;
use App\Models\Post;
use App\Models\Tag;
use Illuminate\Support\Collection;

/**
 * カテゴリ・タグ・メディアの、業務画面向けの読み取り。種類はURLの値（categories / tags / media）で指定する。
 */
class TermRepository
{
    public const TYPES = ['categories' => Category::class, 'tags' => Tag::class, 'media' => Media::class];

    public function find(int $blogId, string $type, int $id): Category|Tag|Media|null
    {
        $modelClass = self::TYPES[$type] ?? null;

        return $modelClass === null ? null : $modelClass::with('blog')->where('blog_id', $blogId)->find($id);
    }

    /**
     * 親カテゴリの選択肢（自分自身を除く）
     *
     * @return Collection<int, Category>
     */
    public function parentCandidates(Category $category): Collection
    {
        return Category::where('blog_id', $category->blog_id)
            ->existing()
            ->where('id', '!=', $category->id)
            ->orderBy('name')
            ->get(['id', 'wordpress_id', 'name']);
    }

    /**
     * この項目に関連している投稿の数（削除の影響を示すため）
     */
    public function linkedPostCount(Category|Tag|Media $record): int
    {
        return match (true) {
            $record instanceof Category => $record->posts()->count(),
            $record instanceof Tag      => $record->posts()->count(),
            default                     => Post::where('featured_media_id', $record->id)->count(),
        };
    }
}
