<?php

namespace App\Clients\OpenAi;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * OpenAI API（Responses API）の呼び出し（D-24）。
 *
 * 1回の指示文を送り、回答の文章とトークン数を返す。会話は続けないため、OpenAI側には保存させない（store: false）。
 * 失敗（通信エラー・HTTPエラー・途中で打ち切られた応答）は OpenAiException にする。APIキーはログ・例外に含めない。
 */
class OpenAiClient
{
    /**
     * 1分あたりの上限に達したときに、送り直すために待つ時間の合計の上限（秒）。Job の時間切れは、この分を足して決める
     */
    public const MAX_RATE_LIMIT_WAIT = 180;

    public function __construct(
        protected string $apiKey,
        protected string $baseUrl = 'https://api.openai.com/v1',
        protected int $timeout = 900,
    ) {
    }

    /**
     * @param array{tool: string, max_calls: int}|null $webSearch Web検索を使う場合の設定（教材の調査。D-30）
     * @return array{text: string, model: string, response_id: string|null, input_tokens: int, cached_input_tokens: int, output_tokens: int, reasoning_tokens: int, web_search_calls: int}
     *
     * @throws OpenAiException
     */
    public function respond(string $model, string $input, ?string $effort, int $maxOutputTokens, ?array $webSearch = null): array
    {
        $body = array_filter([
            'model'             => $model,
            'input'             => $input,
            'reasoning'         => $effort !== null ? ['effort' => $effort] : null,
            'max_output_tokens' => $maxOutputTokens,
            'tools'             => $webSearch !== null ? [['type' => $webSearch['tool']]] : null,
            'max_tool_calls'    => $webSearch !== null ? $webSearch['max_calls'] : null,
            'store'             => false,
        ], fn ($value) => $value !== null);

        $data = $this->post('/responses', $body);

        $status = $data['status'] ?? null;
        if ($status === 'incomplete') {
            $reason = $data['incomplete_details']['reason'] ?? '不明';
            $hint = $reason === 'max_output_tokens' ? '（出力トークンの上限に達しました。推論の深さを下げるか、上限 BLOGOS_AI_MAX_OUTPUT_TOKENS を上げてください）' : '';

            throw new OpenAiException("回答が途中で打ち切られました：{$reason}{$hint}", 200, null, $this->usage($data));
        }
        if ($status !== 'completed') {
            $message = $data['error']['message'] ?? (string) $status;

            throw new OpenAiException("回答を得られませんでした：{$message}", 200, null, $this->usage($data));
        }

        $text = '';
        foreach ((array) ($data['output'] ?? []) as $item) {
            if (($item['type'] ?? null) !== 'message') {
                continue;
            }
            foreach ((array) ($item['content'] ?? []) as $content) {
                if (($content['type'] ?? null) === 'output_text') {
                    $text .= (string) ($content['text'] ?? '');
                }
            }
        }

        if (trim($text) === '') {
            throw new OpenAiException('回答が空でした。', 200, null, $this->usage($data));
        }

        return ['text' => $text, 'model' => (string) ($data['model'] ?? $model), 'response_id' => $data['id'] ?? null] + $this->usage($data);
    }

    /**
     * 画像モデルで画像を1枚作る（Images API。D-32）
     *
     * @return array{bytes: string, model: string, input_tokens: int, text_input_tokens: int, image_input_tokens: int, output_tokens: int}
     *
     * @throws OpenAiException
     */
    public function generateImage(string $model, string $prompt, string $size, string $quality, string $format = 'png'): array
    {
        $data = $this->post('/images/generations', [
            'model'         => $model,
            'prompt'        => $prompt,
            'size'          => $size,
            'quality'       => $quality,
            'output_format' => $format,
            'n'             => 1,
        ]);

        $usage = (array) ($data['usage'] ?? []);
        $details = (array) ($usage['input_tokens_details'] ?? []);
        $result = [
            'model'              => $model,
            'input_tokens'       => (int) ($usage['input_tokens'] ?? 0),
            'text_input_tokens'  => (int) ($details['text_tokens'] ?? ($usage['input_tokens'] ?? 0)),
            'image_input_tokens' => (int) ($details['image_tokens'] ?? 0),
            'output_tokens'      => (int) ($usage['output_tokens'] ?? 0),
        ];

        $base64 = $data['data'][0]['b64_json'] ?? null;
        $bytes = is_string($base64) ? base64_decode($base64, true) : false;
        if ($bytes === false || $bytes === '') {
            throw new OpenAiException('画像が返ってきませんでした。', 200, null, ['input_tokens' => $result['input_tokens'], 'cached_input_tokens' => 0, 'output_tokens' => $result['output_tokens'], 'reasoning_tokens' => 0]);
        }

        return ['bytes' => $bytes] + $result;
    }

