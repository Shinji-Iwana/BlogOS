<?php

namespace App\Services\Sync;

use App\Clients\WordPress\WordPressApiClient;
use App\Clients\WordPress\WordPressApiException;
use App\Enums\ChangeSource;
use App\Enums\SyncIssueType;
use App\Enums\SyncStatus;
use App\Enums\SyncTrigger;
use App\Models\Blog;
use App\Models\SyncRun;
use App\Repositories\SyncIssueRepository;
use App\Repositories\SyncRunRepository;
use App\Services\Blogs\BlogInspectionException;
use App\Services\Articles\ContentExtractionService;
use App\Services\Push\PushRecoveryService;
use App\Services\Blogs\BlogSettingsSyncService;
use App\Services\Sync\Resources\AbstractResourceSyncer;
use App\Services\Sync\Resources\AuthorSyncer;
use App\Services\Sync\Resources\CategorySyncer;
use App\Services\Sync\Resources\CustomContentSyncer;
use App\Services\Sync\Resources\CustomTermSyncer;
use App\Services\Sync\Resources\MediaSyncer;
use App\Services\Sync\Resources\PageSyncer;
use App\Services\Sync\Resources\PostSyncer;
use App\Services\Sync\Resources\StatusSyncer;
use App\Services\Sync\Resources\TagSyncer;
use App\Services\Sync\Resources\TaxonomySyncer;
use App\Services\Sync\Resources\TypeSyncer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 1つのブログの同期（BLOGOS_ARCHITECTURE.md 13-4、BLOGOS_WORDPRESS_API.md 第III部）。
 *
 * ブログ単位のロック → 実行記録 → サイト設定 → リソースごとの同期（依存関係の順）→ 参照先の解決 → 実行記録の完了
 *
 * トランザクションは1レコード単位とし、同期全体を1つのトランザクションにしない。
 * 一部のリソースが失敗しても、他のリソースの結果は残し、実行記録を partial とする。
 */
class SyncService
{
    /**
     * 取得の順序（依存関係に基づく。D-05-06、WORDPRESS_API 14章）
     */
    protected const RESOURCES = [
        StatusSyncer::class,
        TypeSyncer::class,
        TaxonomySyncer::class,
        AuthorSyncer::class,
        CategorySyncer::class,
        TagSyncer::class,
        CustomTermSyncer::class,
        MediaSyncer::class,
        PageSyncer::class,
        PostSyncer::class,
        CustomContentSyncer::class,
    ];

    /**
     * ロックを自動で解除するまでの時間（異常終了でロックが残った場合に備える）
     */
    public const LOCK_SECONDS = 3600;

    /**
     * ブログ単位のロックのキー。反映（App\Services\Push）も同じロックを使い、同期と反映が同時に動かないようにする（D-20-05）
     */
    public static function lockKey(int $blogId): string
    {
        return "blogos:sync:blog:{$blogId}";
    }

    public function __construct(
        protected SyncRunRepository $runs,
        protected SyncIssueRepository $issues,
        protected BlogSettingsSyncService $settingsSync,
        protected ContentExtractionService $extraction,
    ) {
    }

    /**
     * @throws SyncAlreadyRunningException
     */
    public function run(Blog $blog, SyncTrigger $trigger, ?int $userId = null, bool $fullRefetch = false): SyncRun
    {
        $lock = Cache::lock(self::lockKey($blog->id), self::LOCK_SECONDS);

        if (! $lock->get()) {
            throw new SyncAlreadyRunningException('このブログの同期は既に実行中です。');
        }

        try {
            // ロックが切れた後も「実行中」のまま残った記録を片付ける
            $this->runs->failStale($blog->id, self::LOCK_SECONDS);

            return $this->execute($blog, $trigger, $userId, $fullRefetch);
        } finally {
            $lock->release();
        }
    }

