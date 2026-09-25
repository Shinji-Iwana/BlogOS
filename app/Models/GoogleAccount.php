<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Googleアカウントごとの OAuth トークン（BLOGOS_DATABASE.md 12-1、D-03-05、D-21-01）。
 *
 * トークンは encrypted キャストで暗号化して保存し、配列化・JSON化の対象から外す。画面には表示しない。
 */
class GoogleAccount extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'access_token'      => 'encrypted',
            'refresh_token'     => 'encrypted',
            'token_expires_at'  => 'datetime',
            'scopes'            => 'array',
            'last_refreshed_at' => 'datetime',
        ];
    }

    public function properties(): HasMany
    {
        return $this->hasMany(BlogGoogleProperty::class);
    }
}
