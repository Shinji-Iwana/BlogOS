<?php

namespace App\Http\Controllers\Images;

use App\Http\Controllers\Concerns\UsesSelectedBlog;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Repositories\ImageRepository;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * カテゴリごとのアイキャッチ（D-32-03）。
 *
 * 技術（カテゴリ）ごとの共通の画像を使う。子のカテゴリに設定がなければ、親のカテゴリの設定を使う。
 * 新しい記事では、このアイキャッチを設定する（記事の作成への組み込みは、HTMLのルールと一緒に行う）。
 */
class EyecatchController extends Controller
{
    use UsesSelectedBlog;

    public function __construct(
        protected ImageRepository $images,
    ) {
    }

    public function index()
    {
        $blog = $this->selectedBlog();
        $categories = Category::where('blog_id', $blog->id)->whereNull('wordpress_deleted_at')->withCount('posts')->orderBy('name')->get(['id', 'name', 'parent_id']);

        // 親のカテゴリの後に、子のカテゴリを並べる
        $ordered = collect();
        $add = function ($parentId, int $depth) use (&$add, $categories, $ordered) {
            foreach ($categories->where('parent_id', $parentId) as $category) {
                $category->depth = $depth;
                $ordered->push($category);
                $add($category->id, $depth + 1);
            }
        };
        $add(null, 0);

        $media = $this->images->selectableMedia($blog->id);

        return view('images.eyecatches', [
            'blog'       => $blog,
            'categories' => $ordered->concat($categories->whereNotIn('id', $ordered->pluck('id'))),
            'settings'   => $this->images->eyecatches($blog->id),
            'used'       => $this->images->usedEyecatches($blog->id),
            'media'      => $media->keyBy('id'),
        ]);
    }

    public function update(Request $request)
    {
        $blog = $this->selectedBlog();
        $validated = $request->validate([
            'eyecatch'   => ['nullable', 'array'],
            'eyecatch.*' => ['nullable', 'integer', Rule::exists('media', 'id')->where('blog_id', $blog->id)],
        ]);

        $categoryIds = Category::where('blog_id', $blog->id)->pluck('id')->all();
        foreach ((array) ($validated['eyecatch'] ?? []) as $categoryId => $mediaId) {
            if (in_array((int) $categoryId, $categoryIds, true)) {
                $this->images->setEyecatch($blog->id, (int) $categoryId, filled($mediaId) ? (int) $mediaId : null, $request->user()?->id);
            }
        }

        return redirect()->route('images.eyecatches')->with('status', 'カテゴリごとのアイキャッチを保存しました。');
    }
}
