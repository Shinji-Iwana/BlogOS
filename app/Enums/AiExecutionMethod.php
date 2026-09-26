<?php

namespace App\Enums;

/**
 * AIの実行方式（ai_generations.execution_method）。D-07-06。
 */
enum AiExecutionMethod: string
{
    case Manual = 'manual';
    case Api = 'api';

    public function label(): string
    {
        return match ($this) {
            self::Manual => '手動実行',
            self::Api    => 'API実行',
        };
    }
}
