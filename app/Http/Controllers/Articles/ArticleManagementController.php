<?php

namespace App\Http\Controllers\Articles;

use App\Enums\ChangeSource;
use App\Enums\RelationType;
use App\Enums\WorkStatus;
use App\Http\Controllers\Concerns\UsesSelectedBlog;
use App\Http\Controllers\Controller;
use App\Repositories\ArticleManagementRepository;
use App\Repositories\ArticleRepository;
use App\Support\QualityProfiles;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 記事の管理情報・キーワード・記事同士の関係の登録（BLOGOS_DATABASE.md 9-2〜9-4、D-08-02〜D-08-04）。
 */
class ArticleManagementController extends Controller
{
    use UsesSelectedBlog;

    public function __construct(
        protected ArticleRepository $articles,
        protected ArticleManagementRepository $managements,
    ) {
    }

    public function update(Request $request, string $type, int $id)
    {
        $blog = $this->selectedBlog();
        $article = $this->articles->find($blog->id, $type, $id);
        abort_if($article === null, 404);

        // 記事種類・細分類は、ブログ別の定義がある場合はその値だけを受け付ける（D-08-02）
        $definitions = QualityProfiles::articleTypes($blog->quality_profile);
        $typeRule = $definitions['types'] !== [] ? Rule::in(array_keys($definitions['types'])) : 'max:50';
        $subtypeRule = $definitions['subtypes'] !== [] ? Rule::in(array_keys($definitions['subtypes'])) : 'max:50';

        $validated = $request->validate([
            'article_type'       => ['nullable', 'string', $typeRule],
            'article_subtype'    => ['nullable', 'string', $subtypeRule],
            'main_search_intent' => ['nullable', 'string', 'max:2000'],
            'sub_search_intents' => ['nullable', 'string', 'max:5000'],
            'work_status'        => ['required', Rule::enum(WorkStatus::class)],
            'memo'               => ['nullable', 'string', 'max:10000'],
            'main_keyword'       => ['nullable', 'string', 'max:191'],
            'sub_keywords'       => ['nullable', 'string', 'max:5000'],
        ]);

        $this->managements->save(
            $article,
            [
                'article_type'       => $validated['article_type'] ?? null,
                'article_subtype'    => $validated['article_subtype'] ?? null,
                'main_search_intent' => $validated['main_search_intent'] ?? null,
                'sub_search_intents' => $this->lines($validated['sub_search_intents'] ?? null) ?: null,
                'work_status'        => WorkStatus::from($validated['work_status']),
                'memo'               => $validated['memo'] ?? null,
            ],
            $validated['main_keyword'] ?? null,
            $this->lines($validated['sub_keywords'] ?? null),
            ChangeSource::BlogosManual,
            $request->user()?->id
        );

        return redirect()->route('articles.show', ['type' => $type, 'id' => $id])->with('status', '管理情報を保存しました。');
    }

    public function storeRelation(Request $request, string $type, int $id)
    {
        $blog = $this->selectedBlog();
        $article = $this->articles->find($blog->id, $type, $id);
        abort_if($article === null, 404);

        $validated = $request->validate([
            'related_type'  => ['required', Rule::in(array_keys(ArticleRepository::TYPES))],
            'related_id'    => ['required', 'integer'],
            'relation_type' => ['required', Rule::enum(RelationType::class)],
            'sort_order'    => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        $related = $this->articles->find($blog->id, $validated['related_type'], (int) $validated['related_id']);
        if ($related === null || ($related::class === $article::class && $related->id === $article->id)) {
            return back()->withErrors(['related_id' => '関係先の記事が正しくありません。']);
        }

        $this->managements->addRelation($article, $related, RelationType::from($validated['relation_type']), (int) ($validated['sort_order'] ?? 0), $request->user()?->id);

        return redirect()->route('articles.show', ['type' => $type, 'id' => $id])->with('status', '関係を追加しました。');
    }

    public function destroyRelation(Request $request, string $type, int $id, int $relationId)
    {
        $blog = $this->selectedBlog();
        $article = $this->articles->find($blog->id, $type, $id);
        abort_if($article === null, 404);

        abort_unless($this->managements->removeRelation($article, $relationId, $request->user()?->id), 404);

        return redirect()->route('articles.show', ['type' => $type, 'id' => $id])->with('status', '関係を削除しました。');
    }

    /**
     * 1行に1つずつ入力された値の一覧
     *
     * @return array<int, string>
     */
    protected function lines(?string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $text)), fn ($line) => $line !== ''));
    }
}
