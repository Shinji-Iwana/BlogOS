<?php

namespace App\Console\Commands;

use App\DTO\WordPress\BlogApiDto;
use App\Models\Blog;
use App\Repositories\BlogRepository;
use App\Services\WordPress\BlogService;
use Illuminate\Console\Command;

class UpdateBlogsFromApi extends Command
{
    protected $signature = 'blogs:update-from-api';
    protected $description = '登録済み全ブログのサイト情報をAPIから取得し、差分があればDBを更新して履歴を残す';

    public function handle(BlogRepository $blogRepository): int
    {
        $blogs = Blog::all();

        foreach ($blogs as $blog) {
            try {
                $client = new BlogService($blog);

                $rawData = $client->getSite();

                if ($rawData === null) {
                    $this->warn("[スキップ] {$blog->home}：サイト情報を取得できませんでした。");
                    continue;
                }

                $apiData = BlogApiDto::fromApiResponse($rawData);

                if ($apiData === null) {
                    $missingFields = BlogApiDto::findMissingFields($rawData);
                    $this->warn("[スキップ] {$blog->home}：必須項目が不足しています（" . implode(', ', $missingFields) . '）。');
                    continue;
                }

                $diff = $blogRepository->diff($blog, $apiData);

                if (empty($diff)) {
                    $blogRepository->touchSynced($blog);
                    $this->info("[変更なし] {$blog->home}");
                    continue;
                }

                $blogRepository->updateWithHistory($blog, $diff, '定期自動更新');
                $blogRepository->touchSynced($blog->fresh());

                $this->info("[更新] {$blog->home}：" . implode(', ', array_keys($diff)));
            } catch (\Throwable $e) {
                $this->error("[エラー] {$blog->home}：{$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
