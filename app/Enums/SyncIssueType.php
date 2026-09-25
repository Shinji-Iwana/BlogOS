<?php

namespace App\Enums;

/**
 * 人の対応が必要な問題の種類（sync_issues.issue_type）。BLOGOS_DATABASE.md 10-3。
 */
enum SyncIssueType: string
{
    // WordPress APIの取得エラー
    case FetchError = 'fetch_error';

    // 参照先（投稿者・親・アイキャッチ画像など）がDBに見つからない
    case UnresolvedReference = 'unresolved_reference';

    // 作業中の編集案がある記事で、WordPress側の変更を検出した（段階4で使う）
    case Conflict = 'conflict';

    // WordPress側での完全削除を検知した（D-09-02）
    case DeletedDetected = 'deleted_detected';

    // 一度に大量のデータが消えたため、削除として扱わなかった（D-09-03）
    case MassDeletionSuspected = 'mass_deletion_suspected';

    // WordPressのサイトアドレス（home）が、登録されているホームURLと異なる（D-13-01）
    case HomeChanged = 'home_changed';

    // 反映の結果が不明（段階4で使う）
    case PushUnknown = 'push_unknown';

    public function label(): string
    {
        return match ($this) {
            self::FetchError            => '取得エラー',
            self::UnresolvedReference   => '参照先が見つからない',
            self::Conflict              => '競合',
            self::DeletedDetected       => '削除を検知',
            self::MassDeletionSuspected => '大量の消失（削除として扱っていない）',
            self::HomeChanged           => 'サイトアドレスの変更',
            self::PushUnknown           => '反映結果が不明',
        };
    }
}
