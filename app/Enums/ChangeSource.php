<?php

namespace App\Enums;

/**
 * 履歴の変更元（source）。BLOGOS_DATABASE.md 8-3、BLOGOS_DECISIONS.md D-02-07。
 */
enum ChangeSource: string
{
    // ブログ登録時の初回取得
    case WpInitialSync = 'wp_initial_sync';

    // 同期で取り込んだWordPress側の変更
    case WpSync = 'wp_sync';

    // BlogOSから反映し、WordPressが返した結果
    case BlogosPush = 'blogos_push';

    // 反映記録からの回復処理
    case BlogosRecovery = 'blogos_recovery';

    // BlogOS画面での手動編集
    case BlogosManual = 'blogos_manual';

    // BlogOSのAI機能による生成
    case Ai = 'ai';

    // 移行処理・バッチなどの内部処理
    case System = 'system';
}
