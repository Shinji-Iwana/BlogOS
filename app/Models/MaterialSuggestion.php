<?php

namespace App\Models;

use App\Enums\MaterialKind;
use App\Enums\MaterialSuggestionType;
use App\Enums\SuggestionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AIが作った教材の案（登録済みの教材の情報／新しい教材の候補）。人が確認して登録する（D-30）。
 */
class MaterialSuggestion extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type'        => MaterialSuggestionType::class,
            'kind'        => MaterialKind::class,
            'data'        => 'array',
            'status'      => SuggestionStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function relatedMaterial(): BelongsTo
    {
        return $this->belongsTo(Material::class, 'related_material_id');
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(AiGeneration::class, 'ai_generation_id');
    }
}
