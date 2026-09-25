<?php

namespace App\Services\Sync;

/**
 * WordPressから取得した結果。
 */
class FetchResult
{
    /**
     * @param array<int, string>   $allKeys WordPress側に存在するすべてのキー（削除の判定に使う。取得が完全な場合だけ）
     * @param array<string, array> $items   詳細まで取得した項目（キー => APIの項目）
     */
    public function __construct(
        public readonly array $allKeys,
        public readonly array $items,
    ) {
    }
}
