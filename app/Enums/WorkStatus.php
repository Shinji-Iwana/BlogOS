<?php

namespace App\Enums;

/**
 * 記事の作業状態（article_managements.work_status）。D-15-10。
 */
enum WorkStatus: string
{
    case NotStarted = 'not_started';
    case NeedsRevision = 'needs_revision';
    case InProgress = 'in_progress';
    case InReview = 'in_review';
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::NotStarted    => '未着手',
            self::NeedsRevision => '改修対象',
            self::InProgress    => '改修中',
            self::InReview      => '確認待ち',
            self::Done          => '完了',
        };
    }
}
