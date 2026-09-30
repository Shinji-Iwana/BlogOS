<?php

namespace App\Http\Controllers\Articles;

use App\Enums\DraftState;
use App\Enums\PushResourceType;
use App\Enums\RevisionScope;
use App\Enums\SyncIssueType;
use App\Http\Controllers\Concerns\UsesSelectedBlog;
use App\Http\Controllers\Controller;
use App\Enums\KeywordType;
use App\Models\AiGeneration;
use App\Models\ArticleDraft;
use App\Models\Image;
use App\Models\Page;
use App\Models\Post;
use App\Repositories\ArticleDraftRepository;
use App\Repositories\ArticleManagementRepository;
use App\Repositories\ArticleEvaluationRepository;
use App\Repositories\ArticleRepository;
use App\Repositories\SyncIssueRepository;
use App\Services\Articles\ArticleHtmlFinisher;
use App\Services\Articles\ArticleImageRequestService;
use App\Services\Articles\ArticleTitleChecker;
use App\Services\Articles\DraftService;
use App\Support\ArticlePlaceholders;
use App\Services\Push\PushException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 編集案の一覧・作成・編集・状態の変更（BLOGOS_DATABASE.md 9-1、ARCHITECTURE 17-5）。
 */
class DraftController extends Controller
{
    use UsesSelectedBlog;

    /**
     * 編集案で指定できるWordPressのステータス（予約投稿は日時の指定が必要なため、この画面では扱わない）
     */
    public const STATUSES = ['draft' => '下書き', 'pending' => 'レビュー待ち', 'private' => '非公開', 'publish' => '公開'];

    public function __construct(
        protected ArticleDraftRepository $drafts,
        protected ArticleRepository $articles,
        protected DraftService $draftService,
        protected SyncIssueRepository $issues,
        protected ArticleEvaluationRepository $evaluations,
    ) {
    }

    public function index(Request $request)
    {
        $blog = $this->selectedBlog();
        $includeClosed = $request->boolean('all');

        return view('drafts.index', [
            'blog'          => $blog,
            'includeClosed' => $includeClosed,
            'drafts'        => $this->drafts->listForBlog($blog->id, $includeClosed),
        ]);
    }

    /**
     * 既存記事から、または新規記事の編集案を作る
     */
    public function store(Request $request)
    {
        $blog = $this->selectedBlog();

        $validated = $request->validate([
            'article_type'   => ['required', Rule::in(array_keys(ArticleRepository::TYPES))],
            'article_id'     => ['nullable', 'integer'],
            'revision_scope' => ['nullable', Rule::enum(RevisionScope::class)],
        ]);

        try {
            if (filled($validated['article_id'] ?? null)) {
                $article = $this->articles->find($blog->id, $validated['article_type'], (int) $validated['article_id']);
                abort_if($article === null, 404);

                $draft = $this->draftService->createFromArticle(
                    $article,
                    isset($validated['revision_scope']) ? RevisionScope::from($validated['revision_scope']) : null,
                    $request->user()?->id
                );
            } else {
                $draft = $this->draftService->createNew(
                    $blog,
                    $validated['article_type'] === 'posts' ? PushResourceType::Post : PushResourceType::Page,
                    $request->user()?->id
                );
            }
        } catch (PushException $e) {
            return back()->withErrors(['draft' => $e->getMessage()]);
        }

        return redirect()->route('drafts.edit', ['id' => $draft->id])->with('status', '編集案を作成しました。');
    }

    public function edit(int $id)
    {
        $blog = $this->selectedBlog();
        $draft = $this->drafts->findForBlog($blog->id, $id);
        abort_if($draft === null, 404);

        $article = $draft->article();

        return view('drafts.edit', [
            'blog'           => $blog,
            'draft'          => $draft,
            'article'        => $article,
            // 改修前後の点数を比べるため、記事の最新の評価も表示する（D-27-01）
            'articleEvaluation' => $article !== null ? $this->evaluations->latestFor($article, null) : null,
            'locked'         => $draft->isLocked(),
            'histories'      => $this->drafts->histories($draft),
            'conflicts'      => $this->issues->countUnresolvedForDraft($draft->id, SyncIssueType::Conflict),
            'evaluations'    => $this->evaluations->forDraft($draft),
            'categories'     => $draft->target_type === PushResourceType::Post ? $this->articles->terms($blog->id, 'categories') : collect(),
            'tags'           => $draft->target_type === PushResourceType::Post ? $this->articles->terms($blog->id, 'tags') : collect(),
            'statuses'       => self::STATUSES,
            'revisionScopes' => RevisionScope::cases(),
            // AIが依頼した画像と、本文に残っている目印（D-34）
            'draftImages'    => Image::with('media:id,wordpress_id')->where('article_draft_id', $draft->id)->orderBy('id')->get(),
            'placeholders'   => ArticlePlaceholders::remaining($draft->content_raw),
            // タイトル・メタディスクリプションの確認（D-36）
            'titleIssues'    => app(ArticleTitleChecker::class)->check($blog, $draft->title_raw, $draft->meta_description, $this->mainKeyword($draft, $article), $article),
        ]);
    }

