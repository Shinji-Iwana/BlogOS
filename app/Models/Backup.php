<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * 月1回のバックアップの1回分（D-89）。作り方は App\Services\BackupService
 */
class Backup extends Model
{
    public const RUNNING = 'running';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const STATUS_LABELS = [
        self::RUNNING   => '作成中',
        self::SUCCEEDED => '成功',
        self::FAILED    => '失敗',
    ];

    protected $fillable = ['name', 'status', 'files', 'total_bytes', 'message', 'started_at', 'finished_at', 'announced_at', 'downloaded_at'];

    protected function casts(): array
    {
        return [
            'files'         => 'array',
            'started_at'    => 'datetime',
            'finished_at'   => 'datetime',
            'announced_at'  => 'datetime',
            'downloaded_at' => 'datetime',
        ];
    }

    public function scopeSucceeded(Builder $query): Builder
    {
        return $query->where('status', self::SUCCEEDED);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /**
     * サーバーの中のフォルダ
     */
    public function directory(): string
    {
        return rtrim((string) config('blogos.backup.path'), '/\\') . DIRECTORY_SEPARATOR . $this->name;
    }

    /**
     * @return array{key: string, file: string, label: string, bytes: int, encrypted: bool, downloaded_at: string|null}|null
     */
    public function file(string $key): ?array
    {
        return collect($this->files ?? [])->firstWhere('key', $key);
    }

    /**
     * まだダウンロードしていないファイルの名前
     *
     * @return list<string>
     */
    public function notDownloaded(): array
    {
        return collect($this->files ?? [])->whereNull('downloaded_at')->pluck('label')->values()->all();
    }
}
