<?php

namespace App\Repositories;

use App\Models\Blog;
use App\Models\BlogCredential;

class BlogCredentialRepository
{
    public function findForBlog(int $blogId): ?BlogCredential
    {
        return BlogCredential::where('blog_id', $blogId)->first();
    }

    /**
     * 認証情報を保存する（上書き）。secret は Model の encrypted キャストで暗号化される（D-03-02）。
     */
    public function save(Blog $blog, string $username, string $secret, ?bool $connectorExtension): BlogCredential
    {
        return BlogCredential::updateOrCreate(
            ['blog_id' => $blog->id],
            [
                'auth_type'           => BlogCredential::AUTH_TYPE_APPLICATION_PASSWORD,
                'username'            => $username,
                'secret'              => $secret,
                'verified_at'         => now(),
                'connector_extension' => $connectorExtension,
                'last_failed_at'      => null,
                'last_error'          => null,
            ]
        );
    }

    /**
     * 接続確認の成功と、WordPress側の拡張の判定結果を記録する
     */
    public function markVerified(BlogCredential $credential, ?bool $connectorExtension): void
    {
        $credential->verified_at = now();
        $credential->connector_extension = $connectorExtension;
        $credential->save();
    }

    /**
     * 接続確認の失敗を記録する。$error に認証情報を含めてはならない。
     */
    public function markFailed(BlogCredential $credential, string $error): void
    {
        $credential->last_failed_at = now();
        $credential->last_error = $error;
        $credential->save();
    }
}
