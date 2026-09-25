<?php

namespace App\Services\Sync;

use App\Clients\WordPress\WordPressApiClient;
use App\Enums\ChangeSource;
use App\Models\Blog;
use App\Models\SyncRun;

/**
 * WordPressから取得した値をDBに保存するときの状況。
 *
 * 同期では実行記録（run）を持つ。反映・回復処理では run を持たず、
 * 履歴に反映記録と承認した利用者を記録するため historyAttributes を使う（DATABASE 8-2）。
 */
class SyncContext
{
    /**
     * 詳細まで取得し直す投稿のWordPress ID（削除されたカテゴリ等に関連していたもの。D-09-04）
     *
     * @var array<int, int>
     */
    public array $forcedPostIds = [];

    /**
     * @param array<string, mixed> $historyAttributes 履歴に加えて記録する値（wordpress_push_operation_id、user_id）
     */
    public function __construct(
        public readonly Blog $blog,
        public readonly ?SyncRun $run,
        public readonly WordPressApiClient $client,
        public readonly ChangeSource $source,
        public readonly array $historyAttributes = [],
    ) {
    }

    public function runId(): ?int
    {
        return $this->run?->id;
    }
}
