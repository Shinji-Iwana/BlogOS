<?php

namespace App\Http\Controllers\Materials;

use App\Clients\Rakuten\RakutenBooksClient;
use App\Enums\AiExecutionMethod;
use App\Enums\AiMode;
use App\Enums\MaterialKind;
use App\Http\Controllers\Concerns\ResolvesArticleTarget;
use App\Http\Controllers\Concerns\UsesSelectedBlog;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Repositories\MaterialRepository;
use App\Services\Ai\AiApiPolicy;
use App\Services\Ai\AiApiUnavailableException;
use App\Services\Ai\AiException;
use App\Services\Ai\AiRunService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 教材のAI実行（D-30）：教材の情報の調査、カテゴリを指定した候補探し、記事の教材の見直し。
 *
 * 結果は案として保存し、人が確認して登録する（教材・記事は自動では変えない）。
 * 手動実行では、AI実行記録の画面で指示文をコピーし、回答を貼り付ける。
 */
class MaterialAiController extends Controller
{
    use ResolvesArticleTarget;
    use UsesSelectedBlog;

    public function __construct(
        protected AiRunService $runService,
        protected MaterialRepository $materials,
        protected AiApiPolicy $apiPolicy,
    ) {
    }

    /**
     * 1つの教材を調べる（販売サイト等のページの文章を貼り付けて渡せる）
     */
    public function research(Request $request, int $id)
    {
        $blog = $this->selectedBlog();
        $material = $this->materials->findForBlog($blog->id, $id);
        abort_if($material === null, 404);
        $validated = $request->validate($this->methodRules() + [
            'pasted' => ['nullable', 'string', 'max:30000'],
        ]);

        try {
            $generation = $this->runService->start(
                AiMode::MaterialResearch, $blog, null, null,
                array_filter(['貼り付けた情報（販売サイト等のページの文章）' => $validated['pasted'] ?? null], fn ($value) => filled($value)),
                null, $request->user()?->id,
                AiExecutionMethod::from($validated['execution_method']), $validated['model'] ?? null, $validated['reasoning_effort'] ?? null,
                material: $material, webSearch: (bool) ($validated['web_search'] ?? false),
            );
        } catch (AiException $e) {
            return back()->withErrors(['ai' => $e->getMessage()])->withInput();
        }

        return redirect()->route('ai.generations.show', ['id' => $generation->id]);
    }

    /**
     * チェックした教材をまとめて調べる（API実行だけ。教材ごとにJobで実行する）
     */
    public function researchMany(Request $request)
    {
        $blog = $this->selectedBlog();
        $validated = $request->validate([
            'selected'         => ['required', 'array', 'min:1', 'max:50'],
            'selected.*'       => ['integer'],
            'model'            => ['required', 'string', 'max:100'],
            'reasoning_effort' => ['required', 'string', 'max:30'],
            'web_search'       => ['nullable', 'boolean'],
        ], [
            'selected.required' => '調べる教材を、1つ以上チェックしてください。',
        ]);

        $started = 0;
        $errors = [];
        foreach ($validated['selected'] as $id) {
            $material = $this->materials->findForBlog($blog->id, (int) $id);
            if ($material === null) {
                continue;
            }
            try {
                $this->runService->start(AiMode::MaterialResearch, $blog, null, null, [], null, $request->user()?->id,
                    AiExecutionMethod::Api, $validated['model'], $validated['reasoning_effort'],
                    material: $material, webSearch: (bool) ($validated['web_search'] ?? false));
                $started++;
            } catch (AiApiUnavailableException $e) {
                // APIキーがない・費用の上限：残りも実行しない
                $errors[] = $e->getMessage();
                break;
            } catch (AiException $e) {
                $errors[] = "{$material->name}：{$e->getMessage()}";
            }
        }

        $redirect = redirect()->route('materials.index')->with('status', "{$started}件の教材の調査を始めました（API実行）。終わった結果は「教材の案の確認」で確認できます。");

        return $errors === [] ? $redirect : $redirect->withErrors(['ai' => implode("\n", $errors)]);
    }

