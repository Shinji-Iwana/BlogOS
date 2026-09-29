<?php

namespace App\Jobs;

use App\Clients\Google\GoogleApiException;
use App\Models\Blog;
use App\Services\Google\GoogleIndexInspectionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 画面の「今すぐ調べる」（D-37）。記事の数だけ API を呼ぶため、Queue で動かす。
 */
class InspectGoogleIndexJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(
        public readonly int $blogId,
        public readonly bool $all = false,
    ) {
    }

    public function handle(GoogleIndexInspectionService $service): void
    {
        $blog = Blog::find($this->blogId);
        if ($blog === null) {
            return;
        }

        try {
            $service->inspect($blog, null, $this->all, 300);
        } catch (GoogleApiException $e) {
            Log::warning('インデックスの登録状態を調べられませんでした。', ['blog_id' => $blog->id, 'message' => $e->getMessage()]);
        }
    }
}
