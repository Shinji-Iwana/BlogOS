<?php

namespace App\Services\Articles;

use App\Enums\ChangeSource;
use App\Enums\RevisionScope;
use App\Models\ArticleDraft;
use App\Models\Blog;
use App\Models\Page;
use App\Models\Post;
use App\Repositories\ArticleDraftRepository;
use App\Services\Push\PushException;
use Illuminate\Support\Facades\Log;

/**
 * 公開された記事へのリンクの切り替え（D-39）。
 *
 * 仕上げで、公開していない記事へのリンクはタイトルだけにし、見えない目印（<!-- blogos:記事:ID --> / <!-- blogos:下書き:ID -->）を残している。
 * 同期・反映の後に、その記事が公開されたかを調べ、目印を持つ記事ごとに、リンクに切り替える編集案を作る（AIは使わない）。
 * 同じ編集案で、機械的に直せる内部リンク（古い URL・カテゴリの一覧 → ロードマップ。InternalLinkChecker。D-42）と、
 * タイトルが変わった記事へのリンクの文字（ArticleLinkTextUpdater。D-46）も直す。作業中の編集案のリンクの文字は、その場で直す。
 * WordPress への反映は、人が確認して行う（BlogOS が人の承認なしに WordPress を変えないため）。作業中の編集案がある記事には作らない。
 */
class LinkSwitchService
{
    public const REASON = 'link_switch';

    public function __construct(
        protected ArticleDraftRepository $drafts,
        protected DraftService $draftService,
        protected ArticleHtmlFinisher $finisher,
        protected InternalLinkChecker $links,
        protected ArticleLinkTextUpdater $linkTexts,
    ) {
    }

    /**
     * リンクに切り替えられる記事（公開された記事を、タイトルだけで載せている記事）と、
     * 機械的に直せる内部リンク（古い URL・カテゴリの一覧 → ロードマップ。D-42）がある記事
     *
     * @return list<array{article: Post|Page, titles: list<string>, fixes: list<string>, active_draft: ArticleDraft|null}>
     */
    public function pending(Blog $blog): array
    {
        $this->links->forget($blog->id);
        $rows = [];
        foreach ([Post::class, Page::class] as $modelClass) {
            $articles = $modelClass::where('blog_id', $blog->id)->existing()->where('content_raw', 'like', '%<!-- blogos:%')->get();
            foreach ($articles as $article) {
                $titles = $this->publishedTargets($blog, (string) $article->content_raw);
                if ($titles !== []) {
                    $rows[$modelClass . ':' . $article->id] = ['article' => $article, 'titles' => $titles, 'fixes' => []];
                }
            }
        }

        foreach ($this->links->check($blog)['links'] as $issue) {
            if ($issue['fix'] === null) {
                continue;
            }
            $key = $issue['source']::class . ':' . $issue['source']->id;
            $rows[$key] ??= ['article' => $issue['source']::find($issue['source']->id), 'titles' => [], 'fixes' => []];
            $rows[$key]['fixes'][] = "{$issue['link']->target_url} → {$issue['fix']}";
        }

        // タイトルが変わった記事へのリンクの文字が、以前のタイトルのままの記事（D-46）
        foreach ($this->linkTexts->staleArticles($blog) as $stale) {
            $key = $stale['article']::class . ':' . $stale['article']->id;
            $rows[$key] ??= ['article' => $stale['article'], 'titles' => [], 'fixes' => []];
            $rows[$key]['retitles'] = $stale['titles'];
        }

        return array_values(array_map(fn ($row) => $row + ['retitles' => [], 'active_draft' => $this->drafts->activeFor($row['article'])], $rows));
    }

