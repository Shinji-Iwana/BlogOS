<?php

namespace App\Models;

use App\Enums\ImageKind;
use App\Enums\ImageSource;
use App\Enums\ImageStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 記事で使う画像（図解・イラスト・アイキャッチ・スクリーンショット）。D-32。
 *
 * ファイルは BlogOS のサーバー（storage）に保存し、人が確認してから WordPress のメディアに登録する。
 */
class Image extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'kind'   => ImageKind::class,
            'status' => ImageStatus::class,
            'source' => ImageSource::class,
        ];
    }

    public function blog(): BelongsTo
    {
        return $this->belongsTo(Blog::class);
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    /**
     * 比べる元の画像（別の形式で作った場合）
     */
    public function variantOf(): BelongsTo
    {
        return $this->belongsTo(Image::class, 'variant_of_image_id');
    }

    /**
     * この画像を元に、別の形式で作った画像
     */
    public function variants(): HasMany
    {
        return $this->hasMany(Image::class, 'variant_of_image_id');
    }

    public function generations(): HasMany
    {
        return $this->hasMany(AiGeneration::class);
    }

    public function hasFile(): bool
    {
        return filled($this->path);
    }

    public function isReady(): bool
    {
        return $this->status === ImageStatus::Ready;
    }

    /**
     * 確認済みにできない理由（なければ空）
     *
     * @return list<string>
     */
    public function missingForReady(): array
    {
        return array_values(array_filter([
            ! $this->hasFile() ? ($this->kind === ImageKind::Diagram && filled($this->svg_source) ? 'PNG にしていません（「PNG にして保存する」を押してください）' : '画像のファイルがありません') : null,
            blank($this->alt) ? 'alt（画像の代わりの文章）がありません' : null,
            blank($this->filename) ? 'ファイル名がありません' : null,
        ]));
    }
}
