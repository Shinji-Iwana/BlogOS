<?php

namespace App\Services\Sync;

use App\Clients\WordPress\WordPressApiClient;
use App\Enums\ChangeSource;
use App\Models\Blog;
use App\Models\SyncRun;

/**
 * 1回の同期の中で、リソースの同期に共通して渡す情報。
 */
class SyncContext
{
    /**
     * 詳細まで取得し直す投稿のWordPress ID。
     * カテゴリ・タグ・メディアの削除を検知した場合に、関連していた投稿を追加する（D-09-04）。
     *
     * @var array<int, int>
     */
    public array $forcedPostIds = [];

    public function __construct(
        public readonly Blog $blog,
        public readonly SyncRun $run,
        public readonly WordPressApiClient $client,
        public readonly ChangeSource $source,
    ) {
    }
}
