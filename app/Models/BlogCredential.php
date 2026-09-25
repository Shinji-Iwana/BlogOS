<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ブログごとのWordPress認証情報（blog_credentials）。BLOGOS_DATABASE.md 5-3、D-03-02。
 *
 * secret（Application Password）は APP_KEY で暗号化して保存する。
 * 画面・ログ・配列化（toArray / JSON）に出さないよう $hidden に含める。
 */
class BlogCredential extends Model
{
    public const AUTH_TYPE_APPLICATION_PASSWORD = 'application_password';

    protected $fillable = [
        'blog_id',
        'auth_type',
        'username',
        'secret',
        'verified_at',
        'last_failed_at',
        'last_error',
    ];

    protected $hidden = [
        'secret',
    ];

    protected function casts(): array
    {
        return [
            'secret'         => 'encrypted',
            'verified_at'    => 'datetime',
            'last_failed_at' => 'datetime',
        ];
    }

    public function blog(): BelongsTo
    {
        return $this->belongsTo(Blog::class);
    }
}
