<?php

namespace App\Http\Controllers\Topics;

use App\Enums\AiExecutionMethod;
use App\Enums\AiMode;
use App\Enums\SuggestionStatus;
use App\Http\Controllers\Concerns\UsesSelectedBlog;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\CategoryLaunch;
use App\Models\CategoryLaunchChild;
use App\Models\TopicSuggestion;
use App\Services\Ai\AiApiPolicy;
use App\Services\Ai\AiException;
use App\Services\Push\PushException;
use App\Services\Push\TermPushService;
use App\Services\Topics\CategoryLaunchPublishService;
use App\Services\Topics\CategoryLaunchService;
use App\Support\QualityProfiles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * カテゴリの立ち上げ（D-41）。
 */
class CategoryLaunchController extends Controller
{
    use UsesSelectedBlog;

    public function __construct(
        protected CategoryLaunchService $service,
        protected AiApiPolicy $apiPolicy,
    ) {
    }

    public function index(Request $request)
    {
        $blog = $this->selectedBlog();
        $parents = Category::where('blog_id', $blog->id)->existing()->whereNull('parent_id')->orderBy('name')->get(['id', 'name', 'slug']);
        $parent = $parents->firstWhere('id', (int) $request->query('parent_id'));

        return view('launches.index', [
            'blog'     => $blog,
            'launches' => CategoryLaunch::with(['parentCategory:id,name', 'children'])->where('blog_id', $blog->id)->latest('id')->get(),
            'parents'  => $parents,
            'parent'   => $parent,
            'options'  => $parent ? $this->childOptions($blog->id, $parent) : null,
        ]);
    }

    public function store(Request $request)
    {
        $blog = $this->selectedBlog();
        $validated = $request->validate([
            'parent_id'      => ['required', 'integer'],
            'categories'     => ['nullable', 'array'],
            'categories.*'   => ['integer'],
            'suggestions'    => ['nullable', 'array'],
            'suggestions.*'  => ['integer'],
        ]);
        $parent = Category::where('blog_id', $blog->id)->existing()->whereNull('parent_id')->find((int) $validated['parent_id']);
        abort_if($parent === null, 404);
        if (empty($validated['categories']) && empty($validated['suggestions'])) {
            return back()->withErrors(['launch' => '立ち上げる子カテゴリを選んでください。'])->withInput();
        }

        $launch = $this->service->create($blog, $parent, array_map('intval', $validated['categories'] ?? []), array_map('intval', $validated['suggestions'] ?? []), $request->user()?->id);

        return redirect()->route('launches.show', ['id' => $launch->id])->with('status', "「{$parent->name}」の立ち上げを始めました。");
    }

