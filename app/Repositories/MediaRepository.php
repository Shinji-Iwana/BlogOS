<?php

namespace App\Repositories;

use App\DTO\WordPress\MediaApiDto;
use App\Models\Media;
use App\Models\MediaHistory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class MediaRepository
{
    protected const FIELD_MAP = [
    ];

    /**
     * date：登録日時：2026-09-01T10:00:00
     * date_gmt：登録日時GMT：2026-09-01T01:00:00
     * guid：グローバル識別子：{...}
     * id：メディアID：456
     * link：メディアページURL：https://si-note.com/?attachment_id=456
     * modified：更新日時：2026-09-02T10:00:00
     * modified_gmt：更新日時GMT：2026-09-02T01:00:00
     * slug：スラッグ：aws-console
     * status：状態：inherit
     * type：投稿タイプ：attachment
     * title：タイトル：AWS Console
     * author：アップロードユーザーID：2
     * comment_status：コメント状態：open
     * ping_status：トラックバック状態：closed
     * meta：メタ情報：{}
     * template：テンプレート：
     * alt_text：代替テキスト：AWSコンソール画面
     * caption：キャプション：AWS S3の設定画面
     * description：説明：S3バケットの設定画面です。
     * media_type：メディア種類：image
     * mime_type：MIMEタイプ：image/png
     * media_details：画像詳細：{...}
     * post：関連投稿ID：123
     * source_url：元ファイルURL：https://si-note.com/wp-content/uploads/2026/09/aws.png
     * missing_image_sizes：不足している画像サイズ：[]
     */
    public const FIELD_LABELS = [
    ];

    public function getAll(int $blogId): Collection
    {
        return Media::where('blog_id', $blogId)
            ->orderBy('category_id')
            ->get();
    }

    public function findById(int $id): ?Media
    {
        return Media::find($id);
    }
}
