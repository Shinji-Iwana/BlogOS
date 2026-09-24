<?php

namespace App\Repositories;

use App\DTO\WordPress\PageApiDto;
use App\Models\Page;
use App\Models\PageHistory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class PageRepository
{
    protected const FIELD_MAP = [
    ];

    /**
     * id：固定ページID：101
     * date：作成日時：2026-01-01T10:00:00
     * date_gmt：作成日時GMT：2026-01-01T01:00:00
     * guid：グローバル識別子：{...}
     * modified：更新日時：2026-09-10T12:00:00
     * modified_gmt：更新日時GMT：2026-09-10T03:00:00
     * slug：スラッグ：about
     * status：公開状態：publish
     * type：投稿タイプ：page
     * link：ページURL：https://si-note.com/about/
     * title：タイトル：このブログについて
     * content：本文：{...}
     * author：作成者ID：1
     * excerpt：抜粋：{...}
     * featured_media：アイキャッチ画像ID：0
     * comment_status：コメント状態：closed
     * ping_status：トラックバック状態：closed
     * menu_order：メニュー順：2
     * meta：メタ情報：{}
     * template：使用テンプレート：
     * parent：親ページID：0
     */
    public const FIELD_LABELS = [
    ];

    public function getAll(int $blogId): Collection
    {
        return Page::where('blog_id', $blogId)
            ->orderBy('category_id')
            ->get();
    }

    public function findById(int $id): ?Page
    {
        return Page::find($id);
    }
}
