<?php

namespace App\Models;

use App\Models\Histories\CustomTermHistory;

/**
 * カスタムタクソノミーの項目（BLOGOS_DATABASE.md 6-8）。同期と閲覧だけの対象。
 */
class CustomTerm extends WordPressRecord
{
    public static function historyClass(): string
    {
        return CustomTermHistory::class;
    }

    public static function historyForeignKey(): string
    {
        return 'custom_term_id';
    }
}
