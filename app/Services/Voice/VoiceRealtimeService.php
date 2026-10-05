<?php

namespace App\Services\Voice;

use App\Clients\OpenAi\OpenAiClient;
use App\Clients\OpenAi\OpenAiException;
use App\Models\Blog;
use App\Models\VoiceTurn;
use App\Services\Ai\AiApiPolicy;
use App\Services\Ai\AiApiUnavailableException;

/**
 * リアルタイム会話（方式 d・e。D-58-06）。
 *
 * ・会話を始めるとき：BlogOS が OpenAI から、その場限りの鍵を受け取ってブラウザに渡す（本当の API キーは渡さない）。
 *   ブラウザは、その鍵で OpenAI と直接つながる（WebRTC。public/js/voice.js）。
 * ・AI が道具を呼んだら：ブラウザが BlogOS に頼み、BlogOS が道具（VoiceTools。方式 c と共通）を実行して結果を返す。
 *   確認つきの操作は、利用者の発言の番号（ブラウザが、話し始めるたびに数える）で、次の発言でだけ確認を受け付ける。
 * ・AI が応答するたびに：ブラウザが使用量（音声と文字のトークン数）を送り、費用を計算して voice_turns に残す。
 */
class VoiceRealtimeService
{
    /** 今の会話の番号（セッション）。確認つきの操作の「発言」の番号を、会話ごとに分けるため */
    protected const CONVERSATION_KEY = 'voice.realtime_conversation';

    public function __construct(
        protected VoiceSettings $settings,
        protected VoiceTools $tools,
        protected VoiceService $voice,
        protected AiApiPolicy $policy,
    ) {
    }

    /**
     * 会話を始める（その場限りの鍵）
     *
     * @return array{secret: string, expires_at: int|null, model: string, max_seconds: int, idle_seconds: int}
     *
     * @throws VoiceException
     */
    public function start(?Blog $blog): array
    {
        if (! $this->settings->available() || ! $this->settings->realtime()) {
            throw new VoiceException('リアルタイム会話の方式（D・E）が有効になっていません（画面「AIの設定」の「音声」）。');
        }

        $model = $this->settings->realtimeModel();
        try {
            // 会話を開いておく上限の時間、聞いて話し続けた場合の費用で、残高と上限を確かめる
            $this->policy->assertAffordable($this->maxCost($model));
        } catch (AiApiUnavailableException $e) {
            throw new VoiceException($e->getMessage());
        }

        session()->put(self::CONVERSATION_KEY, random_int(1, 2_000_000));

        try {
            $secret = $this->client()->realtimeClientSecret([
                'type'         => 'realtime',
                'model'        => $model,
                'instructions' => $this->voice->instructions($blog) . "\n・話し方：" . $this->settings->instructions(),
                'tools'        => $this->toolDefinitions($blog),
                'tool_choice'  => 'auto',
                'audio'        => [
                    'input'  => [
                        'transcription'  => ['model' => (string) config('blogos.voice.realtime.transcription_model'), 'language' => 'ja'],
                        'turn_detection' => ['type' => 'semantic_vad'],
                    ],
                    'output' => ['voice' => $this->settings->voice()],
                ],
            ]);
        } catch (OpenAiException $e) {
            throw new VoiceException('OpenAI とのやり取りに失敗しました：' . $e->getMessage(), 0, $e);
        }

        return [
            'secret'       => $secret['value'],
            'expires_at'   => $secret['expires_at'],
            'model'        => $model,
            'max_seconds'  => (int) config('blogos.voice.realtime.max_session_seconds'),
            'idle_seconds' => (int) config('blogos.voice.realtime.idle_seconds'),
        ];
    }

    /**
     * AI が呼んだ道具を実行する
     *
     * @param  int  $turn  利用者の発言の番号（会話の中で、話し始めるたびに1つ増える）
     * @return array{output: string, navigate: string|null}
     */
    public function tool(string $name, string $arguments, int $turn, ?Blog $blog, ?int $userId): array
    {
        $conversation = (int) session(self::CONVERSATION_KEY, 0);
        if ($conversation === 0) {
            return ['output' => json_encode(['error' => '会話が始まっていません。'], JSON_UNESCAPED_UNICODE), 'navigate' => null];
        }

        $decoded = json_decode($arguments, true);
        $executed = $this->tools->withContext($userId, $conversation * 10_000 + max(0, $turn))->call($name, is_array($decoded) ? $decoded : [], $blog);

        return ['output' => json_encode($executed['result'], JSON_UNESCAPED_UNICODE), 'navigate' => $executed['navigate']];
    }

