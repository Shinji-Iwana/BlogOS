<?php

namespace App\Services\Voice;

use App\Models\SystemSetting;
use App\Services\Ai\AiApiPolicy;

/**
 * 音声の操作の設定（D-58）。BlogOS 全体の設定（system_settings の voice.*）。
 *
 * メニューの「AI → 音声操作」のポップアップ（voice/settings-modal。D-61）で変える。行がなければ config/blogos.php の voice の初期値。
 */
class VoiceSettings
{
    /**
     * 方式。c：順番に処理。d・e：リアルタイム会話（mini・標準。D-58-06）
     *
     * 画面では A・B・C と呼ぶ（検討のときの案の記号 C・D・E が画面に出ると、A・B がないように見えるため。D-60-03）。
     * 保存する値（system_settings の voice.mode・voice_turns.mode）は c・d・e のまま。
     */
    public const MODES = [
        'c' => 'A：聞き取りも返事も OpenAI（順番に処理。1往復ずつ、発言のたびにボタンを押す。1回 約0.5円）',
        'd' => 'B：リアルタイム会話（mini。会話を開いている間は続けて話せる。1回 約1円）',
        'e' => 'C：リアルタイム会話（標準。より複雑な指示に強い。1回 約2〜4円）',
    ];

    /** 使える方式 */
    public const READY_MODES = ['c', 'd', 'e'];

    /**
     * リアルタイム会話の方式か
     */
    public function realtime(): bool
    {
        return in_array($this->mode(), ['d', 'e'], true);
    }

    /**
     * リアルタイム会話のモデル（方式 d・e）
     */
    public function realtimeModel(): string
    {
        return (string) config('blogos.voice.realtime.models.' . $this->mode(), config('blogos.voice.realtime.models.d'));
    }

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
