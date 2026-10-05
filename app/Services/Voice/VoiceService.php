<?php

namespace App\Services\Voice;

use App\Clients\OpenAi\OpenAiClient;
use App\Clients\OpenAi\OpenAiException;
use App\Models\Blog;
use App\Models\Category;
use App\Models\VoiceTurn;
use App\Services\Ai\AiApiPolicy;
use App\Services\Ai\AiApiUnavailableException;

/**
 * 音声の1回のやり取り（方式 c：順番に処理。D-58）。
 *
 * 録音 → 聞き取り（文字にする）→ 文章の AI が道具（VoiceTools）を呼びながら返事を決める → 返事を声にする。
 * やり取りと費用は voice_turns に残し、「AIの費用と残高」の見込みと月の上限に含める。
 */
class VoiceService
{
    public function __construct(
        protected VoiceSettings $settings,
        protected VoiceTools $tools,
        protected AiApiPolicy $policy,
    ) {
    }

    /**
     * @param  list<array{role: string, content: string}>  $history  直前のやり取り（「それを開いて」のような続きの発言のため）
     * @return array{turn: VoiceTurn, transcript: string, reply: string, audio: string|null, navigate: string|null}
     *
     * @throws VoiceException
     */
    public function turn(string $audio, string $filename, float $seconds, ?Blog $blog, ?int $userId, array $history = []): array
    {
        if (! $this->settings->available()) {
            throw new VoiceException('音声の操作が有効になっていません（画面「AIの設定」の「音声」で有効にしてください）。');
        }

        $seconds = max(0.0, min($seconds, (float) config('blogos.voice.max_seconds')));
        $models = [
            'transcribe' => (string) config('blogos.voice.transcribe_model'),
            'text'       => (string) config('blogos.voice.text_model'),
            'tts'        => (string) config('blogos.voice.tts_model'),
        ];

        // 実行の前に、残高の見込みと月の上限を確かめる（1回の最大の費用：録音の上限の聞き取り＋判断＋30秒の返事）
        try {
            $this->policy->assertAffordable($this->maxCost($models));
        } catch (AiApiUnavailableException $e) {
            throw new VoiceException($e->getMessage());
        }

        $turn = VoiceTurn::create([
            'user_id'          => $userId,
            'blog_id'          => $blog?->id,
            'mode'             => $this->settings->mode(),
            'audio_seconds'    => $seconds,
            'transcribe_model' => $models['transcribe'],
            'text_model'       => $models['text'],
            'tts_model'        => $models['tts'],
            'voice'            => $this->settings->voice(),
        ]);
        $client = $this->client();

        try {
            // 1. 聞き取り（専門の言葉をヒントにする）
            $transcript = $client->transcribe($models['transcribe'], $audio, $filename, 'ja', $this->vocabulary($blog));
            $turn->update(['transcript' => $transcript, 'estimated_cost' => $this->transcribeCost($models['transcribe'], $seconds)]);

            if ($transcript === '') {
                return $this->finish($turn, $client, '', 'すみません、聞き取れませんでした。もう一度お願いします。', null);
            }

            // 2. 判断（道具を呼びながら、返事を決める）
            [$reply, $navigate] = $this->decide($client, $turn, $transcript, $blog, $history, $models['text']);

            // 3. 返事を声にする
            return $this->finish($turn, $client, $transcript, $reply, $navigate);
        } catch (OpenAiException $e) {
            $turn->update(['error' => $e->getMessage()]);

            throw new VoiceException('OpenAI とのやり取りに失敗しました：' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array{0: string, 1: string|null} 返事と、画面を移る URL
     */
    protected function decide(OpenAiClient $client, VoiceTurn $turn, string $transcript, ?Blog $blog, array $history, string $model): array
    {
        $input = [];
        foreach ($history as $message) {
            $input[] = ['role' => $message['role'] === 'assistant' ? 'assistant' : 'user', 'content' => (string) $message['content']];
        }
        $input[] = ['role' => 'user', 'content' => $transcript];

        $definitions = $this->tools->definitions($blog !== null);
        $this->tools->withContext($turn->user_id, $turn->id);
        $calls = [];
        $navigate = null;
        $usage = ['input_tokens' => 0, 'cached_input_tokens' => 0, 'output_tokens' => 0];
        $reply = '';

        for ($round = 0; $round <= (int) config('blogos.voice.max_tool_rounds'); $round++) {
            $result = $client->respondWithTools($model, $this->instructions($blog), $input, $definitions, (string) config('blogos.voice.text_effort') ?: null, 2000);
            foreach (array_keys($usage) as $key) {
                $usage[$key] += $result[$key];
            }

            if ($result['calls'] === []) {
                $reply = trim($result['text']);
                break;
            }

            // 道具を実行し、結果を渡してもう一度判断する（store: false のため、AI の出力も input に加える）
            foreach ($result['output'] as $item) {
                if (($item['type'] ?? null) === 'function_call') {
                    $input[] = ['type' => 'function_call', 'call_id' => $item['call_id'], 'name' => $item['name'], 'arguments' => $item['arguments']];
                }
            }
            foreach ($result['calls'] as $call) {
                $executed = $this->tools->call($call['name'], $call['arguments'], $blog);
                $navigate = $executed['navigate'] ?? $navigate;
                $calls[] = ['name' => $call['name'], 'arguments' => $call['arguments'], 'result' => $executed['result']];
                $input[] = ['type' => 'function_call_output', 'call_id' => $call['call_id'], 'output' => json_encode($executed['result'], JSON_UNESCAPED_UNICODE)];
            }
        }

        if ($reply === '') {
            $reply = match (true) {
                $navigate === 'close' => '画面を閉じます。',
                $navigate !== null    => '画面を開きます。',
                default               => 'すみません、うまくお答えできませんでした。',
            };
        }

        $cost = (float) ($this->policy->cost($model, $usage['input_tokens'], $usage['cached_input_tokens'], $usage['output_tokens']) ?? 0);
        $turn->update($usage + ['tool_calls' => $calls, 'estimated_cost' => $turn->estimated_cost + $cost]);

        return [$reply, $navigate];
    }

    /**
     * 返事を声にして、記録を終える
     */
    protected function finish(VoiceTurn $turn, OpenAiClient $client, string $transcript, string $reply, ?string $navigate): array
    {
        $audio = $client->speech((string) $turn->tts_model, (string) $turn->voice, $reply, $this->settings->instructions());

        $turn->update([
            'reply'          => $reply,
            'navigate_url'   => $navigate,
            'estimated_cost' => $turn->estimated_cost + $this->speechCost((string) $turn->tts_model, $reply),
        ]);

        return ['turn' => $turn->fresh(), 'transcript' => $transcript, 'reply' => $reply, 'audio' => $audio, 'navigate' => $navigate];
    }

    /**
     * 判断の AI への指示
     */
    public function instructions(?Blog $blog): string
    {
        return implode("\n", [
            'あなたは、ブログ運営の管理システム「BlogOS」の音声アシスタント「ジャービス」です。利用者は BlogOS の管理者です。',
            '・日本語で、丁寧に、短く（2文以内。数字は要点だけ）答えてください。返事はそのまま読み上げるので、記号・箇条書き・URL・英字の画面のキーは使わないでください。',
            '・画面を開く・状況・件数・残高・記事を探す依頼には、必ず道具を使ってください。数字は道具の結果だけを使い、推測しないでください。',
            '・「それを開いて」のような続きの依頼は、直前のやり取りから判断してください。',
            '・開いた画面は、トップページの横のパネルに表示され、会話は続きます。続けて別の画面を開けます。「閉じて」「戻って」と言われたら close_screen を呼んでください。',
            '・同期を始める（start_sync）と、品質診断のまとめて実行（run_quality_diagnosis）は確認が必要な操作です。道具を呼ぶと、まだ実行せず内容（summary）が返るので、その内容を伝えて「実行しますか？」と尋ねてください。'
                . '利用者が次の発言で同意したら confirm_action、断ったら cancel_action を呼びます。同じ発言の中で confirm_action を呼ばないでください。件数の指定がなければ10件にしてください。',
            '・道具にない操作（WordPress への反映・削除・承認・設定の変更・ほかの AI の実行など）は、声ではできないと伝え、関係する画面を開くかを尋ねてください。',
            '・選択中のブログ：' . ($blog?->display_name ?? 'なし（ブログが選ばれていません）'),
        ]);
    }

    /**
     * 聞き取りのヒント（BlogOS の言葉と、選択中のブログのカテゴリの名前）
     */
    protected function vocabulary(?Blog $blog): string
    {
        $words = ['BlogOS', 'ジャービス', '編集案', '品質診断', '記事改修', 'インデックス未登録', 'Search Console', 'まとめて実行', 'アフィリエイト', '内部リンク', '定期実行'];
        if ($blog !== null) {
            $words = array_merge($words, Category::where('blog_id', $blog->id)->existing()->pluck('name')->all());
        }

        return implode('、', array_unique($words));
    }

    protected function transcribeCost(string $model, float $seconds): float
    {
        return (float) ((config('blogos.voice.prices.transcribe_per_minute')[$model] ?? null) ?? 0.006) * $seconds / 60;
    }

    protected function speechCost(string $model, string $text): float
    {
        $seconds = mb_strlen($text) / max(1, (int) config('blogos.voice.chars_per_second'));

        return (float) ((config('blogos.voice.prices.tts_per_minute')[$model] ?? null) ?? 0.015) * $seconds / 60;
    }

    /**
     * 1回の最大の費用（上限の判定に使う。多めに見積もる）
     */
    protected function maxCost(array $models): float
    {
        $text = (float) ($this->policy->cost($models['text'], 8000 * ((int) config('blogos.voice.max_tool_rounds') + 1), 0, 2000) ?? 0.01);

        return $this->transcribeCost($models['transcribe'], (float) config('blogos.voice.max_seconds')) + $text + (float) ((config('blogos.voice.prices.tts_per_minute')[$models['tts']] ?? null) ?? 0.015) * 0.5;
    }

    protected function client(): OpenAiClient
    {
        return new OpenAiClient((string) config('services.openai.key'), (string) config('services.openai.base_url'), 60);
    }
}
