<?php

namespace App\Http\Controllers;

use App\Repositories\BlogRepository;
use App\Services\Activity\ActivityLogService;
use Illuminate\Http\Request;

/**
 * アクティビティログ（D-77。メニューの「履歴 → アクティビティログ」）：選択中のブログと、ブログに関係のない BlogOS の作業の記録を、新しい順に並べる
 */
class ActivityLogController extends Controller
{
    /** 初めに出す期間（今日を含めた日数。D-77-07） */
    public const DEFAULT_DAYS = 7;

    public function index(Request $request, ActivityLogService $service, BlogRepository $blogRepository)
    {
        $validated = $request->validate([
            'kind' => ['nullable', 'string', 'in:' . implode(',', array_keys(ActivityLogService::KINDS))],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to'   => ['nullable', 'date_format:Y-m-d'],
        ]);

        // 期間は、日本時間の日付。初めは直近7日間（今日を含む）
        $today = now(config('blogos.display_timezone', 'Asia/Tokyo'));
        $filters = [
            'blog_id' => $blogRepository->findSelected()?->id,
            'from'    => $validated['from'] ?? $today->copy()->subDays(self::DEFAULT_DAYS - 1)->format('Y-m-d'),
            'to'      => $validated['to'] ?? $today->format('Y-m-d'),
            'kind'    => $validated['kind'] ?? '',
        ];

        // 期間を変えて、選んでいた種類の記録がなくなったときは「全て」に戻す（D-77-08）
        $kinds = $service->kinds($filters);
        if (! isset($kinds[$filters['kind']])) {
            $filters['kind'] = '';
        }

        return view('activities.index', [
            'activities' => $service->paginate($filters),
            'kinds'      => $kinds,
            'filters'    => $filters,
        ]);
    }
}