    /**
     * @return array{input_tokens: int, cached_input_tokens: int, output_tokens: int, reasoning_tokens: int, web_search_calls: int}
     */
    protected function usage(array $data): array
    {
        $usage = (array) ($data['usage'] ?? []);

        return [
            'input_tokens'        => (int) ($usage['input_tokens'] ?? 0),
            'cached_input_tokens' => (int) ($usage['input_tokens_details']['cached_tokens'] ?? 0),
            'output_tokens'       => (int) ($usage['output_tokens'] ?? 0),
            'reasoning_tokens'    => (int) ($usage['output_tokens_details']['reasoning_tokens'] ?? 0),
            // Web検索の回数（検索1回ごとに料金がかかる）。ページを開く・ページの中を探す動作は、料金の対象ではないため数えない（D-31-01）
            'web_search_calls'    => count(array_filter((array) ($data['output'] ?? []), fn ($item) => ($item['type'] ?? null) === 'web_search_call'
                && in_array($item['action']['type'] ?? 'search', ['search'], true))),
        ];
    }

    /**
     * @throws OpenAiException
     */
    protected function post(string $path, array $body): array
    {
        $url = rtrim($this->baseUrl, '/') . $path;
        $waited = 0;

        while (true) {
            try {
                /** @var Response $response */
                $response = Http::timeout($this->timeout)->withToken($this->apiKey)->acceptJson()->post($url, $body);
            } catch (ConnectionException $e) {
                Log::warning('OpenAI APIへの接続に失敗しました。', ['url' => $url, 'message' => $e->getMessage()]);

                throw new OpenAiException("OpenAIに接続できませんでした（応答の待ち時間の超過を含む）：{$e->getMessage()}");
            }

            // 1分あたりの上限（回数・トークン数）は、案内された時間だけ待って送り直す（断られたリクエストに料金はかからない。D-43）。
            // クレジットの不足（insufficient_quota も 429）は、待っても直らないため送り直さない
            $wait = $this->rateLimitWait($response);
            if ($wait === null || $waited + $wait > self::MAX_RATE_LIMIT_WAIT) {
                break;
            }
            Log::info('OpenAI APIの1分あたりの上限に達したため、待ってから送り直します。', ['url' => $url, 'wait' => $wait]);
            Sleep::for($wait)->seconds();
            $waited += $wait;
        }

        if ($response->failed()) {
            Log::warning('OpenAI APIがエラーを返しました。', ['url' => $url, 'status' => $response->status(), 'body' => mb_substr($response->body(), 0, 2000)]);

            throw new OpenAiException(
                'OpenAI APIがエラーを返しました：' . $this->describe($response),
                $response->status(),
                mb_substr($response->body(), 0, 2000)
            );
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new OpenAiException('OpenAI APIの応答がJSONではありません。', $response->status(), mb_substr($response->body(), 0, 2000));
        }

        return $data;
    }

    /**
     * 1分あたりの上限に達した場合の、送り直すまでの秒数（上限のエラーでなければ null）
     *
     * Retry-After の見出し、なければメッセージの「try again in 9.353s」（ms・分の形も）を使う。読み取れなければ 20秒
     */
    protected function rateLimitWait(Response $response): ?int
    {
        if ($response->status() !== 429 || $response->json('error.code') === 'insufficient_quota') {
            return null;
        }

        $seconds = null;
        if (is_numeric($response->header('Retry-After'))) {
            $seconds = (float) $response->header('Retry-After');
        } elseif (preg_match('/try again in\s+(?:(\d+)m(?!s))?(?:([\d.]+)s)?(?:([\d.]+)ms)?/i', (string) $response->json('error.message'), $m) && ($m[0] ?? '') !== '') {
            $seconds = (float) ($m[1] ?? 0) * 60 + (float) ($m[2] ?? 0) + (float) ($m[3] ?? 0) / 1000;
        }

        return max(1, (int) ceil(($seconds ?? 20) + 1));
    }

    /**
     * よくあるエラーに、対処を添える
     */
    protected function describe(Response $response): string
    {
        $message = (string) ($response->json('error.message') ?? "HTTP {$response->status()}");
        $code = (string) ($response->json('error.code') ?? '');

        $hint = match (true) {
            // 権限を制限したAPIキー（Restricted）で、使う機能が許可されていない
            stripos($message, 'Missing scopes') !== false => 'APIキーの権限（Permissions）が足りません。OpenAI の画面の API keys で、このキーを編集し、不足している権限（'
                . (preg_match('/Missing scopes:\s*([^\s.]+)/i', $message, $scope) ? $scope[1] : '')
                . '。画像なら Images）を許可してください。キーの文字列は変わらないため、.env を直す必要はありません。',
            $response->status() === 401                                  => 'APIキーが正しいか（.env の OPENAI_API_KEY）確認してください。',
            $response->status() === 403 && stripos($message, 'verif') !== false => '画像モデルなど一部のモデルは、OpenAI の組織の本人確認（Organization Verification）が必要です。OpenAI の画面の Settings → Organization で確認してください。',
            $code === 'insufficient_quota'                               => 'OpenAIのクレジット残高が不足しています。Billing の画面で残高を確認してください。',
            $response->status() === 429                                  => '利用の上限（1分あたりの回数・トークン数）に達しました。少し待ってから実行し直してください。',
            $response->status() === 404 || $code === 'model_not_found'   => 'モデル名が正しいか、このAPIキーで使えるモデルか確認してください。',
            $response->status() >= 500                                   => 'OpenAI側の障害の可能性があります。時間をおいて実行し直してください。',
            default                                                      => '',
        };

        return "HTTP {$response->status()} {$message}" . ($hint !== '' ? "（{$hint}）" : '');
    }
}
