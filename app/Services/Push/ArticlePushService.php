<?php

namespace App\Services\Push;

use App\Clients\WordPress\WordPressApiClient;
use App\Clients\WordPress\WordPressApiException;
use App\Enums\ChangeSource;
use App\Enums\DraftState;
use App\Enums\PushOperationType;
use App\Enums\PushResourceType;
use App\Enums\SyncIssueType;
use App\Models\ArticleDraft;
use App\Models\Page;
use App\Models\Post;
use App\Models\WordPressPushOperation;
use App\Repositories\ArticleDraftRepository;
use App\Repositories\BlogCredentialRepository;
use App\Repositories\SyncIssueRepository;
use App\Repositories\WordPressPushOperationRepository;
use App\Services\Sync\SyncContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 記事（投稿・固定ページ）のWordPressへの反映と、競合の解消（BLOGOS_ARCHITECTURE.md 13-5、BLOGOS_WORDPRESS_API.md 第IV部）。
 *
 * 人が承認した後に呼ぶ。送信・受信・DBの更新は PushOperationRunner が行う。
 */
class ArticlePushService
{
    public function __construct(
        protected PushOperationRunner $runner,
        protected WordPressPushOperationRepository $operations,
        protected ArticleDraftRepository $drafts,
        protected SyncIssueRepository $issues,
        protected BlogCredentialRepository $credentials,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | 送る内容
    |--------------------------------------------------------------------------
    */

    /**
     * WordPressに送る項目。既存記事の更新では、DBの現在の値から変わる項目だけを送る（WORDPRESS_API 22章）。
     *
     * @return array<string, mixed>
     */
    public function payload(ArticleDraft $draft): array
    {
        $values = [
            'title'          => $draft->title_raw,
            'content'        => $draft->content_raw,
            'excerpt'        => $draft->excerpt_raw,
            'slug'           => $draft->slug,
            'status'         => $draft->status,
            'featured_media' => $draft->wordpress_featured_media_id,
        ];

        if ($draft->target_type === PushResourceType::Post) {
            $values['categories'] = $this->ids($draft->wordpress_category_ids);
            $values['tags'] = $this->ids($draft->wordpress_tag_ids);
        }

        $article = $draft->article();

        if ($article === null) {
            $values['status'] ??= 'draft';

            return array_filter($values, fn ($value) => $value !== null);
        }

        $current = $this->currentValues($article);

        return array_filter(
            $values,
            fn ($value, $key) => $value !== null && $value !== ($current[$key] ?? null),
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * 記事の現在の値（WordPressに送る項目の形）
     *
     * @return array<string, mixed>
     */
    public function currentValues(Post|Page $article): array
    {
        $values = [
            'title'          => $article->title_raw,
            'content'        => $article->content_raw,
            'excerpt'        => $article->excerpt_raw,
            'slug'           => $article->slug,
            'status'         => $article->status,
            'featured_media' => (int) $article->wordpress_featured_media_id,
        ];

        if ($article instanceof Post) {
            $article->loadMissing(['categories', 'tags']);
            $values['categories'] = $this->ids($article->categories->pluck('wordpress_id')->all());
            $values['tags'] = $this->ids($article->tags->pluck('wordpress_id')->all());
        }

        return $values;
    }

    /**
     * 反映の後に、記事が公開された状態になるか（公開は確認画面を挟む重大な操作。ARCHITECTURE 18-2 の段階3）
     */
    public function willBePublic(ArticleDraft $draft): bool
    {
        $status = $draft->status ?? $draft->article()?->status ?? 'draft';

        return in_array($status, ['publish', 'future'], true);
    }

    /*
    |--------------------------------------------------------------------------
    | 反映
    |--------------------------------------------------------------------------
    */

    /**
     * 編集案を反映する（新規作成または更新）。
     *
     * @throws PushException 反映を始められない場合（反映記録は作らない）
     */
    public function push(ArticleDraft $draft, ?int $userId): WordPressPushOperation
    {
        $draft->loadMissing(['blog', 'post', 'page']);

        if (! $draft->state->isActive()) {
            throw new PushException('作業中または確認待ちの編集案だけを反映できます。');
        }
        if ($draft->blog->isArchived()) {
            throw new PushException('アーカイブしたブログには反映できません。');
        }
        if ($draft->isLocked()) {
            throw new PushException('結果が確定していない反映記録があるため、反映できません。先に反映記録の画面で確認してください。');
        }

        $payload = $this->payload($draft);
        if ($payload === []) {
            throw new PushException('WordPressの現在の内容から変わっている項目がありません。');
        }

        return $this->runner->withBlogLock($draft->blog, fn () => $this->executePush($draft, $payload, $userId));
    }

    protected function executePush(ArticleDraft $draft, array $payload, ?int $userId): WordPressPushOperation
    {
        $blog = $draft->blog;
        $article = $draft->article();
        $type = $draft->target_type;
        $endpoint = $this->runner->endpoint($type);

        $operation = $this->operations->create(
            $blog->id,
            $type,
            $article === null ? PushOperationType::Create : PushOperationType::Update,
            array_filter([
                'article_draft_id'          => $draft->id,
                $this->runner->column($type) => $article?->id,
            ]),
            $this->summarize($payload),
            ['modified_gmt' => $draft->base_wordpress_modified_gmt?->format('Y-m-d H:i:s')],
            $userId
        );

        $client = WordPressApiClient::forBlog($blog);

        // 反映直前の競合確認（WORDPRESS_API 21-1）
        if ($article !== null && ! $this->checkNotChanged($operation, $client, $article, $draft->base_wordpress_modified_gmt, $draft->id)) {
            return $operation->fresh();
        }

        // 拡張が有効なブログでは、新規作成の照合に使う編集案のIDを送る（WORDPRESS_API 25・26章）
        if ($article === null && $this->credentials->findForBlog($blog->id)?->connector_extension) {
            $payload['meta'] = ['_blogos_draft_id' => $draft->uuid];
        }

        $response = $this->runner->send($operation, fn () => $client->post(
            $article === null ? $endpoint : "{$endpoint}/{$article->wordpress_id}",
            $payload
        ));

        if ($response !== null) {
            $this->runner->receive($operation, $response->json(), $userId);
        }

        return $operation->fresh();
    }

    /**
     * 記事をゴミ箱へ移動する、または完全に削除する（WORDPRESS_API 22章、D-09-05）。
     *
     * @param Carbon|null $baseModifiedGmt 画面を開いた時点の記事の版（競合確認に使う）
     * @throws PushException
     */
    public function trash(Post|Page $article, bool $force, ?Carbon $baseModifiedGmt, ?int $userId): WordPressPushOperation
    {
        $article->loadMissing('blog');

        if ($article->blog->isArchived()) {
            throw new PushException('アーカイブしたブログには反映できません。');
        }
        if ($article->wordpress_deleted_at !== null) {
            throw new PushException('この記事は、既にWordPress側で完全に削除されています。');
        }
        if (! $force && $article->status === 'trash') {
            throw new PushException('この記事は、既にゴミ箱にあります。');
        }
        if ($this->drafts->activeFor($article) !== null) {
            throw new PushException('作業中の編集案があります。編集案を破棄するか反映してから操作してください。');
        }

        return $this->runner->withBlogLock($article->blog, function () use ($article, $force, $baseModifiedGmt, $userId) {
            $type = $article instanceof Post ? PushResourceType::Post : PushResourceType::Page;

            $operation = $this->operations->create(
                $article->blog_id,
                $type,
                $force ? PushOperationType::Delete : PushOperationType::Trash,
                [$this->runner->column($type) => $article->id],
                ['force' => $force],
                ['modified_gmt' => $baseModifiedGmt?->format('Y-m-d H:i:s')],
                $userId
            );

            $client = WordPressApiClient::forBlog($article->blog);

            if (! $this->checkNotChanged($operation, $client, $article, $baseModifiedGmt, null)) {
                return $operation->fresh();
            }

            $response = $this->runner->send($operation, fn () => $client->delete(
                $this->runner->endpoint($type) . "/{$article->wordpress_id}",
                $force ? ['force' => true] : []
            ));

            if ($response !== null) {
                $this->runner->receive($operation, $response->json(), $userId);
            }

            return $operation->fresh();
        });
    }

    /*
    |--------------------------------------------------------------------------
    | 競合の解消（WORDPRESS_API 21-2、D-15-03）
    |--------------------------------------------------------------------------
    */

    /**
     * WordPressの最新の内容を取得する（競合の解消の画面で、編集案との差分を示すため）
     *
     * @return array<string, mixed> WordPressの項目（context=edit）
     *
     * @throws WordPressApiException
     * @throws PushException
     */
    public function fetchLatest(ArticleDraft $draft): array
    {
        $draft->loadMissing(['blog', 'post', 'page']);
        $article = $draft->article() ?? throw new PushException('新規記事の編集案には、競合はありません。');

        return WordPressApiClient::forBlog($draft->blog)
            ->getOrFail($this->runner->endpoint($draft->target_type) . "/{$article->wordpress_id}", ['context' => 'edit'])
            ->json();
    }

    /**
     * WordPressの項目を、送る項目の形（payload と同じキー）にする
     */
    public function valuesFromApi(array $item): array
    {
        $values = [
            'title'          => $item['title']['raw'] ?? null,
            'content'        => $item['content']['raw'] ?? null,
            'excerpt'        => $item['excerpt']['raw'] ?? null,
            'slug'           => $item['slug'] ?? null,
            'status'         => $item['status'] ?? null,
            'featured_media' => (int) ($item['featured_media'] ?? 0),
        ];

        if (array_key_exists('categories', $item)) {
            $values['categories'] = $this->ids($item['categories']);
            $values['tags'] = $this->ids($item['tags'] ?? []);
        }

        return $values;
    }

    /**
     * 競合を解消する。
     *
     * * take_in：WordPressの変更を取り込む。DBを最新にし、編集案の基準の版を最新にする（編集案は作業中のまま）
     * * discard：編集案を破棄し、DBを最新にする
     * * overwrite：DBと基準の版を最新にする（この後、確認画面を経て編集案で上書きする）
     *
     * @throws PushException
     * @throws WordPressApiException
     */
    public function resolveConflict(ArticleDraft $draft, string $action, ?int $userId): void
    {
        $draft->loadMissing(['blog', 'post', 'page']);

        if (! $draft->state->isActive()) {
            throw new PushException('作業中または確認待ちの編集案だけを対象にできます。');
        }
        if (! in_array($action, ['take_in', 'discard', 'overwrite'], true)) {
            throw new PushException('対応を選んでください。');
        }

        $this->runner->withBlogLock($draft->blog, function () use ($draft, $action, $userId) {
            $item = $this->fetchLatest($draft);

            $context = new SyncContext($draft->blog, null, WordPressApiClient::forBlog($draft->blog), ChangeSource::WpSync, ['user_id' => $userId]);

            DB::transaction(function () use ($draft, $action, $userId, $item, $context) {
                $record = $this->runner->syncer($draft->target_type)->storeFromApi($item, $context);

                if ($action === 'discard') {
                    $this->drafts->changeState($draft, DraftState::Discarded, ChangeSource::BlogosManual, $userId);
                } else {
                    $this->drafts->update($draft, ['base_wordpress_modified_gmt' => $record->wordpress_modified_gmt], ChangeSource::BlogosManual, $userId);
                }
            });

            $this->issues->resolveRelated($draft->blog_id, [SyncIssueType::Conflict], null, $draft->id, $userId, match ($action) {
                'take_in'   => 'WordPressの変更を取り込みました（編集案は作業中のまま）。',
                'discard'   => '編集案を破棄しました。',
                'overwrite' => '編集案で上書きすることにしました。',
            });
        });
    }

    /*
    |--------------------------------------------------------------------------
    | 共通の処理
    |--------------------------------------------------------------------------
    */

    /**
     * WordPress側の版が、編集の起点（画面を開いた時点）から変わっていないことを確かめる。
     * 変わっていれば反映を中止し、競合として記録する（WORDPRESS_API 21-1）。
     */
    protected function checkNotChanged(WordPressPushOperation $operation, WordPressApiClient $client, Post|Page $article, ?Carbon $base, ?int $draftId): bool
    {
        try {
            $latest = $client->getOrFail($this->runner->endpoint($operation->resource_type) . "/{$article->wordpress_id}", [
                'context' => 'edit',
                '_fields' => 'id,modified_gmt',
            ])->json();
        } catch (WordPressApiException $e) {
            $this->operations->markFailed($operation, "反映前の確認で、WordPressから記事を取得できませんでした：{$e->getMessage()}", $e->status, $e->body);

            return false;
        }

        $latestModified = isset($latest['modified_gmt']) ? str_replace('T', ' ', $latest['modified_gmt']) : null;

        if ($base !== null && $latestModified === $base->format('Y-m-d H:i:s')) {
            return true;
        }

        $this->runner->stopForConflict(
            $operation,
            $article,
            (string) $article->wordpress_id,
            $draftId,
            'WordPress側で記事が変更されているため、反映を中止しました（競合）。競合の解消の画面で対応を選んでください。'
        );

        return false;
    }

    /**
     * 反映記録に残す送信内容の要約。本文は長いため、文字数だけを残す
     */
    protected function summarize(array $payload): array
    {
        if (isset($payload['content'])) {
            $payload['content'] = ['length' => mb_strlen((string) $payload['content']), 'sha1' => sha1((string) $payload['content'])];
        }

        return $payload;
    }

    /**
     * @return array<int, int>|null
     */
    protected function ids(?array $ids): ?array
    {
        if ($ids === null) {
            return null;
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return $ids;
    }
}
