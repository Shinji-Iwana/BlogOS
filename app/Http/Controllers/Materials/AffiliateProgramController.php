<?php

namespace App\Http\Controllers\Materials;

use App\Enums\AffiliateLinkCheckResult;
use App\Enums\AffiliateProgramStatus;
use App\Enums\MaterialKind;
use App\Http\Controllers\Concerns\UsesSelectedBlog;
use App\Http\Controllers\Controller;
use App\Models\AffiliateProgram;
use App\Repositories\MaterialRepository;
use App\Services\Materials\AffiliateLinkChecker;
use App\Services\Materials\AffiliateProgramService;
use App\Services\Materials\MaterialLinkService;
use App\Support\AffiliateLink;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * アフィリエイトのプログラム（提携先の広告）の一覧・状態の更新（D-33-08）。
 */
class AffiliateProgramController extends Controller
{
    use UsesSelectedBlog;

    public function __construct(
        protected AffiliateProgramService $programs,
        protected MaterialRepository $materials,
        protected MaterialLinkService $links,
    ) {
    }

    public function index()
    {
        $blog = $this->selectedBlog();
        $programs = $this->programs->programs($blog->id);
        $materials = $this->materials->allForBlog($blog->id);

        return view('materials.programs.index', [
            'blog'      => $blog,
            'programs'  => $programs,
            'usage'     => $this->programs->usage($blog),
            'materials' => $programs->map(fn (AffiliateProgram $program) => $this->programs->materialsOf($program, $materials)),
        ]);
    }

    /**
     * プログラムを手で登録する（もしもの広告ID（p_id）、または識別子）
     */
    public function store(Request $request)
    {
        $blog = $this->selectedBlog();
        $validated = $request->validate([
            'asp'       => ['required', Rule::in(['moshimo', 'a8', 'other'])],
            'external_id' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9_.\-]+$/'],
            'name'      => ['required', 'string', 'max:255'],
        ] + $this->attributeRules(), [], ['external_id' => '広告ID', 'name' => '名前']);

        $key = "{$validated['asp']}:{$validated['external_id']}";
        if ($this->programs->programs($blog->id)->has($key)) {
            return back()->withInput()->withErrors(['external_id' => "このプログラム（{$key}）は登録済みです。"]);
        }

        AffiliateProgram::create([
            'blog_id'           => $blog->id,
            'program_key'       => $key,
            'asp'               => $validated['asp'],
            'name'              => $validated['name'],
            'material_kind'     => $validated['material_kind'] ?? null,
            'status'            => $validated['status'],
            'status_changed_on' => now(config('blogos.display_timezone'))->toDateString(),
            'memo'              => $validated['memo'] ?? null,
        ]);
        $this->programs->forget($blog->id);

        return redirect()->route('materials.programs.index')->with('status', "プログラム「{$validated['name']}」を登録しました。");
    }

    public function update(Request $request, int $id)
    {
        $blog = $this->selectedBlog();
        $program = AffiliateProgram::where('blog_id', $blog->id)->find($id);
        abort_if($program === null, 404);

        $validated = $request->validate(['name' => ['required', 'string', 'max:255'], 'align_kind' => ['nullable', 'boolean']] + $this->attributeRules());
        $status = AffiliateProgramStatus::from($validated['status']);

        $program->update([
            'name'              => $validated['name'],
            'material_kind'     => $validated['material_kind'] ?? null,
            'status'            => $status,
            'status_changed_on' => $status !== $program->status ? now(config('blogos.display_timezone'))->toDateString() : $program->status_changed_on,
            'memo'              => $validated['memo'] ?? null,
        ]);
        $this->programs->forget($blog->id);

        // このプログラムの教材の種類をそろえる（スクールとして登録した問題集を直す場合など）
        $aligned = 0;
        if (($validated['align_kind'] ?? false) && $program->material_kind !== null) {
            foreach ($this->programs->materialsOf($program, $this->materials->allForBlog($blog->id)) as $material) {
                if ($material->kind !== $program->material_kind) {
                    $material->update(['kind' => $program->material_kind]);
                    $aligned++;
                }
            }
        }

        return redirect()->route('materials.programs.index')
            ->with('status', "プログラム「{$program->name}」を更新しました（{$status->label()}）。" . ($aligned > 0 ? "教材 {$aligned}件の種類を「{$program->material_kind->label()}」にしました。" : ''));
    }

    /**
     * 記事のリンクにあるプログラムのうち、まだ登録していないものを「未確認」で登録する
     */
    public function registerFromLinks()
    {
        $blog = $this->selectedBlog();
        $created = $this->programs->registerFromLinks($blog);

        return redirect()->route('materials.programs.index')->with('status', $created > 0
            ? "記事のリンクから、プログラムを {$created}件登録しました。状態（提携中・否認など）を確認してください。"
            : '記事のリンクに、未登録のプログラムはありませんでした。');
    }

    /**
     * リンクを今すぐ確かめる（D-33-09。ふだんは週1回の定期確認）
     */
    public function checkLinks(AffiliateLinkChecker $checker)
    {
        $blog = $this->selectedBlog();
        $results = $checker->checkBlog($blog);
        $suspects = count(array_filter($results, fn ($result) => $result === AffiliateLinkCheckResult::Suspect));

        return redirect()->route('materials.programs.index')->with('status', count($results) . "件のプログラムのリンクを確かめました。"
            . ($suspects > 0 ? "提携終了の疑いが {$suspects}件あります。ASP の管理画面で確かめて、状態を直してください。" : '提携終了の疑いはありませんでした。'));
    }

    /**
     * @return array<string, list<mixed>>
     */
    protected function attributeRules(): array
    {
        return [
            'material_kind' => ['nullable', Rule::enum(MaterialKind::class)],
            'status'        => ['required', Rule::enum(AffiliateProgramStatus::class)],
            'memo'          => ['nullable', 'string', 'max:2000'],
        ];
    }
}
