<?php

namespace App\Services\Voice;

use RuntimeException;

/**
 * 音声のやり取りができなかった（無効・残高・OpenAI の失敗など）。メッセージは画面にそのまま出す（D-58）
 */
class VoiceException extends RuntimeException
{
}
