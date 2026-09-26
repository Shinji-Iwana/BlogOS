<?php

namespace App\Http\Controllers\Concerns;

use App\Models\ArticleDraft;
use App\Models\Page;
use App\Models\Post;
use App\Repositories\ArticleDraftRepository;
use App\Repositories\ArticleRepository;

/**
 * 評価・AIの対象の指定（posts:ID / pages:ID / drafts:ID）を、記事と編集案にする。
 */
trait ResolvesArticleTarget
{
    /**
     * @return array{article: Post|Page|null, draft: ArticleDraft|null}
     */
    protected function resolveTarget(int $blogId, ?string $target): array
    {
        if (! is_string($target) || ! preg_match('/^(posts|pages|drafts):(\d+)$/', $target, $matches)) {
            return ['article' => null, 'draft' => null];
        }

        if ($matches[1] === 'drafts') {
            $draft = app(ArticleDraftRepository::class)->findForBlog($blogId, (int) $matches[2]);
            abort_if($draft === null, 404);

            return ['article' => $draft->article(), 'draft' => $draft];
        }

        $article = app(ArticleRepository::class)->find($blogId, $matches[1], (int) $matches[2]);
        abort_if($article === null, 404);

        return ['article' => $article, 'draft' => null];
    }

    protected function targetKey(Post|Page|null $article, ?ArticleDraft $draft): ?string
    {
        return match (true) {
            $draft !== null         => "drafts:{$draft->id}",
            $article instanceof Post => "posts:{$article->id}",
            $article instanceof Page => "pages:{$article->id}",
            default                  => null,
        };
    }
}
