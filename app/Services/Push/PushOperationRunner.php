<?php

namespace App\Services\Push;

use App\Clients\WordPress\WordPressApiClient;
use App\Enums\ChangeSource;
use App\Enums\DraftState;
use App\Enums\PushOperationType;
use App\Enums\PushResourceType;
use App\Enums\SyncIssueType;
use App\Models\Blog;
use App\Models\Page;
use App\Models\Post;
use App\Models\WordPressPushOperation;
use App\Models\WordPressRecord;
use App\Repositories\ArticleDraftRepository;
use App\Repositories\SyncIssueRepository;
use App\Repositories\WordPressPushOperationRepository;
use App\Repositories\WordPressRecordRepository;
use App\Services\Sync\Resources\AbstractResourceSyncer;
use App\Services\Sync\Resources\CategorySyncer;
use App\Services\Sync\Resources\MediaSyncer;
use App\Services\Sync\Resources\PageSyncer;
use App\Services\Sync\Resources\PostSyncer;
use App\Services\Sync\Resources\TagSyncer;
use App\Services\Sync\SyncContext;
use App\Services\Sync\SyncService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 反映の共通の処理（ARCHITECTURE 13-5・13-6、WORDPRESS_API 23・24章）。
 *
 * 記事（ArticlePushService）・カテゴリ・タグ・メディア（TermPushService）の反映と、
 * 回復処理（PushRecoveryService）から使う。反映記録の状態を進め、DBはWordPressの返却値で更新する（D-01-09）。
 */
class PushOperationRunner
{
    /**
     * 同期中にロックの解放を待つ時間
     */
    protected const LOCK_WAIT_SECONDS = 5;

    /**
     * 種類ごとのエンドポイント・同期の処理・反映記録の列
     */
    protected const RESOURCES = [
        'post'     => ['endpoint' => '/wp-json/wp/v2/posts', 'syncer' => PostSyncer::class, 'column' => 'post_id'],
        'page'     => ['endpoint' => '/wp-json/wp/v2/pages', 'syncer' => PageSyncer::class, 'column' => 'page_id'],
        'category' => ['endpoint' => '/wp-json/wp/v2/categories', 'syncer' => CategorySyncer::class, 'column' => 'category_id'],
        'tag'      => ['endpoint' => '/wp-json/wp/v2/tags', 'syncer' => TagSyncer::class, 'column' => 'tag_id'],
        'media'    => ['endpoint' => '/wp-json/wp/v2/media', 'syncer' => MediaSyncer::class, 'column' => 'media_id'],
    ];

    public function __construct(
        protected WordPressPushOperationRepository $operations,
        protected ArticleDraftRepository $drafts,
        protected SyncIssueRepository $issues,
        protected WordPressRecordRepository $records,
    ) {
    }

    public function endpoint(PushResourceType $type): string
    {
        return self::RESOURCES[$type->value]['endpoint'];
    }

    public function column(PushResourceType $type): string
    {
        return self::RESOURCES[$type->value]['column'];
    }

    public function syncer(PushResourceType $type): AbstractResourceSyncer
    {
        return app(self::RESOURCES[$type->value]['syncer']);
    }

