<?php

namespace Tests\Feature\Quality;

use App\Clients\OpenAi\OpenAiClient;
use App\Clients\OpenAi\OpenAiException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * OpenAI API の1分あたりの上限（HTTP 429）で、待って送り直す（D-43）。
 */
class OpenAiRateLimitTest extends TestCase
{
    protected function completed(): array
    {
        return ['id' => 'resp', 'status' => 'completed', 'model' => 'gpt-6-luna',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '回答']]]],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5]];
    }

    protected function rateLimited(string $message): array
    {
        return ['error' => ['message' => $message, 'type' => 'tokens', 'code' => 'rate_limit_exceeded']];
    }

    public function test_rate_limit_waits_as_advised_and_retries(): void
    {
        Sleep::fake();
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()
            ->push($this->rateLimited('Rate limit reached for gpt-6-luna on tokens per min (TPM): Limit 200000, Used 168759, Requested 62420. Please try again in 9.353s.'), 429)
            ->push($this->rateLimited('Rate limit reached. Please try again in 500ms.'), 429)
            ->push($this->completed(), 200)]);

        $result = (new OpenAiClient('sk-test'))->respond('gpt-6-luna', '指示', 'medium', 1000);

        $this->assertSame('回答', $result['text']);
        Http::assertSentCount(3);
        // 9.353秒 → 11秒（切り上げ＋1秒）、500ミリ秒 → 2秒
        Sleep::assertSequence([Sleep::for(11)->seconds(), Sleep::for(2)->seconds()]);
    }

    public function test_insufficient_quota_and_long_waits_are_not_retried(): void
    {
        Sleep::fake();
        Http::fake(['api.openai.com/v1/responses' => Http::response(['error' => ['message' => 'You exceeded your current quota.', 'code' => 'insufficient_quota']], 429)]);

        try {
            (new OpenAiClient('sk-test'))->respond('gpt-6-luna', '指示', 'medium', 1000);
            $this->fail('例外になるはず');
        } catch (OpenAiException $e) {
            $this->assertStringContainsString('クレジット残高が不足', $e->getMessage());
        }
        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    public function test_gives_up_when_total_wait_exceeds_the_limit(): void
    {
        Sleep::fake();
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->rateLimited('Please try again in 1m30s.'), 429)]);

        try {
            (new OpenAiClient('sk-test'))->respond('gpt-6-luna', '指示', 'medium', 1000);
            $this->fail('例外になるはず');
        } catch (OpenAiException $e) {
            $this->assertSame(429, $e->status);
        }
        // 91秒を1回待ち、次の91秒で合計の上限（180秒）を超えるため止める
        Http::assertSentCount(2);
        Sleep::assertSequence([Sleep::for(91)->seconds()]);
    }
}
