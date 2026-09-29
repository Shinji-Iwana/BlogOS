<?php

namespace App\Services\Topics;

use App\Enums\ChangeSource;
use App\Enums\PushResourceType;
use App\Models\ArticleDraft;
use App\Models\CategoryLaunch;
use App\Models\CategoryLaunchChild;
use App\Models\Page;
use App\Repositories\ArticleDraftRepository;
use App\Repositories\ImageRepository;
use App\Services\Articles\ArticleHtmlFinisher;
use App\Services\Articles\DraftService;
use App\Services\Push\ArticlePushService;
use App\Services\Push\PushException;
use App\Services\Push\TermPushService;
use Illuminate\Support\Str;

/**
 * カテゴリの立ち上げの公開（D-41 の ⑥・⑦）。人が選んだ記事の編集案と子ロードマップを、まとめて WordPress に公開する。
 *
 * 1. 親ロードマップの固定ページがなければ、WordPress の下書きとして作る（子ロードマップの URL を /親/子.html にするため）
 * 2. WordPress にまだない子カテゴリを作る
 * 3. 記事の編集案にカテゴリ（とカテゴリのアイキャッチ）を設定し、仕上げ直して（公開済みの記事へのリンクにする）公開する
 * 4. 子ロードマップを、親ロードマップの子のページにして、仕上げ直して公開する（記事の後に公開し、公開した記事をリンクにする）
 */
class CategoryLaunchPublishService
{
    public function __construct(
        protected TermPushService $terms,
        protected ArticlePushService $push,
        protected DraftService $draftService,
        protected ArticleDraftRepository $drafts,
        protected ArticleHtmlFinisher $finisher,
        protected ImageRepository $images,
    ) {
    }

    /**
     * @param list<int> $draftIds 公開する記事の編集案
     * @return array{published: int, errors: list<string>}
     *
     * @throws PushException カテゴリ・親ロードマップのページを作れなかった場合
     */
    public function publish(CategoryLaunchChild $child, array $draftIds, bool $withRoadmap, ?int $userId): array
    {
        $launch = $child->launch()->with(['blog', 'parentCategory'])->firstOrFail();
        $blog = $launch->blog;

        $category = $this->ensureChildCategory($child, $userId);
        $eyecatch = $this->images->eyecatchFor($category);

        $published = 0;
        $errors = [];
        $articleDrafts = ArticleDraft::where('category_launch_child_id', $child->id)->where('target_type', PushResourceType::Post->value)
            ->whereIn('id', $draftIds)->active()->orderBy('id')->get();
        foreach ($articleDrafts as $draft) {
            $values = ['wordpress_category_ids' => [(int) $category->wordpress_id], 'status' => 'publish'];
            if ($eyecatch !== null && ! $draft->wordpress_featured_media_id) {
                $values['wordpress_featured_media_id'] = (int) $eyecatch->wordpress_id;
            }
            if ($this->publishDraft($draft, $values, $userId, $errors)) {
                $published++;
            }
        }

        $roadmap = $child->roadmapDraft()->first();
        if ($withRoadmap && $roadmap !== null && $roadmap->state->isActive()) {
            $parentPage = $this->ensureParentRoadmapPage($launch, $userId);
            if ($this->publishDraft($roadmap, [
                'slug'                => $child->slug ?: $roadmap->slug,
                'wordpress_parent_id' => (int) $parentPage->wordpress_id,
                'status'              => 'publish',
            ], $userId, $errors)) {
                $published++;
            }
        }

        return ['published' => $published, 'errors' => $errors];
    }

    /**
     * WordPress にまだない子カテゴリを作る
     *
     * @throws PushException
     */
    public function ensureChildCategory(CategoryLaunchChild $child, ?int $userId): \App\Models\Category
    {
        if ($child->category_id !== null && ($category = $child->category()->first()) !== null) {
            return $category;
        }

        $launch = $child->launch()->with(['blog', 'parentCategory'])->firstOrFail();
        $slug = $child->slug ?: Str::slug($child->name) ?: "{$launch->parentCategory->slug}-{$child->id}";
        $category = $this->terms->createCategory($launch->blog, $child->name, $slug, $launch->parentCategory, $userId);
        $child->update(['category_id' => $category->id, 'slug' => $category->slug]);

        return $category;
    }

    /**
     * 親ロードマップの固定ページ（スラッグが親カテゴリのスラッグで、親のページがないもの）。なければ WordPress の下書きとして作る
     *
     * @throws PushException
     */
    public function ensureParentRoadmapPage(CategoryLaunch $launch, ?int $userId): Page
    {
        $launch->loadMissing(['blog', 'parentCategory']);
        $slug = (string) $launch->parentCategory->slug;

        $existing = Page::where('blog_id', $launch->blog_id)->existing()->where('slug', $slug)->where('wordpress_parent_id', 0)->first();
        if ($existing !== null) {
            return $existing;
        }

        $draft = $launch->parent_roadmap_draft_id ? ArticleDraft::find($launch->parent_roadmap_draft_id) : null;
        if ($draft === null || ! $draft->state->isActive()) {
            $draft = $this->draftService->createNew($launch->blog, PushResourceType::Page, $userId);
            $this->drafts->update($draft, [
                'title_raw'   => "{$launch->parentCategory->name} ロードマップ（準備中）",
                'slug'        => $slug,
                'content_raw' => '<p>準備中です。</p>',
                'status'      => 'draft',
            ], ChangeSource::System, $userId);
            $launch->update(['parent_roadmap_draft_id' => $draft->id]);
        }

        $operation = $this->push->push($draft->fresh(), $userId);
        $page = $operation->fresh()?->page_id ? Page::find($operation->fresh()->page_id) : null;
        if ($page === null) {
            throw new PushException("親ロードマップのページ（下書き）を作れませんでした。反映記録 #{$operation->id} を確認してください。");
        }

        // 下書きのページは、⑧で中身を作って公開するため、編集案を作業中に戻して残す
        $launch->update(['parent_roadmap_draft_id' => $this->draftService->createFromArticle($page, null, $userId)->id]);

        return $page;
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string> $errors
     */
    protected function publishDraft(ArticleDraft $draft, array $values, ?int $userId, array &$errors): bool
    {
        $draft->loadMissing('blog');
        try {
            $this->drafts->update($draft, $values, ChangeSource::System, $userId);
            // 公開済みになった記事へのリンクにするため、仕上げ直す
            $finished = $this->finisher->finish($draft->blog, (string) $draft->content_raw);
            $this->drafts->update($draft, ['content_raw' => $finished['content']], ChangeSource::System, $userId);

            $operation = $this->push->push($draft->fresh(), $userId);
            if ($operation->fresh()?->state !== \App\Enums\PushState::Completed) {
                $errors[] = "「{$draft->title_raw}」：反映が完了しませんでした（反映記録 #{$operation->id}）。";

                return false;
            }

            return true;
        } catch (PushException $e) {
            $errors[] = "「{$draft->title_raw}」：{$e->getMessage()}";

            return false;
        }
    }
}