    /**
     * カテゴリを指定して、新しい教材の候補を探す画面
     */
    public function discoverForm(Request $request)
    {
        $blog = $this->selectedBlog();

        return view('materials.discover', [
            'blog'       => $blog,
            'categories' => Category::where('blog_id', $blog->id)->whereNull('wordpress_deleted_at')->withCount('posts')->orderBy('name')->get(['id', 'name', 'parent_id']),
            'selected'   => (int) $request->query('category'),
            'api'        => $this->apiSummary(AiMode::MaterialDiscovery),
            'rakuten'    => RakutenBooksClient::fromConfig()->isConfigured(),
            'method'     => config('blogos.ai.methods.material_discovery', 'api'),
        ]);
    }

    public function discover(Request $request)
    {
        $blog = $this->selectedBlog();
        $validated = $request->validate($this->methodRules() + [
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where('blog_id', $blog->id)],
            'kind'        => ['nullable', Rule::enum(MaterialKind::class)],
            'count'       => ['nullable', 'integer', 'min:1', 'max:10'],
            'words'       => ['nullable', 'string', 'max:100'],
            'notes'       => ['nullable', 'string', 'max:20000'],
        ]);

        $category = Category::find($validated['category_id']);
        $kind = isset($validated['kind']) ? MaterialKind::from($validated['kind']) : null;

        try {
            $generation = $this->runService->start(
                AiMode::MaterialDiscovery, $blog, null, null,
                array_filter([
                    'カテゴリ'          => $category?->name,
                    'カテゴリの値'      => (string) $validated['category_id'],
                    '教材の種類'        => $kind?->label(),
                    '教材の種類の値'    => $kind?->value,
                    '探す数'            => (string) ($validated['count'] ?? 5),
                    '探す語句'          => $validated['words'] ?? null,
                    '補足（探したい教材の条件など）' => $validated['notes'] ?? null,
                ], fn ($value) => filled($value)),
                null, $request->user()?->id,
                AiExecutionMethod::from($validated['execution_method']), $validated['model'] ?? null, $validated['reasoning_effort'] ?? null,
                webSearch: (bool) ($validated['web_search'] ?? false),
            );
        } catch (AiException $e) {
            return back()->withErrors(['ai' => $e->getMessage()])->withInput();
        }

        return redirect()->route('ai.generations.show', ['id' => $generation->id]);
    }

    /**
     * 1つの記事の教材を見直す（AIが判断し、人が確認する）
     */
    public function review(Request $request)
    {
        $blog = $this->selectedBlog();
        $validated = $request->validate($this->methodRules() + [
            'target' => ['required', 'string'],
        ]);
        ['article' => $article] = $this->resolveTarget($blog->id, $validated['target']);
        abort_if($article === null, 404);

        try {
            $generation = $this->runService->start(
                AiMode::MaterialReview, $blog, $article, null, [], null, $request->user()?->id,
                AiExecutionMethod::from($validated['execution_method']), $validated['model'] ?? null, $validated['reasoning_effort'] ?? null,
            );
        } catch (AiException $e) {
            return back()->withErrors(['ai' => $e->getMessage()])->withInput();
        }

        return redirect()->route('ai.generations.show', ['id' => $generation->id]);
    }

    protected function methodRules(): array
    {
        return [
            'execution_method' => ['required', Rule::enum(AiExecutionMethod::class)],
            'model'            => ['nullable', 'string', 'max:100'],
            'reasoning_effort' => ['nullable', 'string', 'max:30'],
            'web_search'       => ['nullable', 'boolean'],
        ];
    }

    protected function apiSummary(AiMode $mode): array
    {
        return [
            'configured' => $this->apiPolicy->isConfigured(),
            'models'     => $this->apiPolicy->models(),
            'defaults'   => $this->apiPolicy->defaults($mode),
            'spent'      => $this->apiPolicy->spentThisMonth(),
            'budget'     => $this->apiPolicy->monthlyBudget(),
            'webSearch'  => $this->apiPolicy->webSearch(),
        ];
    }
}
