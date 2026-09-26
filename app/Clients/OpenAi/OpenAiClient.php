<?php

namespace App\Clients\OpenAi;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OpenAI API（Responses API）の呼び出し（D-24）。
 *
 * 1回の指示文を送り、回答の文章とトークン数を返す。会話は続けないため、OpenAI側には保存させない（store: false）。
 * 失敗（通信エラー・HTTPエラー・途中で打ち切られた応答）は OpenAiException にする。APIキーはログ・例外に含めない。
 */
class OpenAiClient
{
    public function __construct(
        protected string $apiKey,
        protected string $baseUrl = 'https://api.openai.com/v1',
        protected int $timeout = 900,
    ) {
    }

    /**
     * @return array{text: string, model: string, response_id: string|null, input_tokens: int, cached_input_tokens: int, output_tokens: int, reasoning_tokens: int}
     *
     * @throws OpenAiException
     */
    public function respond(string $model, string $input, ?string $effort, int $maxOutputTokens): array
    {
        $body = array_filter([
            'model'             => $model,
            'input'             => $input,
            'reasoning'         => $effort !== null ? ['effort' => $effort] : null,
            'max_output_tokens' => $maxOutputTokens,
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
     * @return array{input_tokens: int, cached_input_tokens: int, output_tokens: int, reasoning_tokens: int}
     */
    protected function usage(array $data): array
    {
        $usage = (array) ($data['usage'] ?? []);

        return [
            'input_tokens'        => (int) ($usage['input_tokens'] ?? 0),
            'cached_input_tokens' => (int) ($usage['input_tokens_details']['cached_tokens'] ?? 0),
            'output_tokens'       => (int) ($usage['output_tokens'] ?? 0),
            'reasoning_tokens'    => (int) ($usage['output_tokens_details']['reasoning_tokens'] ?? 0),
        ];
    }

    /**
     * @throws OpenAiException
     */
    protected function post(string $path, array $body): array
    {
        $url = rtrim($this->baseUrl, '/') . $path;

        try {
            /** @var Response $response */
            $response = Http::timeout($this->timeout)->withToken($this->apiKey)->acceptJson()->post($url, $body);
        } catch (ConnectionException $e) {
            Log::warning('OpenAI APIへの接続に失敗しました。', ['url' => $url, 'message' => $e->getMessage()]);

            throw new OpenAiException("OpenAIに接続できませんでした（応答の待ち時間の超過を含む）：{$e->getMessage()}");
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
     * よくあるエラーに、対処を添える
     */
    protected function describe(Response $response): string
    {
        $message = (string) ($response->json('error.message') ?? "HTTP {$response->status()}");
        $code = (string) ($response->json('error.code') ?? '');

        $hint = match (true) {
            $response->status() === 401                                  => 'APIキーが正しいか（.env の OPENAI_API_KEY）確認してください。',
            $code === 'insufficient_quota'                               => 'OpenAIのクレジット残高が不足しています。Billing の画面で残高を確認してください。',
            $response->status() === 429                                  => '利用の上限（1分あたりの回数・トークン数）に達しました。少し待ってから実行し直してください。',
            $response->status() === 404 || $code === 'model_not_found'   => 'モデル名が正しいか、このAPIキーで使えるモデルか確認してください。',
            $response->status() >= 500                                   => 'OpenAI側の障害の可能性があります。時間をおいて実行し直してください。',
            default                                                      => '',
        };

        return "HTTP {$response->status()} {$message}" . ($hint !== '' ? "（{$hint}）" : '');
    }
}