    protected function execute(Blog $blog, SyncTrigger $trigger, ?int $userId, bool $fullRefetch = false): SyncRun
    {
        $run = $this->runs->start($blog, $trigger, $userId);
        $source = $trigger === SyncTrigger::Initial ? ChangeSource::WpInitialSync : ChangeSource::WpSync;
        $context = new SyncContext($blog, $run, WordPressApiClient::forBlog($blog), $source);
        $context->fullRefetch = $fullRefetch;

        $failed = 0;
        $total = 0;

        // 回復処理：止まっている反映を完了させる（WORDPRESS_API 24-2。D-15-07：実行契機はこの同期のもの）
        try {
            $recovery = app(PushRecoveryService::class)->recover($blog);
            if ($recovery['recovered'] + $recovery['matched'] > 0) {
                $this->runs->note($run, "回復処理で反映記録を{$recovery['recovered']}件完了し、新規作成を{$recovery['matched']}件照合しました。");
            }
        } catch (Throwable $e) {
            Log::error('同期：回復処理に失敗しました。', ['blog_id' => $blog->id, 'message' => $e->getMessage()]);
        }

        // サイト設定
        $total++;
        if (! $this->syncSettings($context)) {
            $failed++;
        }

        // リソースごとの同期
        $syncers = array_map(fn (string $class) => app($class), self::RESOURCES);
        foreach ($syncers as $syncer) {
            $total++;
            if (! $this->syncResource($syncer, $context)) {
                $failed++;
            }
        }

        // 参照先の解決（全リソースを取得した後にまとめて行う）
        foreach ($syncers as $syncer) {
            try {
                $syncer->resolveReferences($context);
            } catch (Throwable $e) {
                Log::error('同期：参照先の解決に失敗しました。', ['blog_id' => $blog->id, 'resource' => $syncer->key(), 'message' => $e->getMessage()]);
            }
        }

        // 本文から抽出した内部リンク・本文中のメディアのうち、未解決のものを照合し直す（D-15-09）
        try {
            $this->extraction->resolve($blog->id);
        } catch (Throwable $e) {
            Log::error('同期：内部リンク・本文中のメディアの照合に失敗しました。', ['blog_id' => $blog->id, 'message' => $e->getMessage()]);
        }

        $status = match (true) {
            $failed === 0      => SyncStatus::Succeeded,
            $failed === $total => SyncStatus::Failed,
            default            => SyncStatus::Partial,
        };

        return $this->runs->finish($run, $status);
    }

    protected function syncSettings(SyncContext $context): bool
    {
        $resource = $this->runs->startResource($context->run, 'settings');

        try {
            $result = $this->settingsSync->sync($context->blog, $context->source, $context->runId());
        } catch (BlogInspectionException $e) {
            $this->recordFailure($context, 'settings', $e->getMessage(), null, null);
            $this->runs->finishResource($resource, SyncStatus::Failed, ['error' => 1], $e->getMessage());

            return false;
        } catch (WordPressApiException $e) {
            $this->recordFailure($context, 'settings', $e->getMessage(), $e->status, $e->body);
            $this->runs->finishResource($resource, SyncStatus::Failed, ['error' => 1], $e->getMessage());

            return false;
        }

        if ($result['home_changed']) {
            $this->issues->record($context->blog->id, SyncIssueType::HomeChanged, 'settings', 'home', [
                'sync_run_id' => $context->runId(),
                'message'     => "WordPressのサイトアドレスが「{$result['home_from_wordpress']}」になっています（登録：{$context->blog->home}）。接続先は自動で変更していません。",
            ]);
        }

        $this->runs->finishResource($resource, SyncStatus::Succeeded, [
            'updated' => count($result['changed']),
        ]);

        return true;
    }

    protected function syncResource(AbstractResourceSyncer $syncer, SyncContext $context): bool
    {
        $resource = $this->runs->startResource($context->run, $syncer->key());

        try {
            $counts = $syncer->sync($context);
        } catch (WordPressApiException $e) {
            $this->recordFailure($context, $syncer->key(), $e->getMessage(), $e->status, $e->body);
            $this->runs->finishResource($resource, SyncStatus::Failed, ['error' => 1], $e->getMessage());

            return false;
        } catch (Throwable $e) {
            Log::error('同期：リソースの同期に失敗しました。', [
                'blog_id'  => $context->blog->id,
                'resource' => $syncer->key(),
                'message'  => $e->getMessage(),
            ]);
            $this->recordFailure($context, $syncer->key(), $e->getMessage(), null, null);
            $this->runs->finishResource($resource, SyncStatus::Failed, ['error' => 1], $e->getMessage());

            return false;
        }

        $this->runs->finishResource($resource, SyncStatus::Succeeded, $counts);

        return true;
    }

    /**
     * 取得の失敗を記録する。応答本文は全文を保存する（D-10-03）
     */
    protected function recordFailure(SyncContext $context, string $resourceType, string $message, ?int $status, ?string $body): void
    {
        $this->issues->record($context->blog->id, SyncIssueType::FetchError, $resourceType, null, [
            'sync_run_id'  => $context->runId(),
            'message'      => $message,
            'error_status' => $status,
            'error_body'   => $body,
        ]);
    }
}
