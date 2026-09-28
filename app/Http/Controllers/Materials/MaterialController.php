<?php

namespace App\Http\Controllers\Materials;

use App\Clients\Rakuten\RakutenBooksClient;
use App\Enums\MaterialKind;
use App\Enums\MaterialStatus;
use App\Http\Controllers\Concerns\UsesSelectedBlog;
use App\Http\Controllers\Controller;
use App\Models\Blog;
use App\Models\Category;
use App\Models\Material;
use App\Repositories\MaterialRepository;
use App\Services\Ai\AiApiPolicy;
use App\Services\Materials\AffiliateProgramService;
use App\Services\Materials\MaterialLinkService;
use App\Services\Materials\MaterialService;
use App\Support\AffiliateLink;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * 収益用の教材（書籍・Udemy・スクール）の一覧・登録・更新と、既存の記事にあるリンクからの登録（D-30）。
 */
class MaterialController extends Controller
{
    use UsesSelectedBlog;

    public function __construct(
        protected MaterialRepository $materials,
        protected MaterialService $service,
        protected MaterialLinkService $links,
        protected AiApiPolicy $apiPolicy,
        protected AffiliateProgramService $programs,
    ) {
    }

    public function index(Request $request)
    {
        $blog = $this->selectedBlog();
        $kind = MaterialKind::tryFrom((string) $request->query('kind'));
        $materials = $this->materials->listForBlog($blog->id)->filter(fn (Material $material) => $kind === null || $material->kind === $kind);

        // 見直しが必要な記事の数（教材ごと）
        $needsReview = [];
        foreach ($this->materials->articleMaterialsForBlog($blog->id) as $record) {
            if ($record->reviewReason() !== null) {
                $needsReview[$record->material_id] = ($needsReview[$record->material_id] ?? 0) + 1;
            }
        }

        return view('materials.index', [
            'blog'             => $blog,
            'kind'             => $kind,
            'materials'        => $materials,
            'needsReview'      => $needsReview,
            'programProblems'  => $materials->mapWithKeys(fn (Material $material) => [$material->id => $this->programs->problems($material)])->filter()->all(),
            'pendingSuggestions' => $this->materials->countPendingSuggestions($blog->id),
            'api'              => $this->apiSummary(),
            'categories'       => $this->categories($blog),
        ]);
    }

    public function create(Request $request)
    {
        $blog = $this->selectedBlog();

        return view('materials.form', [
            'blog'       => $blog,
            'material'   => new Material(['kind' => MaterialKind::tryFrom((string) $request->query('kind')) ?? MaterialKind::Book, 'status' => MaterialStatus::Active]),
            'categories' => $this->categories($blog),
            'previousOptions' => $this->materials->allForBlog($blog->id),
        ]);
    }

    public function store(Request $request)
    {
        $blog = $this->selectedBlog();
        [$attributes, $categoryIds] = $this->validated($request, $blog, null);

        $material = $this->service->create($blog, $attributes, $categoryIds, $request->user()?->id);

        return redirect()->route('materials.edit', ['id' => $material->id])->with('status', "教材「{$material->name}」を登録しました。");
    }

    public function edit(int $id)
    {
        $blog = $this->selectedBlog();
        $material = $this->materials->findForBlog($blog->id, $id);
        abort_if($material === null, 404);

        return view('materials.form', [
            'blog'       => $blog,
            'material'   => $material,
            'categories' => $this->categories($blog),
            'previousOptions' => $this->materials->allForBlog($blog->id)->where('id', '!=', $material->id),
            'articles'   => $this->materials->articlesUsing($material),
            'api'        => $this->apiSummary(),
            'rakuten'    => RakutenBooksClient::fromConfig()->isConfigured(),
        ]);
    }

    public function update(Request $request, int $id)
    {
        $blog = $this->selectedBlog();
        $material = $this->materials->findForBlog($blog->id, $id);
        abort_if($material === null, 404);

        [$attributes, $categoryIds] = $this->validated($request, $blog, $material);
        $this->service->update($material, $attributes, $categoryIds);

        return redirect()->route('materials.edit', ['id' => $material->id])->with('status', '教材を更新しました。');
    }

    /**
     * 教材を削除する。記事で使っている教材は削除せず、「使わない」にする
     */
    public function destroy(int $id)
    {
        $blog = $this->selectedBlog();
        $material = $this->materials->findForBlog($blog->id, $id);
        abort_if($material === null, 404);

        if ($material->articleMaterials()->exists()) {
            return back()->withErrors(['material' => '記事で使っている教材は削除できません。使わない場合は、状態を「使わない」にしてください。']);
        }

        $name = $material->name;
        $material->delete();

        return redirect()->route('materials.index')->with('status', "教材「{$name}」を削除しました。");
    }

