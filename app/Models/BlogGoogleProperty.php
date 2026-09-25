<?php

namespace App\Models;

use App\Enums\GoogleService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ブログごとのGoogleの対応先（GA4のプロパティ、Search Consoleのサイト、AdSenseのアカウント）。BLOGOS_DATABASE.md 12-1。
 */
class BlogGoogleProperty extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'service' => GoogleService::class,
        ];
    }

    public function blog(): BelongsTo
    {
        return $this->belongsTo(Blog::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(GoogleAccount::class, 'google_account_id');
    }
}