    /**
     * 同期と同じブログ単位のロックの中で実行する。同期と反映を同時に動かさない（D-20-05）
     *
     * @throws PushException
     */
    public function withBlogLock(Blog $blog, callable $callback): mixed
    {
        $lock = Cache::lock(SyncService::lockKey($blog->id), SyncService::LOCK_SECONDS);

        try {
            $lock->block(self::LOCK_WAIT_SECONDS);
        } catch (LockTimeoutException $e) {
            throw new PushException('このブログの同期または反映が実行中です。完了してから、もう一度操作してください。');
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    /**
     * 状態を sent にしてから送信する。結果が分からない場合は unknown、受け付けられなかった場合は failed にする。
     *
     * @return Response|null 成功した応答（それ以外は null）
     */
    public function send(WordPressPushOperation $operation, callable $request): ?Response
    {
        $this->operations->markSent($operation);

        try {
            $response = $request();
        } catch (ConnectionException $e) {
            $this->markUnknown($operation, "送信中に通信が切れたため、WordPressに反映されたかが分かりません：{$e->getMessage()}");

            return null;
        }

        // 5xx は、WordPress側で処理が途中まで進んだ可能性があるため「結果が不明」とする（D-20-06）
        if ($response->serverError()) {
            $this->markUnknown($operation, "WordPressがエラーを返しました（HTTP {$response->status()}）。反映されたかを確認してください。", $response->status(), $response->body());

            return null;
        }

        if ($response->failed()) {
            $this->operations->markFailed($operation, "WordPressが反映を受け付けませんでした（HTTP {$response->status()}）。", $response->status(), $response->body());

            return null;
        }

        return $response;
    }

    /**
     * 応答を受け取り、反映記録に保存してからDBを更新する（WORDPRESS_API 23章）
     */
    public function receive(WordPressPushOperation $operation, mixed $body, ?int $userId): void
    {
        // 完全削除の応答は { deleted: true, previous: {...} }
        $item = $operation->operation === PushOperationType::Delete ? ($body['previous'] ?? null) : $body;

        if (! is_array($item) || ! isset($item['id'])) {
            $this->markUnknown($operation, 'WordPressの応答から、反映の結果を確認できませんでした。', 200, is_string($body) ? $body : json_encode($body));

            return;
        }

        // 1. 受信した直後に、最優先で反映記録に保存する
        $this->operations->markWpSucceeded(
            $operation,
            (int) $item['id'],
            $operation->operation === PushOperationType::Delete ? null : ($item['modified_gmt'] ?? null)
        );

        // 2〜4. DBの更新。失敗しても wp_succeeded のまま残り、回復処理で完了させる（WORDPRESS_API 24-2）
        try {
            $this->complete($operation->fresh(), $item, ChangeSource::BlogosPush, $userId);
        } catch (Throwable $e) {
            Log::error('反映：WordPressへの反映は成功しましたが、DBの更新に失敗しました。', [
                'wordpress_push_operation_id' => $operation->id,
                'message'                     => $e->getMessage(),
            ]);
            $operation->update(['message' => 'WordPressへの反映は成功しましたが、DBの更新に失敗しました。次の同期の最初に、回復処理で完了させます。']);
        }
    }

    /**
     * WordPressの返却値でDBを更新し、編集案と反映記録を完了にする（WORDPRESS_API 23章 2〜4）。
     * 反映の直後と、回復処理・結果が不明なものの確認で使う。
     */
    public function complete(WordPressPushOperation $operation, array $item, ChangeSource $source, ?int $userId): void
    {
        $operation->loadMissing(['blog', 'draft', 'post', 'page', 'category', 'tag', 'media']);
        $type = $operation->resource_type;
        $syncer = $this->syncer($type);
        $column = $this->column($type);
        $context = new SyncContext(
            $operation->blog,
            null,
            WordPressApiClient::forBlog($operation->blog),
            $source,
            ['wordpress_push_operation_id' => $operation->id, 'user_id' => $userId]
        );

        // カテゴリ・タグ・メディアを完全に削除すると、WordPressは関連していた投稿を付け替えるが、
        // 投稿の更新日時は変わらない。そのため、関連していた投稿を取得し直す（D-09-04）
        $linkedPostIds = [];

        DB::transaction(function () use ($operation, $item, $syncer, $context, $column, $userId, $type, &$linkedPostIds) {
            $record = $operation->post ?? $operation->page ?? $operation->category ?? $operation->tag ?? $operation->media;

            if ($operation->operation === PushOperationType::Delete) {
                if ($record !== null && in_array($type, [PushResourceType::Category, PushResourceType::Tag, PushResourceType::Media], true)) {
                    $linkedPostIds = $this->records->postWordpressIdsLinkedTo(
                        ['category' => 'categories', 'tag' => 'tags', 'media' => 'media'][$type->value],
                        [$record->id]
                    );
                }
                if ($record !== null && $record->wordpress_deleted_at === null) {
                    $syncer->markDeletedFromApi($record, $context);
                }
            } else {
                $record = $syncer->storeFromApi($item, $context);
            }

            if ($operation->draft !== null && $record !== null) {
                $draftChanges = [
                    'state'                       => DraftState::Pushed,
                    'pushed_at'                   => now(),
                    'base_wordpress_modified_gmt' => $record->wordpress_modified_gmt,
                ];
                if ($operation->draft->isNewArticle()) {
                    $draftChanges[$column] = $record->id;
                }
                $this->drafts->update($operation->draft, $draftChanges, ChangeSource::System, $userId, $operation->id);
            }

            $this->operations->markCompleted($operation, $record !== null ? [$column => $record->id] : []);
        });

        if ($linkedPostIds !== []) {
            $this->refetchPosts($operation->blog, $linkedPostIds, $context);
        }

        $this->issues->resolveRelated($operation->blog_id, [SyncIssueType::PushUnknown], $operation->id, null, $userId, '反映の結果を確認し、完了しました。');
    }

    /**
     * 投稿を詳細まで取得し直してDBを更新する（反映記録に結び付けて履歴を残す）。
     * 失敗しても反映そのものは完了しているため、ログに残し、次の同期での取り込みに任せる。
     *
     * @param array<int, int> $wordpressIds
     */
    protected function refetchPosts(Blog $blog, array $wordpressIds, SyncContext $context): void
    {
        $syncer = $this->syncer(PushResourceType::Post);

        try {
            foreach (array_chunk($wordpressIds, 100) as $chunk) {
                $items = $context->client->getAllPages('/wp-json/wp/v2/posts', [
                    'context' => 'edit',
                    'status'  => 'publish,future,draft,pending,private,trash',
                    'include' => implode(',', $chunk),
                ]);
                foreach ($items as $item) {
                    $syncer->storeFromApi($item, $context);
                }
            }
        } catch (Throwable $e) {
            Log::warning('反映：関連していた投稿を取得し直せませんでした。次の同期で取り込みます。', ['blog_id' => $blog->id, 'message' => $e->getMessage()]);
        }
    }

    /**
     * 結果が不明な反映として記録し、人が確認する問題にする（WORDPRESS_API 24-1）
     */
    public function markUnknown(WordPressPushOperation $operation, string $message, ?int $status = null, ?string $body = null): void
    {
        $this->operations->markUnknown($operation, $message, $status, $body);

        $this->issues->record($operation->blog_id, SyncIssueType::PushUnknown, $operation->resource_type->value, "push:{$operation->id}", [
            'wordpress_push_operation_id' => $operation->id,
            'article_draft_id'            => $operation->article_draft_id,
            'post_id'                     => $operation->post_id,
            'page_id'                     => $operation->page_id,
            'message'                     => $message,
            'error_status'                => $status,
            'error_body'                  => $body,
        ]);
    }

    /**
     * 反映直前の確認で競合を見つけた場合に、反映を中止して記録する（WORDPRESS_API 21-1・21-3）
     */
    public function stopForConflict(WordPressPushOperation $operation, WordPressRecord $record, string $resourceKey, ?int $draftId, string $message): void
    {
        $this->operations->markFailed($operation, $message);

        $this->issues->record($operation->blog_id, SyncIssueType::Conflict, $this->syncer($operation->resource_type)->key(), $resourceKey, [
            'post_id'                     => $record instanceof Post ? $record->id : null,
            'page_id'                     => $record instanceof Page ? $record->id : null,
            'article_draft_id'            => $draftId,
            'wordpress_push_operation_id' => $operation->id,
            'message'                     => $message,
        ]);
    }
}