    /**
     * 作業中の編集案のリンクの文字を、タイトルが変わった記事の今のタイトルに直す（D-46。WordPress は変えない）。
     * 反映の結果待ちの編集案は変えない
     *
     * @return int 直した編集案の数
     */
    public function refreshActiveDrafts(Blog $blog, ?int $userId = null): int
    {
        $this->linkTexts->forget($blog->id);
        if ($this->linkTexts->renamed($blog->id) === []) {
            return 0;
        }

        $count = 0;
        foreach (ArticleDraft::where('blog_id', $blog->id)->active()->where('content_raw', 'like', '%<a %')->orderBy('id')->get() as $draft) {
            if ($draft->isLocked()) {
                continue;
            }
            $refreshed = $this->linkTexts->refresh($blog, (string) $draft->content_raw);
            if ($refreshed['content'] === (string) $draft->content_raw) {
                continue;
            }
            $this->drafts->update($draft, ['content_raw' => $refreshed['content']], ChangeSource::System, $userId);
            $draft->forceFill(['finish_notes' => array_values(array_unique(array_merge((array) $draft->finish_notes, $refreshed['notes'])))])->save();
            $count++;
        }

        return $count;
    }

    /**
     * リンクに切り替える編集案を作る（作業中の編集案がある記事は除く）
     *
     * @return int 作った編集案の数
     */
    public function createDrafts(Blog $blog, ?int $userId = null): int
    {
        if ($blog->isArchived()) {
            return 0;
        }

        // 作業中の編集案は、その場で直す（公開中の記事は、下で編集案を作って人が反映する）
        $this->refreshActiveDrafts($blog, $userId);

        $created = 0;
        foreach ($this->pending($blog) as $row) {
            if ($row['active_draft'] !== null) {
                continue;
            }

            try {
                $draft = $this->draftService->createFromArticle($row['article'], RevisionScope::Minor, $userId);
            } catch (PushException $e) {
                Log::info('リンクの切り替えの編集案を作れませんでした。', ['article' => $row['article']->id, 'message' => $e->getMessage()]);

                continue;
            }

            // 目印の切り替えは仕上げで行う。機械的なリンクの修正だけの記事は、仕上げ（広告の挿入など）を通さない
            $content = (string) $draft->content_raw;
            $notes = array_map(fn ($title) => "公開された記事「{$title}」へのリンクに切り替えました。", $row['titles']);
            if ($row['titles'] !== []) {
                $finished = $this->finisher->finish($blog, $content);
                $content = $finished['content'];
                $notes = array_merge($notes, array_values(array_filter($finished['notes'], fn ($note) => str_starts_with($note, 'タイトルが変わった記事へのリンクの文字'))));
            } elseif ($row['retitles'] !== []) {
                $refreshed = $this->linkTexts->refresh($blog, $content);
                $content = $refreshed['content'];
                $notes = array_merge($notes, $refreshed['notes']);
            }
            if ($row['fixes'] !== []) {
                $fixed = $this->links->fixContent($row['article'], $content);
                $content = $fixed['content'];
                $notes = array_merge($notes, array_map(fn ($note) => "内部リンクを直しました（{$note}）。", $fixed['notes']));
            }
            $this->drafts->update($draft, ['content_raw' => $content], ChangeSource::System, $userId);
            $draft->forceFill([
                'auto_reason'  => self::REASON,
                'finish_notes' => $notes,
            ])->save();
            $created++;
        }

        return $created;
    }

    /**
     * 本文の目印のうち、公開された記事のタイトル
     *
     * @return list<string>
     */
    protected function publishedTargets(Blog $blog, string $content): array
    {
        preg_match_all('/<!-- blogos:(記事|下書き):(\d+) -->/u', $content, $matches, PREG_SET_ORDER);

        $titles = [];
        foreach ($matches as [, $type, $id]) {
            $article = null;
            if ($type === '下書き') {
                $draft = ArticleDraft::with(['post:id,title_raw,status', 'page:id,title_raw,status'])->where('blog_id', $blog->id)->find((int) $id);
                $article = $draft?->post ?? $draft?->page;
            } else {
                foreach ([Post::class, Page::class] as $modelClass) {
                    $article ??= $modelClass::where('blog_id', $blog->id)->where('wordpress_id', (int) $id)->existing()->first(['id', 'title_raw', 'status']);
                }
            }
            if ($article !== null && $article->status === 'publish') {
                $titles[] = (string) $article->title_raw;
            }
        }

        return array_values(array_unique($titles));
    }
}
