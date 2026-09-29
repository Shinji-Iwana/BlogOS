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
 * WordPress への反映は、人が確認して行う（BlogOS が人の承認なしに WordPress を変えないため）。作業中の編集案がある記事には作らない。
 */
class LinkSwitchService
{
    public const REASON = 'link_switch';

    public function __construct(
        protected ArticleDraftRepository $drafts,
        protected DraftService $draftService,
        protected ArticleHtmlFinisher $finisher,
    ) {
    }

    /**
     * リンクに切り替えられる記事（公開された記事を、タイトルだけで載せている記事）
     *
     * @return list<array{article: Post|Page, titles: list<string>, active_draft: ArticleDraft|null}>
     */
    public function pending(Blog $blog): array
    {
        $rows = [];
        foreach ([Post::class, Page::class] as $modelClass) {
            $articles = $modelClass::where('blog_id', $blog->id)->existing()->where('content_raw', 'like', '%<!-- blogos:%')->get();
            foreach ($articles as $article) {
                $titles = $this->publishedTargets($blog, (string) $article->content_raw);
                if ($titles !== []) {
                    $rows[] = ['article' => $article, 'titles' => $titles, 'active_draft' => $this->drafts->activeFor($article)];
                }
            }
        }

        return $rows;
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

            $finished = $this->finisher->finish($blog, (string) $draft->content_raw);
            $this->drafts->update($draft, ['content_raw' => $finished['content']], ChangeSource::System, $userId);
            $draft->forceFill([
                'auto_reason'  => self::REASON,
                'finish_notes' => array_map(fn ($title) => "公開された記事「{$title}」へのリンクに切り替えました。", $row['titles']),
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
