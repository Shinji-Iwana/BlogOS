<?php

namespace App\Http\Controllers\Articles;

use App\Enums\ChangeSource;
use App\Enums\SuggestionStatus;
use App\Http\Controllers\Concerns\UsesSelectedBlog;
use App\Http\Controllers\Controller;
use App\Repositories\ArticleManagementRepository;
use App\Repositories\ArticleManagementSuggestionRepository;
use App\Support\QualityProfiles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * AIが作った記事の管理情報の案を、人が確認して登録する（D-27-03）。
 *
 * 案の値は、登録の前に画面で直せる。チェックした案をまとめて登録・不採用にできる。
 */
class ManagementSuggestionController extends Controller
{
    use UsesSelectedBlog;

    public function __construct(
        protected ArticleManagementSuggestionRepository $suggestions,
        protected ArticleManagementRepository $managements,
    ) {
    }

    public function index()
    {
        $blog = $this->selectedBlog();

        $rows = $this->suggestions->pendingForBlog($blog->id)->map(function ($suggestion) {
            $article = $suggestion->article();

            return [
                'suggestion' => $suggestion,
                'article'    => $article,
                'management' => $article ? $this->managements->findFor($article) : null,
                'keywords'   => $article ? $this->managements->keywordsFor($article) : collect(),
            ];
        });

        return view('articles.management-suggestions.index', [
            'blog'  => $blog,
            'rows'  => $rows,
            'types' => QualityProfiles::articleTypes($blog->quality_profile),
        ]);
    }

    public function review(Request $request)
    {
        $blog = $this->selectedBlog();
        $definitions = QualityProfiles::articleTypes($blog->quality_profile);
        $typeRule = $definitions['types'] !== [] ? Rule::in(array_keys($definitions['types'])) : 'max:50';
        $subtypeRule = $definitions['subtypes'] !== [] ? Rule::in(array_keys($definitions['subtypes'])) : 'max:50';

        $validated = $request->validate([
            'action'                            => ['required', Rule::in(['accept', 'reject'])],
            'selected'                          => ['required', 'array', 'min:1'],
            'selected.*'                        => ['integer'],
            'items'                             => ['nullable', 'array'],
            'items.*.article_type'              => ['nullable', 'string', $typeRule],
            'items.*.article_subtype'           => ['nullable', 'string', $subtypeRule],
            'items.*.main_keyword'              => ['nullable', 'string', 'max:191'],
            'items.*.sub_keywords'              => ['nullable', 'string', 'max:5000'],
            'items.*.main_search_intent'        => ['nullable', 'string', 'max:2000'],
            'items.*.sub_search_intents'        => ['nullable', 'string', 'max:5000'],
        ], [
            'selected.required' => '登録・不採用にする案を、1つ以上チェックしてください。',
        ]);

        $selected = $this->suggestions->pendingByIds($blog->id, array_map('intval', $validated['selected']));
        $userId = $request->user()?->id;

        DB::transaction(function () use ($selected, $validated, $userId) {
            foreach ($selected as $suggestion) {
                if ($validated['action'] === 'reject') {
                    $this->suggestions->markReviewed($suggestion, SuggestionStatus::Rejected, $userId);

                    continue;
                }

                $article = $suggestion->article();
                if ($article === null) {
                    continue;
                }

                // 画面で直した値を登録する。作業状況・メモは変えない
                $values = $validated['items'][$suggestion->id] ?? [];
                $this->managements->save(
                    $article,
                    [
                        'article_type'       => $values['article_type'] ?? null,
                        'article_subtype'    => $values['article_subtype'] ?? null,
                        'main_search_intent' => $values['main_search_intent'] ?? null,
                        'sub_search_intents' => $this->lines($values['sub_search_intents'] ?? null) ?: null,
                    ],
                    $values['main_keyword'] ?? null,
                    $this->lines($values['sub_keywords'] ?? null),
                    ChangeSource::Ai,
                    $userId
                );
                $this->suggestions->markReviewed($suggestion, SuggestionStatus::Accepted, $userId);
            }
        });

        $label = $validated['action'] === 'accept' ? '登録しました' : '不採用にしました';

        return redirect()->route('management-suggestions.index')->with('status', "{$selected->count()}件の案を{$label}。");
    }

    /**
     * @return array<int, string>
     */
    protected function lines(?string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $text)), fn ($line) => $line !== ''));
    }
}
