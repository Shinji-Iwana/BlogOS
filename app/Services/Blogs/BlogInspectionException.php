<?php

namespace App\Services\Blogs;

use RuntimeException;

/**
 * ブログの確認（登録・接続確認）で問題が見つかったことを表す。
 * メッセージは利用者にそのまま表示する。認証情報を含めてはならない。
 */
class BlogInspectionException extends RuntimeException
{
}
