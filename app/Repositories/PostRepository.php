<?php

namespace App\Repositories;

use App\DTO\WordPress\PostApiDto;
use App\Models\Post;
use App\Models\PostHistory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class PostRepository
{
    protected const FIELD_MAP = [
    ];

    /**
     * id：投稿ID：123
     * date：公開日時：2026-09-13T10:00:00
     * date_gmt：公開日時GMT：2026-09-13T01:00:00
     * guid：投稿のグローバル識別子：{ "rendered": "https://si-note.com/?p=123" }
     * modified：更新日時：2026-09-13T11:00:00
     * modified_gmt：更新日時GMT：2026-09-13T02:00:00
     * slug：スラッグ：aws-s3-upload
     * status：公開状態：publish
     * type：投稿タイプ：post
     * link：記事URL：https://si-note.com/aws-s3-upload/
     * title：タイトル：AWS S3へファイルをアップロードする方法
     * content：本文：{ "rendered": "<p>...</p>", "protected": false }
     * author：投稿者ID：2
     * excerpt：抜粋：{ "rendered": "<p>...</p>", "protected": false }
     * featured_media：アイキャッチ画像ID：456
     * comment_status：コメント状態：open
     * ping_status：トラックバック状態：open
     * format：投稿フォーマット：standard
     * meta：メタ情報：{}
     * sticky：先頭固定か：false
     * template：使用テンプレート：
     * categories：カテゴリID： [12, 35]
     * tags：タグID： [5, 9]
     */
    public const FIELD_LABELS = [
    ];

    public function getAll(int $blogId): Collection
    {
        return Post::where('blog_id', $blogId)
            ->orderBy('category_id')
            ->get();
    }

    public function findById(int $id): ?Post
    {
        return Post::find($id);
    }
}
