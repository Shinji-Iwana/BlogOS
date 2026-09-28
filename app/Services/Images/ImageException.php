<?php

namespace App\Services\Images;

use RuntimeException;

/**
 * 画像の保存・生成・登録ができない（画面に理由を表示する）。D-32。
 */
class ImageException extends RuntimeException
{
}
