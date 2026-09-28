<?php

namespace App\Repositories;

use App\Enums\ImageKind;
use App\Enums\ImageStatus;
use App\Models\Category;
use App\Models\CategoryEyecatch;
use App\Models\Image;
use App\Models\Media;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 画像（images）と、カテゴリごとのアイキャッチ（category_eyecatches）。D-32。
 */
class ImageRepository
{
    /**
     * @return Collection<int, Image>
     */
    public function listForBlog(int $blogId, ?ImageKind $kind = null, ?ImageStatus $status = null): Collection
    {
        return Image::with(['media:id,wordpress_id,source_url', 'variantOf:id,title'])
            ->where('blog_id', $blogId)
            ->when($kind, fn ($query) => $query->where('kind', $kind))
            ->when($status, fn ($query) => $query->where('status', $status))
            ->latest('id')
            ->limit(300)
            ->get();
    }

    public function findForBlog(int $blogId, int $id): ?Image
    {
        return Image::with(['media', 'variantOf', 'variants', 'generations' => fn ($query) => $query->latest('id')->limit(10)])
            ->where('blog_id', $blogId)
            ->find($id);
    }

    public function create(array $attributes): Image
    {
        return Image::create($attributes);
    }

    // ---- カテゴリごとのアイキャッチ ----

    /**
     * @return Collection<int, CategoryEyecatch> category_id をキーにする
     */
    public function eyecatches(int $blogId): Collection
    {
        return CategoryEyecatch::with('media:id,wordpress_id,title_raw,source_url,width,height')->where('blog_id', $blogId)->get()->keyBy('category_id');
    }

    public function setEyecatch(int $blogId, int $categoryId, ?int $mediaId, ?int $userId): void
    {
        if ($mediaId === null) {
            CategoryEyecatch::where('blog_id', $blogId)->where('category_id', $categoryId)->delete();

            return;
        }

        CategoryEyecatch::updateOrCreate(['category_id' => $categoryId], ['blog_id' => $blogId, 'media_id' => $mediaId, 'updated_by' => $userId]);
    }

    /**
     * カテゴリのアイキャッチ（設定がなければ、親のカテゴリをたどる）
     */
    public function eyecatchFor(Category $category): ?Media
    {
        $current = $category;
        for ($depth = 0; $depth < 10 && $current !== null; $depth++) {
            $setting = CategoryEyecatch::with('media')->where('category_id', $current->id)->first();
            if ($setting?->media !== null) {
                return $setting->media;
            }
            $current = $current->parent_id ? Category::find($current->parent_id) : null;
        }

        return null;
    }

    /**
     * 既存の記事で、カテゴリごとにいちばん多く使われているアイキャッチ（登録の目安）
     *
     * @return array<int, array{media_id: int, count: int}> category_id をキーにする
     */
    public function usedEyecatches(int $blogId): array
    {
        $rows = DB::table('post_categories')
            ->join('posts', 'posts.id', '=', 'post_categories.post_id')
            ->join('media', fn ($join) => $join->on('media.wordpress_id', '=', 'posts.wordpress_featured_media_id')->on('media.blog_id', '=', 'posts.blog_id'))
            ->where('posts.blog_id', $blogId)
            ->whereNull('posts.wordpress_deleted_at')
            ->where('posts.wordpress_featured_media_id', '>', 0)
            ->selectRaw('post_categories.category_id, media.id as media_id, COUNT(*) as used')
            ->groupBy('post_categories.category_id', 'media.id')
            ->orderByDesc('used')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[$row->category_id] ??= ['media_id' => (int) $row->media_id, 'count' => (int) $row->used];
        }

        return $result;
    }

    /**
     * 選べる WordPress の画像（アイキャッチ・既存の画像を使う場合）
     *
     * @return Collection<int, Media>
     */
    public function selectableMedia(int $blogId): Collection
    {
        return Media::where('blog_id', $blogId)->whereNull('wordpress_deleted_at')->where('mime_type', 'like', 'image/%')
            ->orderByDesc('wordpress_id')->limit(500)->get(['id', 'wordpress_id', 'title_raw', 'source_url', 'width', 'height', 'alt_text']);
    }
}
