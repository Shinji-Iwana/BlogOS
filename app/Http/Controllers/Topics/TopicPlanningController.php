<?php

namespace App\Http\Controllers\Topics;

use App\Enums\AiExecutionMethod;
use App\Enums\AiMode;
use App\Enums\SuggestionStatus;
use App\Http\Controllers\Concerns\UsesSelectedBlog;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\TopicSuggestion;
use App\Services\Ai\AiApiPolicy;
use App\Services\Ai\AiException;
use App\Services\Ai\AiRunService;
use App\Services\Topics\TopicPlanningPromptValues;
use App\Support\QualityProfiles;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * 記事の企画（D-40）：まだ記事にしていない内容・足りない子カテゴリの案を AI で出し、人が確認して採用する。
 * AIを使わない手がかり（記事の少ないカテゴリ・記事が合っていない検索語句）も並べる。
 */
class TopicPlanningController extends Controller
{
    use UsesSelectedBlog;

    public function __construct(
        protected AiRunService $runService,
        protected AiApiPolicy $apiPolicy,
    ) {
    }

    public function index(Request $request)
    {
        $blog = $this->selectedBlog();
        $categories = Category::where('blog_id', $blog->id)->existing()->orderBy('name')->get(['id', 'name', 'slug', 'parent_id']);
        $counts = DB::table('post_categories')->join('posts', 'posts.id', '=', 'post_categories.post_id')
            ->where('posts.status', 'publish')->whereNull('posts.wordpress_deleted_at')
            ->groupBy('post_categories.category_id')->selectRaw('post_categories.category_id, count(*) as c')->pluck('c', 'category_id');

        $pending = TopicSuggestion::with(['category:id,name', 'articles'])->where('blog_id', $blog->id)
            ->whereNull('parent_suggestion_id')->where('status', SuggestionStatus::Pending)->orderBy('type')->orderBy('category_id')
            ->orderByRaw("field(priority, 'high', 'medium', 'low')")->get();

        return view('topics.index', [
            'blog'       => $blog,
            'categories' => $categories,
            'counts'     => $counts,
            'pending'    => $pending,
            'accepted'   => TopicSuggestion::with('category:id,name')->where('blog_id', $blog->id)->where('type', 'article')
                ->where('status', SuggestionStatus::Accepted)->latest('reviewed_at')->limit(30)->get(),
            // 記事の少ないカテゴリ（子のカテゴリ、または子のないカテゴリで、記事が2件以下）
            'thin'       => $categories->filter(fn ($category) => ($category->parent_id !== null || ! $categories->contains('parent_id', $category->id)) && ($counts[$category->id] ?? 0) <= 2),
            'queries'    => $this->unmatchedQueries($blog->id),
            'units'      => TopicPlanningPromptValues::UNITS,
            'types'      => QualityProfiles::articleTypes($blog->quality_profile),
            'api'        => [
                'configured' => $this->apiPolicy->isConfigured(),
                'models'     => $this->apiPolicy->models(),
                'defaults'   => $this->apiPolicy->defaults(AiMode::TopicPlanning),
                'webSearch'  => $this->apiPolicy->webSearch(),
            ],
            'selected'   => (int) $request->query('category_id'),
        ]);
    }

    public function store(Request $request)
    {
        $blog = $this->selectedBlog();
        $validated = $request->validate([
            'category_id'      => ['required', 'integer'],
            'unit'             => ['required', Rule::in(array_keys(TopicPlanningPromptValues::UNITS))],
            'notes'            => ['nullable', 'string', 'max:5000'],
            'execution_method' => ['required', Rule::enum(AiExecutionMethod::class)],
            'model'            => ['nullable', 'string', 'max:100'],
            'reasoning_effort' => ['nullable', 'string', 'max:30'],
            'web_search'       => ['nullable', 'boolean'],
        ]);
        $category = Category::where('blog_id', $blog->id)->existing()->find((int) $validated['category_id']);
        abort_if($category === null, 404);

        try {
            $generation = $this->runService->start(AiMode::TopicPlanning, $blog, null, null, array_filter([
                '企画の単位'     => TopicPlanningPromptValues::UNITS[$validated['unit']],
                '企画の単位の値' => $validated['unit'],
                'カテゴリ'       => $category->name,
                'カテゴリの値'   => (string) $category->id,
                '補足'           => $validated['notes'] ?? null,
            ], fn ($value) => filled($value)), null, $request->user()?->id,
                AiExecutionMethod::from($validated['execution_method']), $validated['model'] ?? null, $validated['reasoning_effort'] ?? null,
                webSearch: (bool) ($validated['web_search'] ?? false));
        } catch (AiException $e) {
            return back()->withErrors(['ai' => $e->getMessage()])->withInput();
        }

        return redirect()->route('ai.generations.show', ['id' => $generation->id]);
    }

    public function review(Request $request, int $id)
    {
        $blog = $this->selectedBlog();
        $suggestion = TopicSuggestion::where('blog_id', $blog->id)->find($id);
        abort_if($suggestion === null, 404);
        $validated = $request->validate(['action' => ['required', Rule::in(['accept', 'reject'])]]);

        $status = $validated['action'] === 'accept' ? SuggestionStatus::Accepted : SuggestionStatus::Rejected;
        $suggestion->update(['status' => $status, 'reviewed_by' => $request->user()?->id, 'reviewed_at' => now()]);
        // 子カテゴリの案を見送ったら、その最初の記事の案も見送る
        if ($suggestion->type === 'category' && $status === SuggestionStatus::Rejected) {
            $suggestion->articles()->where('status', SuggestionStatus::Pending)->update(['status' => SuggestionStatus::Rejected->value, 'reviewed_by' => $request->user()?->id, 'reviewed_at' => now()]);
        }

        return back()->with('status', "「{$suggestion->title}」を" . ($status === SuggestionStatus::Accepted ? '採用' : '見送り') . 'にしました。');
    }

    /**
     * 記事が合っていない検索語句（表示されているが、平均掲載順位が20位より下の語句）
     */
    protected function unmatchedQueries(int $blogId)
    {
        $to = Carbon::parse(Carbon::now(config('blogos.display_timezone'))->subDays(2)->toDateString());

        return DB::table('google_search_console_query_daily as q')
            ->leftJoin('posts', 'posts.id', '=', 'q.post_id')
            ->where('q.blog_id', $blogId)->whereBetween('q.date', [$to->copy()->subDays(89)->toDateString(), $to->toDateString()])
            ->groupBy('q.query')
            ->selectRaw('q.query, sum(q.impressions) as impressions, sum(q.clicks) as clicks, sum(q.position * q.impressions) / nullif(sum(q.impressions), 0) as position, max(posts.title_raw) as title')
            ->havingRaw('sum(q.position * q.impressions) / nullif(sum(q.impressions), 0) > 20')
            ->orderByDesc('impressions')->limit(30)->get();
    }
}