    /**
     * AI の1回の応答の使用量から費用を計算して残す
     *
     * @param  array<string, mixed>  $usage  response.done の usage
     * @param  list<string>  $toolNames
     */
    public function record(array $usage, string $transcript, string $reply, array $toolNames, ?Blog $blog, ?int $userId): VoiceTurn
    {
        $model = $this->settings->realtimeModel();
        $input = (array) ($usage['input_token_details'] ?? []);
        $output = (array) ($usage['output_token_details'] ?? []);
        $cached = (array) ($input['cached_tokens_details'] ?? []);

        $audioIn = (int) ($input['audio_tokens'] ?? 0);
        $textIn = (int) ($input['text_tokens'] ?? 0);
        $cachedAudio = min($audioIn, (int) ($cached['audio_tokens'] ?? 0));
        $cachedText = min($textIn, (int) ($cached['text_tokens'] ?? max(0, (int) ($input['cached_tokens'] ?? 0) - $cachedAudio)));

        $price = $this->prices($model);
        $cost = (($audioIn - $cachedAudio) * $price['audio_input'] + $cachedAudio * $price['audio_cached_input']
            + ($textIn - $cachedText) * $price['text_input'] + $cachedText * $price['text_cached_input']
            + (int) ($output['audio_tokens'] ?? 0) * $price['audio_output'] + (int) ($output['text_tokens'] ?? 0) * $price['text_output']) / 1_000_000;

        // 会話の欄に出す文字起こしの費用（聞いた音声の長さ。音声のトークンから見積もる）
        $seconds = ($audioIn - $cachedAudio) / max(1, (int) config('blogos.voice.realtime.audio_tokens_per_second'));
        $transcriptionModel = (string) config('blogos.voice.realtime.transcription_model');
        $cost += (float) ((config('blogos.voice.prices.transcribe_per_minute')[$transcriptionModel] ?? null) ?? 0.003) * $seconds / 60;

        return VoiceTurn::create([
            'user_id'             => $userId,
            'blog_id'             => $blog?->id,
            'mode'                => $this->settings->mode(),
            'transcript'          => $transcript !== '' ? $transcript : null,
            'reply'               => $reply !== '' ? $reply : null,
            'tool_calls'          => $toolNames !== [] ? array_map(fn ($name) => ['name' => $name], $toolNames) : null,
            'audio_seconds'       => round($seconds, 2),
            'transcribe_model'    => $transcriptionModel,
            'text_model'          => $model,
            'tts_model'           => $model,
            'voice'               => $this->settings->voice(),
            'input_tokens'        => (int) ($usage['input_tokens'] ?? $audioIn + $textIn),
            'cached_input_tokens' => $cachedAudio + $cachedText,
            'output_tokens'       => (int) ($usage['output_tokens'] ?? 0),
            'estimated_cost'      => round($cost, 6),
        ]);
    }

    /**
     * リアルタイム会話に渡す道具（方式 c と同じ道具。リアルタイムの形：strict を付けない）
     */
    protected function toolDefinitions(?Blog $blog): array
    {
        return array_map(function (array $tool) {
            unset($tool['strict']);

            return $tool;
        }, $this->tools->definitions($blog !== null));
    }

    /**
     * 会話を開いておく上限の時間、聞いて話し続けた場合の費用（上限の判定。多めに見積もる）
     */
    protected function maxCost(string $model): float
    {
        $price = $this->prices($model);
        $minutes = (int) config('blogos.voice.realtime.max_session_seconds') / 60;

        // 聞く：1分600トークン、話す：1分1,200トークン、指示と道具の説明（文字）を応答ごとに 約5,000トークン×1分3回
        return $minutes * (600 * $price['audio_input'] + 1200 * $price['audio_output'] + 15000 * $price['text_input']) / 1_000_000;
    }

    /**
     * モデルの料金（モデル名に「.」が入るため、config のキーの区切りとして読まれないよう、一覧から引く）
     *
     * @return array{audio_input: float, audio_cached_input: float, audio_output: float, text_input: float, text_cached_input: float, text_output: float}
     */
    protected function prices(string $model): array
    {
        $prices = (array) config('blogos.voice.realtime.prices');

        return $prices[$model] ?? $prices['gpt-realtime-2.1'];
    }

    protected function client(): OpenAiClient
    {
        return new OpenAiClient((string) config('services.openai.key'), (string) config('services.openai.base_url'), 30);
    }
}
