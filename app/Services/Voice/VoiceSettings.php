<?php

namespace App\Services\Voice;

use App\Models\SystemSetting;
use App\Services\Ai\AiApiPolicy;

/**
 * 音声の操作の設定（D-58）。BlogOS 全体の設定（system_settings の voice.*）。
 *
 * 画面「AIの設定」の「音声」で変える。行がなければ config/blogos.php の voice の初期値。
 */
class VoiceSettings
{
    /**
     * 方式。c：順番に処理（段階1）。d・e：リアルタイム会話（mini・標準。段階3で加える）
     */
    public const MODES = [
        'c' => 'C：聞き取りも返事も OpenAI（順番に処理。1往復ずつ）',
        'd' => 'D：リアルタイム会話（mini）※準備中',
        'e' => 'E：リアルタイム会話（標準）※準備中',
    ];

    /** 今使える方式（D・E は段階3で加える） */
    public const READY_MODES = ['c'];

    public function __construct(
        protected AiApiPolicy $policy,
    ) {
    }

    /**
     * 有効にしていて、OpenAI の API キーがあるか（ヘッダーのマイクのボタンを出すか）
     */
    public function available(): bool
    {
        return $this->enabled() && $this->policy->isConfigured();
    }

    public function enabled(): bool
    {
        return $this->value('enabled') === '1';
    }

    public function mode(): string
    {
        $mode = (string) $this->value('mode');

        return in_array($mode, self::READY_MODES, true) ? $mode : 'c';
    }

    public function voice(): string
    {
        $voice = (string) $this->value('voice');

        return in_array($voice, config('blogos.voice.voices'), true) ? $voice : (string) config('blogos.voice.default_voice');
    }

    public function instructions(): string
    {
        return (string) ($this->value('instructions') ?? config('blogos.voice.default_instructions'));
    }

    /**
     * @param  array{enabled: bool, mode: string, voice: string, instructions: string}  $values
     */
    public function save(array $values, ?int $userId): void
    {
        SystemSetting::put('voice.enabled', $values['enabled'] ? '1' : '0', $userId);
        SystemSetting::put('voice.mode', $values['mode'], $userId);
        SystemSetting::put('voice.voice', $values['voice'], $userId);
        SystemSetting::put('voice.instructions', trim($values['instructions']), $userId);
    }

    protected function value(string $key): ?string
    {
        return SystemSetting::value('voice.' . $key);
    }
}