    /**
     * 既存の記事にある教材のリンク（登録済みのものを含む）
     */
    public function detected()
    {
        $blog = $this->selectedBlog();

        return view('materials.detected', [
            'blog'     => $blog,
            'detected' => $this->links->detect($blog),
        ]);
    }

    /**
     * 検出したリンクから、チェックしたものを教材として登録する（名前・種類は画面で直せる）
     */
    public function registerDetected(Request $request)
    {
        $blog = $this->selectedBlog();

        $validated = $request->validate([
            'selected'         => ['required', 'array', 'min:1'],
            'selected.*'       => ['integer', 'min:0'],
            'items'            => ['required', 'array'],
            'items.*.name'     => ['nullable', 'string', 'max:255'],
            'items.*.kind'     => ['nullable', Rule::enum(MaterialKind::class)],
        ], [
            'selected.required' => '登録するリンクを、1つ以上チェックしてください。',
        ]);

        // 画面を表示した後に記事が変わっても、今の検出の結果で登録する（登録済みのものは除く）
        $detected = $this->links->detect($blog);
        $register = [];
        foreach ($validated['selected'] as $index) {
            $item = $detected[(int) $index] ?? null;
            if ($item === null || $item['material'] !== null) {
                continue;
            }
            $input = $validated['items'][$index] ?? [];
            $name = trim((string) ($input['name'] ?? '')) ?: $item['name'];
            if ($name === '') {
                continue;
            }
            $register[] = ['name' => $name, 'kind' => MaterialKind::tryFrom((string) ($input['kind'] ?? '')) ?? $item['kind']] + $item;
        }

        $count = $this->service->registerDetected($blog, $register, $request->user()?->id);

        return redirect()->route('materials.index')->with('status', "{$count}件の教材を登録し、記事との照合をやり直しました。次に、各教材の「AIで調べる」で、記事に合う教材を選ぶための情報を調べてください。");
    }

