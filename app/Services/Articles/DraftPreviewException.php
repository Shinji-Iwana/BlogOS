<?php

namespace App\Services\Articles;

use RuntimeException;

/**
 * 編集案のプレビューを表示できない（新規記事の変更前、WordPress側の拡張がない、通信の失敗など）。
 * メッセージは画面にそのまま表示する。
 */
class DraftPreviewException extends RuntimeException
{
}
