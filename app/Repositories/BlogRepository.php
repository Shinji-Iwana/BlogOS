<?php

namespace App\Repositories;

use App\DTO\WordPress\BlogApiDto;
use App\Models\Blog;
use App\Models\BlogHistory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BlogRepository
{
    /**
     * APIの項目名（BlogApiDto の data のキー）→ blogs テーブルの列名
     *
     * キーは WordPress API Root が返す項目名（スネークケース）に合わせる。
     */
    protected const FIELD_MAP = [
        'name'            => 'name',
        'description'     => 'description',
        'url'             => 'url',
        'gmt_offset'      => 'gmt_offset',
        'timezone_string' => 'timezone',
    ];

    /**
     * name：サイト名：SI-Note
     * description：サイト説明：IT初心者向けのブログ
     * url：サイトURL：https://si-note.com
     * home：ホームURL：https://si-note.com
     * gmt_offset：GMTとの差：9
     * timezone_string：タイムゾーン：Asia/Tokyo
     * namespaces：利用可能なAPI namespace：["oembed/1.0","wp/v2"]
     * routes：利用可能なAPIルート：{"/wp/v2/posts": {...}, ...}
     * authentication：認証方式：{...}
     */
    public const FIELD_LABELS = [
        'name'           => 'サイト名',
        'description'    => '説明',
        'url'            => 'URL',
        'home'           => 'Home',
        'gmt_offset'     => 'GMTオフセット',
        'timezone'       => 'タイムゾーン',
        'is_selected'    => '選択中',
        'last_synced_at' => '最終同期日時',
    ];

    public function getAll(): Collection
    {
        return Blog::orderBy('id')->get();
    }

    public function findById(int $id): ?Blog
    {
        return Blog::find($id);
    }

    public function findByHome(string $home): ?Blog
    {
        return Blog::where('home', $home)->first();
    }

    /**
     * 選択中のブログを取得する。
     *
     * 選択中のブログがない場合は null を返す。
     * 画面の表示だけでDBを書き換えないよう、最初のブログを自動で選択することはしない
     * （BLOGOS_CURRENT_STATUS.md 3-2）。
     */
    public function findSelected(): ?Blog
    {
        return Blog::where('is_selected', true)
            ->orderBy('id')
            ->first();
    }

    public function createFromApiData(BlogApiDto $data, string $source): Blog
    {
        return DB::transaction(function () use ($data, $source) {
            $apiData = $data->data;

            // 呼び出し元での重複チェックとは別に、DB処理側でも担保しておく。
            if ($this->findByHome($apiData['home']) !== null) {
                throw new RuntimeException(
                    "指定されたhome（{$apiData['home']}）は既に登録されています。"
                );
            }

            Blog::query()->update([
                'is_selected' => false,
            ]);

            $blog = Blog::create([
                'name'        => $apiData['name'],
                'description' => $apiData['description'],
                'url'         => $apiData['url'],
                'home'        => $apiData['home'],
                'gmt_offset'  => $apiData['gmt_offset'],
                'timezone'    => $apiData['timezone_string'],
                'is_selected' => true,
            ]);

            foreach (self::FIELD_MAP as $dbField) {
                BlogHistory::create([
                    'blog_id'   => $blog->id,
                    'field'     => $dbField,
                    'old_value' => null,
                    'new_value' => (string) $blog->{$dbField},
                    'source'    => $source,
                ]);
            }

            return $blog;
        });
    }

    public function updateSelected(int $blogId): Blog
    {
        return DB::transaction(function () use ($blogId) {
            $blog = Blog::find($blogId);

            if ($blog === null) {
                throw new RuntimeException(
                    '指定されたブログが存在しません。'
                );
            }

            Blog::query()->update([
                'is_selected' => false,
            ]);

            $blog->is_selected = true;
            $blog->save();

            return $blog->fresh();
        });
    }

    public function diff(Blog $blog, BlogApiDto $data): array
    {
        $diff = [];

        $apiData = $data->data;

        foreach (self::FIELD_MAP as $apiField => $dbField) {
            $oldValue = (string) $blog->{$dbField};
            $newValue = (string) $apiData[$apiField];

            if ($oldValue !== $newValue) {
                $diff[$dbField] = [
                    'old' => $oldValue,
                    'new' => $newValue,
                ];
            }
        }

        return $diff;
    }

    public function updateWithHistory(Blog $blog, array $diff, string $source): void
    {
        if (empty($diff)) {
            return;
        }

        DB::transaction(function () use ($blog, $diff, $source) {
            foreach ($diff as $field => $values) {
                BlogHistory::create([
                    'blog_id'   => $blog->id,
                    'field'     => $field,
                    'old_value' => $values['old'],
                    'new_value' => $values['new'],
                    'source'    => $source,
                ]);

                $blog->{$field} = $values['new'];
            }

            $blog->save();
        });
    }

    /**
     * APIとの同期処理が成功したことを記録する。
     *
     * last_synced_atはブログ自体の情報変更ではなく
     * BlogOS内部の同期状態管理用の項目のため、履歴（BlogHistory）は残さない。
     * diffの有無に関わらず、API取得に成功した時点で呼び出す想定。
     */
    public function touchSynced(Blog $blog): void
    {
        $blog->last_synced_at = now();
        $blog->save();
    }
}