    /**
     * 記事の本文との照合をやり直す
     */
    public function relink()
    {
        $blog = $this->selectedBlog();
        $count = $this->service->relink($blog);

        return redirect()->route('materials.index')->with('status', "記事との照合をやり直しました（教材を使っている記事：{$count}件）。");
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<int, int>}
     */
    protected function validated(Request $request, Blog $blog, ?Material $material): array
    {
        $validated = $request->validate([
            'kind'            => ['required', Rule::enum(MaterialKind::class)],
            'status'          => ['required', Rule::enum(MaterialStatus::class)],
            'name'            => ['required', 'string', 'max:255'],
            'amazon_url'      => ['nullable', 'string', 'max:5000'],
            'rakuten_url'     => ['nullable', 'string', 'max:5000'],
            'affiliate_url'   => ['nullable', 'string', 'max:5000'],
            'extra_urls'      => ['nullable', 'string', 'max:20000'],
            'product_url'     => ['nullable', 'string', 'max:2000'],
            'amazon_product_url'  => ['nullable', 'string', 'max:2000'],
            'rakuten_product_url' => ['nullable', 'string', 'max:2000'],
            'isbn'          => ['nullable', 'string', 'max:20'],
            'creator'         => ['nullable', 'string', 'max:255'],
            'publisher'       => ['nullable', 'string', 'max:255'],
            'edition'         => ['nullable', 'string', 'max:50'],
            'published_on'    => ['nullable', 'date'],
            'category_ids'    => ['nullable', 'array'],
            'category_ids.*'  => ['integer', Rule::exists('categories', 'id')->where('blog_id', $blog->id)],
            'topics'          => ['nullable', 'string', 'max:5000'],
            'target_versions' => ['nullable', 'string', 'max:5000'],
            'levels'          => ['nullable', 'array'],
            'levels.*'        => [Rule::in(array_keys(Material::LEVELS))],
            'scenes'          => ['nullable', 'array'],
            'scenes.*'        => [Rule::in(array_keys(Material::SCENES))],
            'summary'         => ['nullable', 'string', 'max:5000'],
            'target_readers'  => ['nullable', 'string', 'max:2000'],
            'not_for'         => ['nullable', 'string', 'max:2000'],
            'merits'          => ['nullable', 'string', 'max:5000'],
            'cautions'        => ['nullable', 'string', 'max:5000'],
            'cost_note'       => ['nullable', 'string', 'max:255'],
            'duration_note'   => ['nullable', 'string', 'max:255'],
            'cost_checked_on' => ['nullable', 'date'],
            'previous_material_id' => ['nullable', 'integer', Rule::exists('materials', 'id')->where('blog_id', $blog->id)],
            'memo'            => ['nullable', 'string', 'max:5000'],
        ]);

        $kind = MaterialKind::from($validated['kind']);
        $links = $kind === MaterialKind::Book ? ['amazon_url', 'rakuten_url'] : ['affiliate_url'];
        foreach ($links as $column) {
            if (filled($validated[$column] ?? null) && ! preg_match('#^(https?:)?//#i', (string) AffiliateLink::extractUrl($validated[$column]))) {
                throw ValidationException::withMessages([$column => 'リンクは、URL（https://…）か、<a href="…"> を含むHTMLを入力してください。']);
            }
        }

        $lines = fn (?string $text) => array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $text)), fn ($line) => $line !== ''));

        $attributes = [
            'kind'            => $kind,
            'status'          => MaterialStatus::from($validated['status']),
            'name'            => $validated['name'],
            'amazon_url'      => $kind === MaterialKind::Book ? ($validated['amazon_url'] ?? null) : null,
            'rakuten_url'     => $kind === MaterialKind::Book ? ($validated['rakuten_url'] ?? null) : null,
            'affiliate_url'   => $kind !== MaterialKind::Book ? ($validated['affiliate_url'] ?? null) : null,
            'extra_urls'      => $lines($validated['extra_urls'] ?? null),
            'product_url'     => $validated['product_url'] ?? null,
            'amazon_product_url'  => $kind === MaterialKind::Book ? ($validated['amazon_product_url'] ?? null) : null,
            'rakuten_product_url' => $kind === MaterialKind::Book ? ($validated['rakuten_product_url'] ?? null) : null,
            'isbn'            => $validated['isbn'] ?? null,
            'creator'         => $validated['creator'] ?? null,
            'publisher'       => $validated['publisher'] ?? null,
            'edition'         => $validated['edition'] ?? null,
            'published_on'    => $validated['published_on'] ?? null,
            'topics'          => $lines($validated['topics'] ?? null),
            'target_versions' => $lines($validated['target_versions'] ?? null),
            'levels'          => $validated['levels'] ?? [],
            'scenes'          => $validated['scenes'] ?? [],
            'summary'         => $validated['summary'] ?? null,
            'target_readers'  => $validated['target_readers'] ?? null,
            'not_for'         => $validated['not_for'] ?? null,
            'merits'          => $lines($validated['merits'] ?? null),
            'cautions'        => $lines($validated['cautions'] ?? null),
            'cost_note'       => $validated['cost_note'] ?? null,
            'duration_note'   => $validated['duration_note'] ?? null,
            'cost_checked_on' => $validated['cost_checked_on'] ?? null,
            'previous_material_id' => $material !== null && (int) ($validated['previous_material_id'] ?? 0) === $material->id ? null : ($validated['previous_material_id'] ?? null),
            'memo'            => $validated['memo'] ?? null,
        ];

        // ASIN は、Amazon のリンクから読み直す
        if ($kind === MaterialKind::Book) {
            $attributes['asin'] = AffiliateLink::asin($attributes['amazon_url']);
        }

        return [$attributes, array_map('intval', $validated['category_ids'] ?? [])];
    }

    /**
     * @return Collection<int, Category> 親のカテゴリの後に子のカテゴリを並べる
     */
    protected function categories(Blog $blog): Collection
    {
        $all = Category::where('blog_id', $blog->id)->whereNull('wordpress_deleted_at')->orderBy('name')->get(['id', 'name', 'parent_id']);
        $ordered = collect();
        $add = function ($parentId, int $depth) use (&$add, $all, $ordered) {
            foreach ($all->where('parent_id', $parentId) as $category) {
                $category->depth = $depth;
                $ordered->push($category);
                $add($category->id, $depth + 1);
            }
        };
        $add(null, 0);

        // 親が見つからないカテゴリ（親が削除された場合など）も表示する
        return $ordered->concat($all->whereNotIn('id', $ordered->pluck('id')));
    }

    protected function apiSummary(): array
    {
        return [
            'configured' => $this->apiPolicy->isConfigured(),
            'models'     => $this->apiPolicy->models(),
            'spent'      => $this->apiPolicy->spentThisMonth(),
            'budget'     => $this->apiPolicy->monthlyBudget(),
            'webSearch'  => $this->apiPolicy->webSearch(),
        ];
    }
}
