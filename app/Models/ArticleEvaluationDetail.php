<?php

namespace App\Models;

use App\Enums\Judgment;
use Illuminate\Database\Eloquent\Model;

/**
 * 評価項目・必須条件ごとの判定（BLOGOS_DATABASE.md 9-5）。
 */
class ArticleEvaluationDetail extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'judgment' => Judgment::class,
            'points'   => 'float',
        ];
    }
}
