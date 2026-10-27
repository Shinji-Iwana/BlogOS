<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * サーバー環境の点検の結果（D-88）。対象ごとに最新の1行
 */
class EnvironmentCheck extends Model
{
    public const SECURITY = 'security';

    public const SUPPORT = 'support';

    public const OK = 'ok';

    public const INFO = 'info';

    public const UNKNOWN = 'unknown';

    public const LEVEL_LABELS = [
        self::SECURITY => 'すぐ対応',
        self::SUPPORT  => '計画して対応',
        self::OK       => '問題なし',
        self::INFO     => '参考',
        self::UNKNOWN  => '読み取れない',
    ];

    // 画面の並び
    public const TARGETS = ['php_web', 'php_cli', 'packages', 'laravel', 'database'];

    protected $fillable = ['target', 'label', 'current_version', 'latest_version', 'support_until', 'level', 'message', 'details', 'checked_at'];

    protected function casts(): array
    {
        return ['support_until' => 'date', 'details' => 'array', 'checked_at' => 'datetime'];
    }

    public function levelLabel(): string
    {
        return self::LEVEL_LABELS[$this->level] ?? $this->level;
    }
}
