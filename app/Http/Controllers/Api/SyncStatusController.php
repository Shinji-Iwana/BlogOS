<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\BlogRepository;
use App\Services\Sync\SyncStatusService;
use Illuminate\Http\JsonResponse;

/**
 * 選択中のブログの同期の状態（JSON）。
 *
 * 「今すぐ同期」の後、ダッシュボードが定期的に読んで進み具合を表示する（ARCHITECTURE 28章）。
 * DBとキャッシュから読むだけで、WordPress APIは呼ばない（D-01-05）。
 */
class SyncStatusController extends Controller
{
    public function __construct(
        protected BlogRepository $blogRepository,
        protected SyncStatusService $statusService,
    ) {
    }

    public function show(): JsonResponse
    {
        $blog = $this->blogRepository->findSelected();

        if ($blog === null) {
            return response()->json(['message' => 'ブログが選択されていません。'], 404);
        }

        return response()->json(['blog_id' => $blog->id] + $this->statusService->forBlog($blog));
    }
}
