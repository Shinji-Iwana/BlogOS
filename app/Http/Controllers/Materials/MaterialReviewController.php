<?php

namespace App\Http\Controllers\Materials;

use App\Enums\AiMode;
use App\Enums\SuggestionStatus;
use App\Http\Controllers\Concerns\ResolvesArticleTarget;
use App\Http\Controllers\Concerns\UsesSelectedBlog;
use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\Post;
use App\Repositories\MaterialRepository;
use App\Services\Ai\AiApiPolicy;
use Illuminate\Http\Request;

/**
 * 記事の教材の見直し（D-30）。
 *
 * 教材を「使わない」にした・新しい版を登録した・教材の情報や記事が更新された記事を一覧にし、
 * AIの見直しの結果を人が確認する。教材の差し替えは、記事の改修で行う（記事は自動では変えない）。
 */
class MaterialReviewController extends Controller
{
    use ResolvesArticleTarget;
    use UsesSelectedBlog;

    public function __construct(
        protected MaterialRepository $materials,
        protected AiApiPolicy $apiPolicy,
    ) {
    }

    public function index()
    {
        $blog = $this->selectedBlog();

        // 見直しが必要な記事（記事ごとに、教材と理由をまとめる）
        $articles = [];
        foreach ($this->materials->articleMaterialsForBlog($blog->id) as $record) {
            $reason = $record->reviewReason();
            $article = $record->article();
            if ($reason === null || $article === null) {
                continue;
            }
            $key = ($article instanceof Post ? 'posts:' : 'pages:') . $article->id;
            $articles[$key]['article'] ??= $article;
            $articles[$key]['items'][] = ['material' => $record->material, 'reason' => $reason];
        }

        $reviews = $this->materials->pendingReviews($blog->id);
        $materialNames = $this->materials->allForBlog($blog->id)->pluck('name', 'id');

        return view('materials.reviews.index', [
            'blog'          => $blog,
            'articles'      => $articles,
            'reviews'       => $reviews,
            'materialNames' => $materialNames,
            'pending'       => $this->materials->pendingReviewArticles($blog->id),
            'api'           => [
                'configured' => $this->apiPolicy->isConfigured(),
                'defaults'   => $this->apiPolicy->defaults(AiMode::MaterialReview),
                'models'     => $this->apiPolicy->models(),
            ],
            'method'        => config('blogos.ai.methods.material_review', 'manual'),
        ]);
    }

    /**
     * AIの見直しの結果を確認した（記事の教材を、見直したことにする）
     */
    public function confirm(Request $request, int $id)
    {
        $blog = $this->selectedBlog();
        $review = $this->materials->findPendingReview($blog->id, $id);
        abort_if($review === null, 404);

        $this->materials->markReviewConfirmed($review, SuggestionStatus::Accepted, $request->user()?->id);
        if (($article = $review->article()) !== null) {
            $this->materials->markArticleReviewed($article);
        }

        return back()->with('status', "「{$review->article()?->title_raw}」の見直しの結果を確認しました。差し替え・削除が必要な場合は、記事の改修で行ってください。");
    }

    public function reject(Request $request, int $id)
    {
        $blog = $this->selectedBlog();
        $review = $this->materials->findPendingReview($blog->id, $id);
        abort_if($review === null, 404);

        $this->materials->markReviewConfirmed($review, SuggestionStatus::Rejected, $request->user()?->id);

        return back()->with('status', "「{$review->article()?->title_raw}」の見直しの結果を不採用にしました（記事は見直しが必要なままです）。");
    }

    /**
     * AIを使わずに、人が見直した（このままでよい）ことにする
     */
    public function markReviewed(Request $request)
    {
        $blog = $this->selectedBlog();
        $validated = $request->validate(['target' => ['required', 'string']]);
        ['article' => $article] = $this->resolveTarget($blog->id, $validated['target']);
        abort_if($article === null, 404);

        $this->materials->markArticleReviewed($article);

        return back()->with('status', "「{$article->title_raw}」の教材を、見直したことにしました。");
    }

    public static function articleType(Post|Page $article): string
    {
        return $article instanceof Post ? 'posts' : 'pages';
    }
}
