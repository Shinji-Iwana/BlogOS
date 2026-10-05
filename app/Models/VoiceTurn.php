<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 音声のやり取りの記録（D-58）。1回の発言ごとに1行。
 */
class VoiceTurn extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tool_calls'     => 'array',
            'audio_seconds'  => 'float',
            'estimated_cost' => 'float',
        ];
    }
}
