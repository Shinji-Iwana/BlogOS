<?php

namespace App\Services\Blogs;

use App\Clients\WordPress\WordPressApiClient;
use App\Models\Blog;
use App\Repositories\BlogCredentialRepository;

/**
 * ブログごとの認証情報の更新と接続確認（D-03-02、D-03-03）。
 */
class BlogCredentialService
{
    public function __construct(
        protected WordPressSiteInspector $inspector,
        protected BlogCredentialRepository $blogCredentialRepository
    ) {
    }

    /**
     * 新しい認証情報で接続を確認し、成功した場合だけ保存する（上書き）。
     */
    public function update(Blog $blog, string $username, string $applicationPassword): array
    {
        $client = new WordPressApiClient($blog->home, $username, $applicationPassword);

        $wordpressUser = $this->inspector->verifyCredentials($client);

        $this->blogCredentialRepository->save($blog, $username, $applicationPassword);

        return $wordpressUser;
    }

    /**
     * 保存済みの認証情報で接続を確認し、結果を記録する。
     */
    public function verify(Blog $blog): array
    {
        $credential = $this->blogCredentialRepository->findForBlog($blog->id);

        if ($credential === null) {
            throw new BlogInspectionException('認証情報が登録されていません。');
        }

        try {
            $wordpressUser = $this->inspector->verifyCredentials(WordPressApiClient::forBlog($blog));
        } catch (BlogInspectionException $e) {
            $this->blogCredentialRepository->markFailed($credential, $e->getMessage());

            throw $e;
        }

        $this->blogCredentialRepository->markVerified($credential);

        return $wordpressUser;
    }
}
