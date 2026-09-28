<?php

namespace App\Services\Images;

use App\Enums\ImageKind;
use App\Enums\ImageSource;
use App\Enums\ImageStatus;
use App\Models\Blog;
use App\Models\Image;
use App\Repositories\ImageRepository;
use App\Support\SvgSanitizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * 画像の保存・更新・確認（D-32）。
 *
 * ファイルは BlogOS のサーバー（storage の local ディスク）に保存する。WordPress には、人が確認してから登録する。
 */
class ImageService
{
    /**
     * 受け付ける画像の形式
     */
    public const MIME_TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    public function __construct(
        protected ImageRepository $images,
    ) {
    }

    /**
     * 画像の登録（ファイルは後から。図の作成・画像の生成の前に作る）
     */
    public function create(Blog $blog, ImageKind $kind, string $title, ?string $description, ?int $userId, array $attributes = []): Image
    {
        return $this->images->create($attributes + [
            'blog_id'     => $blog->id,
            'kind'        => $kind,
            'status'      => ImageStatus::Draft,
            'title'       => mb_substr($title, 0, 255),
            'description' => $description,
            'created_by'  => $userId,
        ]);
    }

    /**
     * アップロードした画像を登録する（スクリーンショット・ChatGPT 等で作った画像）
     *
     * @throws ImageException
     */
    public function upload(Image $image, UploadedFile $file): void
    {
        $this->storeBytes($image, (string) file_get_contents($file->getRealPath()), ImageSource::Upload);
    }

    /**
     * 画像のデータを保存する（前のファイルは消す）
     *
     * @throws ImageException
     */
    public function storeBytes(Image $image, string $bytes, ImageSource $source): void
    {
        $info = @getimagesizefromstring($bytes);
        $extension = $info !== false ? (self::MIME_TYPES[$info['mime']] ?? null) : null;
        if ($extension === null) {
            throw new ImageException('PNG・JPEG・WebP の画像だけを保存できます。');
        }
        // 大きさの上限は、人がアップロードする画像だけに当てる（画像モデルが作った画像は料金がかかっているため、捨てない）
        $maxKb = (int) (config('blogos.ai.image.max_upload_kb') ?: 10240);
        if ($source === ImageSource::Upload && strlen($bytes) > $maxKb * 1024) {
            throw new ImageException("画像が大きすぎます（{$maxKb}KB まで）。");
        }

        $this->deleteFile($image);
        $name = ($image->filename ?: "image-{$image->id}") . ".{$extension}";
        $path = "images/{$image->blog_id}/{$image->id}/{$name}";
        Storage::disk('local')->put($path, $bytes);

        $image->update([
            'path'      => $path,
            'mime_type' => $info['mime'],
            'width'     => $info[0],
            'height'    => $info[1],
            'file_size' => strlen($bytes),
            'source'    => $source,
            // ファイルが変わったら、もう一度確認する
            'status'    => ImageStatus::Draft,
        ]);
    }

    /**
     * 図解の SVG を保存する（人が直した場合も）。SVG が変わったら、前の PNG は使わない（PNG にし直す）
     *
     * @throws ImageException
     */
    public function saveSvg(Image $image, string $svg): void
    {
        try {
            $clean = SvgSanitizer::sanitize($svg);
        } catch (\InvalidArgumentException $e) {
            throw new ImageException($e->getMessage());
        }

        if ($clean === $image->svg_source) {
            return;
        }

        $this->deleteFile($image);
        $image->update([
            'svg_source' => $clean,
            'path'       => null,
            'mime_type'  => null,
            'width'      => null,
            'height'     => null,
            'file_size'  => null,
            'status'     => ImageStatus::Draft,
        ]);
    }

    /**
     * alt・キャプション・ファイル名などを直す
     */
    public function updateInfo(Image $image, array $attributes): void
    {
        if (array_key_exists('filename', $attributes)) {
            $attributes['filename'] = self::filename((string) $attributes['filename']);
        }
        $renamed = array_key_exists('filename', $attributes) && $attributes['filename'] !== $image->filename;

        $image->update($attributes);

        // ファイル名を変えたら、保存したファイルの名前も合わせる（WordPress に登録するときのファイル名になる）
        if ($renamed && $image->hasFile()) {
            $extension = pathinfo($image->path, PATHINFO_EXTENSION);
            $newPath = dirname($image->path) . "/{$image->filename}.{$extension}";
            Storage::disk('local')->move($image->path, $newPath);
            $image->update(['path' => $newPath]);
        }
    }

    /**
     * 確認済みにする（ファイル・alt・ファイル名がそろっている場合だけ）
     *
     * @throws ImageException
     */
    public function markReady(Image $image): void
    {
        $missing = $image->missingForReady();
        if ($missing !== []) {
            throw new ImageException('確認済みにできません：' . implode('／', $missing));
        }

        $image->update(['status' => ImageStatus::Ready]);
    }

    public function delete(Image $image): void
    {
        $this->deleteFile($image);
        Storage::disk('local')->deleteDirectory("images/{$image->blog_id}/{$image->id}");
        $image->delete();
    }

    /**
     * WordPress に登録するファイル名（英小文字・数字・ハイフン。内容が分かる名前にする）
     */
    public static function filename(string $name): ?string
    {
        $name = Str::of(Str::ascii($name))->lower()->replaceMatches('/[^a-z0-9]+/', '-')->trim('-')->limit(80, '')->toString();

        return $name !== '' ? $name : null;
    }

    protected function deleteFile(Image $image): void
    {
        if ($image->hasFile()) {
            Storage::disk('local')->delete($image->path);
        }
    }
}
