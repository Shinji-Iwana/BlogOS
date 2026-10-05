<?php

namespace App\Services\Voice;

use App\Models\AiPrice;
use App\Repositories\AiPriceRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

/**
 * 音声の操作のモデルの料金（米ドル。D-58・D-68-02）。
 *
 * 料金表（ai_prices。毎日の公式のページとの照合で更新）にあればその値、なければ設定（config/blogos.php の voice）の値。
 * ・聞き取り：1分あたり（ai_prices.per_call に入れる）
 * ・返事の声：文字の入力・音声の出力（100万トークンあたり。ai_prices.input・audio_output）。1分あたりは、音声の出力 × 1分の音声のトークン数
 * ・リアルタイム会話：音声と文字の、入力・キャッシュ済みの入力・出力（100万トークンあたり。ai_prices.audio_*・input・cached_input・output）
 */
class VoicePrices
{
    protected ?Collection $stored = null;

    public function __construct(
        protected AiPriceRepository $prices,
    ) {
    }

    /**
     * 照合する・料金表に出す、聞き取りのモデル
     *
     * @return list<string>
     */
    public static function transcribeModels(): array
    {
        return array_keys((array) config('blogos.voice.prices.transcribe_per_minute'));
    }

    /**
     * @return list<string>
     */
    public static function ttsModels(): array
    {
        return array_keys((array) config('blogos.voice.prices.tts'));
    }

    /**
     * @return list<string>
     */
    public static function realtimeModels(): array
    {
        return array_keys((array) config('blogos.voice.realtime.prices'));
    }

    public static function isVoiceModel(string $key): bool
    {
        return in_array($key, array_merge(self::transcribeModels(), self::ttsModels(), self::realtimeModels()), true);
    }

    public function transcribePerMinute(string $model): float
    {
        return (float) ($this->stored($model)?->per_call ?? ((array) config('blogos.voice.prices.transcribe_per_minute'))[$model] ?? 0.006);
    }

    /**
     * @return array{text_input: float, audio_output: float, per_minute: float}
     */
    public function tts(string $model): array
    {
        $config = ((array) config('blogos.voice.prices.tts'))[$model] ?? ['text_input' => 0.60, 'audio_output' => 12.00];
        $stored = $this->stored($model);
        $audioOutput = (float) ($stored?->audio_output ?? $config['audio_output']);

        return [
            'text_input'   => (float) ($stored?->input ?? $config['text_input']),
            'audio_output' => $audioOutput,
            'per_minute'   => $audioOutput * (int) config('blogos.voice.prices.tts_audio_tokens_per_minute') / 1_000_000,
        ];
    }

    public function ttsPerMinute(string $model): float
    {
        return $this->tts($model)['per_minute'];
    }

    /**
     * @return array{audio_input: float, audio_cached_input: float, audio_output: float, text_input: float, text_cached_input: float, text_output: float}
     */
    public function realtime(string $model): array
    {
        $all = (array) config('blogos.voice.realtime.prices');
        // モデル名に「.」が入るため、config のキーの区切りとして読まれないよう、一覧から引く
        $config = $all[$model] ?? $all['gpt-realtime-2.1'];
        $stored = $this->stored($model);

        return [
            'audio_input'        => (float) ($stored?->audio_input ?? $config['audio_input']),
            'audio_cached_input' => (float) ($stored?->audio_cached_input ?? $config['audio_cached_input']),
            'audio_output'       => (float) ($stored?->audio_output ?? $config['audio_output']),
            'text_input'         => (float) ($stored?->input ?? $config['text_input']),
            'text_cached_input'  => (float) ($stored?->cached_input ?? $config['text_cached_input']),
            'text_output'        => (float) ($stored?->output ?? $config['text_output']),
        ];
    }

    /**
     * 公式のページと照合した日時
     */
    public function checkedAt(string $model): ?\DateTimeInterface
    {
        return $this->stored($model)?->checked_at;
    }

    protected function stored(string $model): ?AiPrice
    {
        if ($this->stored === null) {
            try {
                $this->stored = $this->prices->all();
            } catch (QueryException) {
                // 料金表のテーブルを作る前（Migrationの前）は、設定の値を使う
                $this->stored = new Collection();
            }
        }

        return $this->stored->get($model);
    }
}
