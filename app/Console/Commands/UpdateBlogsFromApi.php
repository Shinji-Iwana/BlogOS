<?php

namespace App\Console\Commands;

use App\Repositories\BlogRepository;
use App\Services\Blogs\BlogInspectionException;
use App\Services\Blogs\BlogSettingsSyncService;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

/**
 * 登録済みのブログのサイト設定をWordPressから取得し、差分があれば blog_settings を更新して履歴を残す。
 *
 * 対象はアーカイブしていないブログだけ（D-09-06）。
 * 同期の仕組み全体（sync_runs・ロック・Queue）への置き換えは段階3で行う。
 */
class UpdateBlogsFromApi extends Command
{
    protected $signature = 'blogs:update-from-api';

    protected $description = '登録済みの全ブログのサイト設定をAPIから取得し、差分があればDBを更新して履歴を残す';

    public function handle(BlogRepository $blogRepository, BlogSettingsSyncService $syncService): int
    {
        foreach ($blogRepository->getActive() as $blog) {
            try {
                $changed = $syncService->sync($blog);
            } catch (BlogInspectionException|ConnectionException $e) {
                $this->warn("[スキップ] {$blog->home}：{$e->getMessage()}");
                Log::warning('ブログのサイト設定の同期に失敗しました。', [
                    'blog_id' => $blog->id,
                    'message' => $e->getMessage(),
                ]);

                continue;
            }

            if ($changed === []) {
                $this->info("[変更なし] {$blog->home}");
            } else {
                $this->info("[更新] {$blog->home}：" . implode(', ', $changed));
            }
        }

        return self::SUCCESS;
    }
}
