<?php

namespace App\Models\Histories;

use App\Enums\ChangeSource;
use Illuminate\Database\Eloquent\Model;

/**
 * WordPress由来のテーブルの履歴に共通の定義（履歴の共通の構造。BLOGOS_DATABASE.md 8-2）。
 *
 * 履歴は追加するだけで更新しないため、created_at / updated_at は持たず changed_at に日時を保存する。
 */
abstract class WordPressHistory extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'source'     => ChangeSource::class,
            'changed_at' => 'datetime',
        ];
    }
}
