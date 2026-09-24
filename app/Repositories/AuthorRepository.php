<?php

namespace App\Repositories;

use App\DTO\WordPress\AuthorApiDto;
use App\Models\Author;
use App\Models\AuthorHistory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class AuthorRepository
{
    protected const FIELD_MAP = [
    ];

    /**
     * id：ユーザーID：2
     * username：ログインユーザー名：admin
     * name：表示名：山田太郎
     * first_name：名：太郎
     * last_name：姓：山田
     * email：メールアドレス：admin@example.com
     * url：プロフィールURL：https://example.com/
     * description：プロフィール説明：AWSやPythonの記事を書いています。
     * link：投稿者ページ：https://si-note.com/author/admin/
     * locale：ロケール：ja
     * nickname：ニックネーム：taro
     * slug：ユーザースラッグ：admin
     * registered_date：登録日時：2026-01-01T10:00:00
     * roles：権限ロール：["administrator"]
     * capabilities：権限一覧：{"edit_posts":true,...}
     * extra_capabilities：追加権限：{}
     * avatar_urls：アバターURL：{"24":"...","48":"...","96":"..."}
     * meta：メタ情報：{}
     */
    public const FIELD_LABELS = [
        'blog_id'         => 'ブログID',
        'author_id'       => '投稿者ID',
        'username'        => '',
        'name'            => '',
        'first_name'      => '',
        'last_name'       => '',
        'email'           => '',
        'url'             => '',
        'description'     => '',
        'link'            => '',
        'locale'          => '',
        'nickname'        => '',
        'slug'            => '',
        'registered_date' => '',
        'roles'           => '',
        'avatar_urls'     => '',
        'last_synced_at'  => '',
    ];

    public function getAll(int $blogId): Collection
    {
        return Author::where('blog_id', $blogId)
            ->orderBy('category_id')
            ->get();
    }

    public function findById(int $id): ?Author
    {
        return Author::find($id);
    }
}
