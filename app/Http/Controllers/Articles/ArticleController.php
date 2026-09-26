<?php

namespace App\Http\Controllers\Articles;

use App\Enums\RelationType;
use App\Enums\WorkStatus;
use App\Http\Controllers\Analytics\AnalyticsController;
use App\Http\Controllers\Concerns\UsesSelectedBlog;
use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Repositories\AiGenerationRepository;
use App\Repositories\ArticleDraftRepository;
use App\Repositories\ArticleEvaluationRepository;
use App\Repositories\ArticleManagementRepository;
use App\Repositories\ArticleRepository;
use App\Repositories\GoogleMetricRepository;
use App\Support\QualityProfiles;
use Illuminate\Http\Request;

/**
 * 記事（投稿・固定ページ）の一覧と詳細（業務画面。ARCHITECTURE 17章）。
 *
 * 表示する内容はDBから読む。WordPress APIは呼ばない（D-01-10）。
 */
class ArticleController extends Controller
{
    use UsesSelectedBlog;

    public function __construct(
        protected ArticleRepository $articles,
        protected ArticleManagementRepository $managements,
        protected ArticleDraftRepository $drafts,
        protected GoogleMetricRepository $metrics,
        protected ArticleEvaluationRepository $evaluations,
        protected AiGenerationRepository $generations,
    ) {
    }

    public function index(Request $request, string $type)
    {
        abort_unless(isset(ArticleRepository::TYPES[$type]), 404);
        $blog = $this->selectedBlog();

        $filters = $request->only(['q', 'status', 'work_status']);

        return view('articles.index', [
            'blog'         => $blog,
            'type'         => $type,
            'filters'      => $filters,
            'articles'     => $this->articles->paginate($blog->id, $type, $filters),
            'workStatuses' => WorkStatus::cases(),
        ]);
    }

    public function show(Request $request, string $type, int $id)
    {
        $blog = $this->selectedBlog();
        $article = $this->articles->find($blog->id, $type, $id);
        abort_if($article === null, 404);

        // 関係の登録で選ぶ記事の候補（同じ画面で検索する）
        $relatedKeyword = trim((string) $request->query('related_q', ''));
        $relatedType = $request->query('related_type') === 'pages' ? 'pages' : 'posts';
        $candidates = $relatedKeyword !== '' ? $this->articles->search($blog->id, $relatedType, $relatedKeyword) : collect();

        if ($article instanceof Post) {
            $article->load(['categories', 'tags']);
        }

        $management = $this->managements->findFor($article);

        // Googleの指標（直近28日と、その前の28日）。保存済みのデータから読む
        $column = $article instanceof Post ? 'post_id' : 'page_id';
        [$from, $to] = AnalyticsController::period(28);
        [$previousFrom, $previousTo] = AnalyticsController::period(28, 28);

        return view('articles.show', [
            'blog'          => $blog,
            'type'          => $type,
            'article'       => $article,
            'isPost'        => $article instanceof Post,
            'management'    => $management,
            'histories'     => $management ? $this->managements->historiesFor($management) : collect(),
            'keywords'      => $this->managements->keywordsFor($article),
            'relations'     => $this->managements->relationsFor($article),
            'outbound'      => $this->articles->outboundLinks($article),
            'inbound'       => $this->articles->inboundLinks($article),
            'media'         => $this->articles->media($article),
            'operations'    => $this->articles->pushOperations($article),
            'activeDraft'   => $this->drafts->activeFor($article),
            'articleTypes'  => QualityProfiles::articleTypes($blog->quality_profile),
            'workStatuses'  => WorkStatus::cases(),
            'relationTypes' => RelationType::cases(),
            'evaluations'    => $this->evaluations->forArticle($article),
            'generations'    => $this->generations->forArticle($article),
            'google'         => $this->metrics->articleSummary($column, $article->id, $from, $to),
            'googlePrevious' => $this->metrics->articleSummary($column, $article->id, $previousFrom, $previousTo),
            'googlePeriod'   => [$from, $to],
            'relatedQuery'  => $relatedKeyword,
            'relatedType'   => $relatedType,
            'candidates'    => $candidates,
        ]);
    }

}