    /**
     * メインキーワード：記事の管理情報、新規記事は AI 実行で人が入力したもの
     */
    protected function mainKeyword(ArticleDraft $draft, Post|Page|null $article): ?string
    {
        if ($article !== null) {
            return app(ArticleManagementRepository::class)->keywordsFor($article)->firstWhere('keyword_type', KeywordType::Main)?->keyword;
        }

        return $draft->ai_generation_id ? (AiGeneration::find($draft->ai_generation_id)?->parameters['メインキーワード'] ?? null) : null;
    }

    /**
     * 本文を仕上げ直す：目印を置き換え直し、広告・広告を含むことの表示を入れ直す（D-34）。画像を WordPress に登録した後などに使う
     */
    public function finish(Request $request, int $id, ArticleHtmlFinisher $finisher, ArticleImageRequestService $imageRequests)
    {
        $blog = $this->selectedBlog();
        $draft = $this->drafts->findForBlog($blog->id, $id);
        abort_if($draft === null, 404);

        // 付け替えられずに残った [[画像:新規N]] を、この編集案の画像と結び付け直してから仕上げる（D-34-06）
        $finished = $finisher->finish($blog, $imageRequests->repairMarkers($draft, (string) $draft->content_raw)['content']);

        try {
            $changed = $this->draftService->update($draft, ['content_raw' => $finished['content']], $request->user()?->id);
        } catch (PushException $e) {
            return back()->withErrors(['draft' => $e->getMessage()]);
        }
        $draft->forceFill(['finish_notes' => $finished['notes'] !== [] ? $finished['notes'] : null])->save();

        $remaining = ArticlePlaceholders::remaining($finished['content']);

        return redirect()->route('drafts.edit', ['id' => $draft->id])->with('status', ($changed === [] ? '本文は変わりませんでした。' : '目印を置き換え直しました。')
            . ($remaining !== [] ? '置き換えられなかった目印が ' . count($remaining) . '件あります。' : ''));
    }

    public function update(Request $request, int $id)
    {
        $blog = $this->selectedBlog();
        $draft = $this->drafts->findForBlog($blog->id, $id);
        abort_if($draft === null, 404);

        $validated = $request->validate([
            'title_raw'                   => ['nullable', 'string', 'max:1000'],
            'content_raw'                 => ['nullable', 'string'],
            'excerpt_raw'                 => ['nullable', 'string', 'max:10000'],
            'meta_description'            => ['nullable', 'string', 'max:1000'],
            'slug'                        => ['nullable', 'string', 'max:200', 'regex:/^[^\s\/]+$/u'],
            'status'                      => ['required', Rule::in(array_keys(self::STATUSES))],
            'wordpress_category_ids'      => ['nullable', 'array'],
            'wordpress_category_ids.*'    => ['integer'],
            'wordpress_tag_ids'           => ['nullable', 'array'],
            'wordpress_tag_ids.*'         => ['integer'],
            'wordpress_featured_media_id' => ['nullable', 'integer', 'min:0'],
            'revision_scope'              => ['required', Rule::enum(RevisionScope::class)],
        ]);

        // 入力されなかった項目は空文字ではなく空として扱う（本文・タイトルを空にする場合も、空文字で送る）
        $values = [
            'title_raw'                   => $validated['title_raw'] ?? '',
            'content_raw'                 => $validated['content_raw'] ?? '',
            'excerpt_raw'                 => $validated['excerpt_raw'] ?? '',
            'meta_description'            => $validated['meta_description'] ?? '',
            'slug'                        => $validated['slug'] ?? null,
            'status'                      => $validated['status'],
            'wordpress_featured_media_id' => (int) ($validated['wordpress_featured_media_id'] ?? 0),
            'revision_scope'              => RevisionScope::from($validated['revision_scope']),
        ];

        if ($draft->target_type === PushResourceType::Post) {
            $values['wordpress_category_ids'] = $this->ids($validated['wordpress_category_ids'] ?? []);
            $values['wordpress_tag_ids'] = $this->ids($validated['wordpress_tag_ids'] ?? []);
        }

        try {
            $changed = $this->draftService->update($draft, $values, $request->user()?->id);
        } catch (PushException $e) {
            return back()->withErrors(['draft' => $e->getMessage()])->withInput();
        }

        return redirect()->route('drafts.edit', ['id' => $draft->id])
            ->with('status', $changed === [] ? '変更はありませんでした。' : '保存しました（' . count($changed) . '項目）。');
    }

    public function changeState(Request $request, int $id)
    {
        $blog = $this->selectedBlog();
        $draft = $this->drafts->findForBlog($blog->id, $id);
        abort_if($draft === null, 404);

        $validated = $request->validate([
            'state' => ['required', Rule::in([DraftState::Editing->value, DraftState::Review->value, DraftState::Discarded->value])],
        ]);

        try {
            $this->draftService->changeState($draft, DraftState::from($validated['state']), $request->user()?->id);
        } catch (PushException $e) {
            return back()->withErrors(['draft' => $e->getMessage()]);
        }

        return redirect()->route('drafts.edit', ['id' => $draft->id])->with('status', '状態を変更しました。');
    }

    /**
     * @return array<int, int>
     */
    protected function ids(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return $ids;
    }
}
