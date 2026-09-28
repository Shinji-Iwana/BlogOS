<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * API実行の料金表（1Mトークンあたりの米ドル。Web検索は1回あたり）。D-31-03。
 */
class AiPrice extends Model
{
    public const WEB_SEARCH = 'web_search';

    /**
     * モデルの料金の項目
     */
    public const MODEL_FIELDS = ['input', 'cached_input', 'cache_write', 'output', 'long_context_threshold', 'long_input_multiplier', 'long_output_multiplier'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'input'                  => 'float',
            'cached_input'           => 'float',
            'cache_write'            => 'float',
            'output'                 => 'float',
            'per_call'               => 'float',
            'long_context_threshold' => 'integer',
            'long_input_multiplier'  => 'float',
            'long_output_multiplier' => 'float',
            'checked_at'             => 'datetime',
        ];
    }
}
