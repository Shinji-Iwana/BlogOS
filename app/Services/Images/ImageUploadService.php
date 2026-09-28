<?php

namespace App\Services\Images;

use App\Clients\WordPress\WordPressApiClient;
use App\Enums\PushOperationType;
use App\Enums\PushResourceType;
use App\Models\Image;
use App\Models\WordPressPushOperation;
use App\Repositories\WordPressPushOperationRepository;
use App\Services\Push\PushException;
use App\Services\Push\PushOperationRunner;
use Illuminate\Support\Facades\Storage;

/**
 * 確認済みの画像を、WordPress のメディアに登録する（D-32-04）。
 *
 * 反映の共通の仕組み（PushOperationRunner）を使い、反映記録に残す。ファイルと一緒に、タイトル・alt・キャプションを送る。
 * 登録した後は、WordPress の返却値でメディアのテーブルを更新し、画像にそのメディアを結び付ける。
 */
class ImageUploadService
{
    public function __construct(
        protected PushOperationRunner $runner,
        protected WordPressPushOperationRepository $operations,
    ) {
    }

    /**
     * @throws PushException
     */
    public function upload(Image $image, ?int $userId): WordPressPushOperation
    {
        $image->loadMissing('blog');

        if (! $image->isReady()) {
            throw new PushException('確認済みの画像だけを、WordPress に登録できます。');
        }
        if ($image->media_id !== null) {
            throw new PushException('この画像は、すでに WordPress に登録しています。');
        }
        if (! $image->hasFile() || ! Storage::disk('local')->exists($image->path)) {
            throw new PushException('画像のファイルが見つかりません。');
        }
        if ($image->blog->isArchived()) {
            throw new PushException('アーカイブしたブログには登録できません。');
        }

        $fields = array_filter([
            'title'    => $image->title,
            'alt_text' => $image->alt,
            'caption'  => $image->caption,
        ], fn ($value) => filled($value));

        $operation = $this->runner->withBlogLock($image->blog, function () use ($image, $fields, $userId) {
            $operation = $this->operations->create(
                $image->blog_id,
                PushResourceType::Media,
                PushOperationType::Create,
                [],
                $fields + ['file' => basename($image->path), 'image_id' => $image->id],
                null,
                $userId
            );

            $client = WordPressApiClient::forBlog($image->blog);
            $response = $this->runner->send($operation, fn () => $client->postMultipart(
                $this->runner->endpoint(PushResourceType::Media),
                Storage::disk('local')->path($image->path),
                'file',
                $fields
            ));

            if ($response !== null) {
                $this->runner->receive($operation, $response->json(), $userId);
            }

            return $operation->fresh();
        });

        if ($operation->media_id !== null) {
            $image->update(['media_id' => $operation->media_id]);
        }

        return $operation;
    }
}