    /**
     * 新しい親カテゴリ（新しい技術）を WordPress に作る。記事がないカテゴリは、WordPress の画面には出ない
     */
    public function storeParent(Request $request, TermPushService $terms)
    {
        $blog = $this->selectedBlog();
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/'],
        ]);

        try {
            $category = $terms->createCategory($blog, $validated['name'], $validated['slug'], null, $request->user()?->id);
        } catch (PushException $e) {
            return back()->withErrors(['parent' => $e->getMessage()])->withInput();
        }

        return redirect()->route('launches.index', ['parent_id' => $category->id])->with('status', "親カテゴリ「{$category->name}」を WordPress に作りました。");
    }

    public function show(int $id, CategoryLaunchPublishService $publisher)
    {
        $blog = $this->selectedBlog();
        $launch = CategoryLaunch::with(['parentCategory', 'children.roadmapDraft:id,title_raw,state,page_id,content_raw', 'children.category:id,name',
            'children.drafts' => fn ($q) => $q->with(['post:id,status', 'page:id,status'])->orderBy('id')])->where('blog_id', $blog->id)->find($id);
        abort_if($launch === null, 404);

        return view('launches.show', [
            'blog'       => $blog,
            'launch'     => $launch,
            'progress'   => $launch->children->mapWithKeys(fn (CategoryLaunchChild $child) => [$child->id => $this->service->progress($child)]),
            'articles'   => TopicSuggestion::with('articleDraft:id,title_raw,state')->whereIn('launch_child_id', $launch->children->pluck('id'))->where('type', 'article')
                ->whereIn('status', [SuggestionStatus::Pending->value, SuggestionStatus::Accepted->value])->orderBy('id')->get()->groupBy('launch_child_id'),
            'options'    => $this->childOptions($blog->id, $launch->parentCategory, $launch),
            'types'      => QualityProfiles::articleTypes($blog->quality_profile),
            'api'        => [
                'configured' => $this->apiPolicy->isConfigured(),
                'models'     => $this->apiPolicy->models(),
                'defaults'   => $this->apiPolicy->defaults(AiMode::NewArticle),
                'webSearch'  => $this->apiPolicy->webSearch(),
            ],
            'perChild'   => CategoryLaunchService::ARTICLES_PER_CHILD,
            'parentPage'  => $publisher->parentRoadmapPage($launch),
            'parentDraft' => $publisher->parentRoadmapDraft($launch),
        ]);
    }

    public function addChildren(Request $request, int $id)
    {
        $launch = $this->findLaunch($id);
        $validated = $request->validate(['categories' => ['nullable', 'array'], 'categories.*' => ['integer'], 'suggestions' => ['nullable', 'array'], 'suggestions.*' => ['integer']]);
        $added = $this->service->addChildren($launch, array_map('intval', $validated['categories'] ?? []), array_map('intval', $validated['suggestions'] ?? []));

        return back()->with('status', "子カテゴリを {$added}件加えました。");
    }

    public function updateStatus(Request $request, int $id)
    {
        $launch = $this->findLaunch($id);
        $validated = $request->validate(['status' => ['required', Rule::in(array_keys(CategoryLaunch::STATUSES))]]);
        $launch->update(['status' => $validated['status']]);

        return back()->with('status', '状態を「' . CategoryLaunch::STATUSES[$validated['status']] . '」にしました。');
    }

    /**
     * ③ 記事の企画
     */
    public function plan(Request $request, int $id)
    {
        $child = $this->findChild($id);
        $validated = $request->validate([
            'execution_method' => ['required', Rule::enum(AiExecutionMethod::class)],
            'model'            => ['nullable', 'string', 'max:100'],
            'reasoning_effort' => ['nullable', 'string', 'max:30'],
            'web_search'       => ['nullable', 'boolean'],
        ]);

        try {
            $generation = $this->service->planArticles($child, AiExecutionMethod::from($validated['execution_method']), $validated['model'] ?? null,
                $validated['reasoning_effort'] ?? null, (bool) ($validated['web_search'] ?? false), $request->user()?->id);
        } catch (AiException $e) {
            return back()->withErrors(['ai' => $e->getMessage()]);
        }

        return redirect()->route('ai.generations.show', ['id' => $generation->id]);
    }

    /**
     * ④ 記事の編集案をまとめて作る
     */
    public function articles(Request $request, int $id)
    {
        $child = $this->findChild($id);
        $validated = $request->validate(['model' => ['nullable', 'string', 'max:100'], 'reasoning_effort' => ['nullable', 'string', 'max:30']]);
        $result = $this->service->generateArticles($child, $validated['model'] ?? null, $validated['reasoning_effort'] ?? null, $request->user()?->id);

        $redirect = back()->with('status', "{$result['started']}件の記事の編集案を作り始めました（Queue で動きます。しばらくしてから、この画面を開き直してください）。");

        return $result['errors'] === [] ? $redirect : $redirect->withErrors(['ai' => implode("\n", $result['errors'])]);
    }

    /**
     * ⑤ 子ロードマップの編集案を作る
     */
    public function roadmap(Request $request, int $id)
    {
        $child = $this->findChild($id);
        $validated = $request->validate(['model' => ['nullable', 'string', 'max:100'], 'reasoning_effort' => ['nullable', 'string', 'max:30']]);

        try {
            $generation = $this->service->generateRoadmap($child, $validated['model'] ?? null, $validated['reasoning_effort'] ?? null, $request->user()?->id);
        } catch (AiException $e) {
            return back()->withErrors(['ai' => $e->getMessage()]);
        }

        return redirect()->route('ai.generations.show', ['id' => $generation->id]);
    }

    /**
     * ⑥・⑦ 選んだ記事と子ロードマップを公開する（カテゴリ・親ロードマップのページがなければ作る。人の承認）
     */
    public function publish(Request $request, int $id, CategoryLaunchPublishService $publisher)
    {
        $child = $this->findChild($id);
        $validated = $request->validate(['drafts' => ['nullable', 'array'], 'drafts.*' => ['integer'], 'roadmap' => ['nullable', 'boolean']]);
        if (empty($validated['drafts']) && ! ($validated['roadmap'] ?? false)) {
            return back()->withErrors(['publish' => '公開する記事か子ロードマップを選んでください。']);
        }

        try {
            $result = $publisher->publish($child, array_map('intval', $validated['drafts'] ?? []), (bool) ($validated['roadmap'] ?? false), $request->user()?->id);
        } catch (PushException $e) {
            return back()->withErrors(['publish' => $e->getMessage()]);
        }

        $redirect = back()->with('status', "{$result['published']}件を公開しました。公開した記事どうしのリンクは、「リンクの切り替え」の画面でまとめて反映できます。");

        return $result['errors'] === [] ? $redirect : $redirect->withErrors(['publish' => implode("\n", $result['errors'])]);
    }

    /**
     * ⑧ 親ロードマップの編集案を作る（親ロードマップのページの記事改修。ページがなければ WordPress の下書きとして作る）
     */
    public function parentRoadmap(Request $request, int $id)
    {
        $launch = $this->findLaunch($id);
        $validated = $request->validate(['model' => ['nullable', 'string', 'max:100'], 'reasoning_effort' => ['nullable', 'string', 'max:30']]);

        try {
            $generation = $this->service->generateParentRoadmap($launch, $validated['model'] ?? null, $validated['reasoning_effort'] ?? null, $request->user()?->id);
        } catch (AiException|PushException $e) {
            return back()->withErrors(['ai' => $e->getMessage()]);
        }

        return redirect()->route('ai.generations.show', ['id' => $generation->id]);
    }

    /**
     * ⑧ 親ロードマップを公開する（人の承認）
     */
    public function publishParentRoadmap(int $id, CategoryLaunchPublishService $publisher)
    {
        $launch = $this->findLaunch($id);

        try {
            $result = $publisher->publishParentRoadmap($launch, request()->user()?->id);
        } catch (PushException $e) {
            return back()->withErrors(['publish' => $e->getMessage()]);
        }

        return $result['errors'] === []
            ? back()->with('status', '親ロードマップを公開しました。' . ($launch->fresh()->status === 'completed' ? 'すべて公開したため、立ち上げを「完了」にしました。' : ''))
            : back()->withErrors(['publish' => implode("\n", $result['errors'])]);
    }

    /**
     * 立ち上げに加えられる子カテゴリ：既存の子カテゴリ（記事の数）と、採用した子カテゴリの案
     *
     * @return array{categories: \Illuminate\Support\Collection, counts: \Illuminate\Support\Collection, suggestions: \Illuminate\Support\Collection}
     */
    protected function childOptions(int $blogId, Category $parent, ?CategoryLaunch $launch = null): array
    {
        $used = CategoryLaunchChild::whereHas('launch', fn ($q) => $q->where('blog_id', $blogId)->where('status', '!=', 'cancelled'))->get(['category_id', 'topic_suggestion_id']);
        $categories = Category::where('blog_id', $blogId)->existing()->where('parent_id', $parent->id)->orderBy('name')->get(['id', 'name', 'slug'])
            ->reject(fn ($category) => $used->contains('category_id', $category->id));

        return [
            'categories'  => $categories,
            'counts'      => DB::table('post_categories')->join('posts', 'posts.id', '=', 'post_categories.post_id')->where('posts.status', 'publish')
                ->whereNull('posts.wordpress_deleted_at')->whereIn('post_categories.category_id', $categories->pluck('id'))
                ->groupBy('post_categories.category_id')->selectRaw('post_categories.category_id, count(*) as c')->pluck('c', 'category_id'),
            'suggestions' => TopicSuggestion::where('blog_id', $blogId)->where('type', 'category')->where('category_id', $parent->id)
                ->where('status', SuggestionStatus::Accepted)->orderBy('id')->get()->reject(fn ($suggestion) => $used->contains('topic_suggestion_id', $suggestion->id)),
        ];
    }

    protected function findLaunch(int $id): CategoryLaunch
    {
        $launch = CategoryLaunch::where('blog_id', $this->selectedBlog()->id)->find($id);
        abort_if($launch === null, 404);

        return $launch;
    }

    protected function findChild(int $id): CategoryLaunchChild
    {
        $child = CategoryLaunchChild::whereHas('launch', fn ($q) => $q->where('blog_id', $this->selectedBlog()->id))->find($id);
        abort_if($child === null, 404);

        return $child;
    }
}
