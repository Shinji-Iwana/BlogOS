<?php

namespace Tests\Feature\Quality;

use App\Enums\AiExecutionMethod;
use App\Enums\AiGenerationStatus;
use App\Enums\EvaluatorType;
use App\Jobs\RunAiApiJob;
use App\Models\AiGeneration;
use App\Models\ArticleDraft;
use App\Models\ArticleEvaluation;
use App\Models\Blog;
use App\Models\Post;
use App\Models\User;
use App\Repositories\AiGenerationRepository;
use App\Services\Ai\AiRunService;
use App\Services\Quality\QualityStandardLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * BlogOSのAI機能のAPI実行（OpenAI Responses API）。D-07-06〜D-07-08、D-24。
 */
class AiApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Blog $blog;

    protected Post $post;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.openai.key' => 'sk-test-key', 'blogos.ai.api.monthly_budget_usd' => 10]);
        Http::preventStrayRequests();

        $this->user = User::factory()->create();
        $this->blog = Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true, 'quality_profile' => 'si-note']);
        $this->post = Post::create([
            'blog_id' => $this->blog->id, 'wordpress_id' => 100, 'title_raw' => 'PHP入門', 'status' => 'publish',
            'content_raw' => '<p>本文</p>', 'link' => 'https://blog.example.test/php/100.html', 'normalized_path' => '/php/100.html',
            'wordpress_modified_gmt' => '2026-09-01 00:00:00',
        ]);

        $this->actingAs($this->user);
    }

    protected function selected(array $data = []): array
    {
        return array_merge(['selected_blog_id' => $this->blog->id], $data);
    }

    protected function diagnosisOutput(): string
    {
        $standard = app(QualityStandardLoader::class)->load('si-note');
        $judgments = fn (array $keys) => array_map(fn () => ['judgment' => '○', 'comment' => 'OK'], array_flip($keys));

        return json_encode([
            'required' => $judgments(array_keys($standard->required)),
            'items'    => $judgments(array_keys($standard->items)),
            'summary'  => 'APIで診断した',
        ], JSON_UNESCAPED_UNICODE);
    }

    protected function response(string $text, string $status = 'completed', array $extra = []): array
    {
        return array_merge([
            'id'     => 'resp_123',
            'status' => $status,
            'model'  => 'gpt-6-sol-2026-09-22',
            'output' => [
                ['type' => 'reasoning', 'summary' => []],
                ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => $text]]],
            ],
            'usage' => [
                'input_tokens'          => 30000,
                'input_tokens_details'  => ['cached_tokens' => 10000],
                'output_tokens'         => 12000,
                'output_tokens_details' => ['reasoning_tokens' => 4000],
            ],
        ], $extra);
    }

    public function test_quality_diagnosis_via_api(): void
    {
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->response($this->diagnosisOutput()))]);

        $this->get(route('ai.generations.create', ['mode' => 'quality_diagnosis', 'target' => "posts:{$this->post->id}"]))
            ->assertOk()
            ->assertSee('API実行（BlogOSがOpenAI APIで実行')
            ->assertSee('gpt-6-sol');

        $this->post(route('ai.generations.store'), $this->selected([
            'mode' => 'quality_diagnosis', 'target' => "posts:{$this->post->id}", 'execution_method' => 'api', 'model' => 'gpt-6-sol', 'reasoning_effort' => 'medium',
        ]))->assertRedirect();

        // 送った内容：指示文・モデル・推論の深さ・出力の上限。OpenAI側に保存させない
        Http::assertSent(function (Request $request) {
            return $request->hasHeader('Authorization', 'Bearer sk-test-key')
                && $request['model'] === 'gpt-6-sol'
                && $request['reasoning'] === ['effort' => 'medium']
                && $request['max_output_tokens'] === 48000
                && $request['store'] === false
                && str_contains($request['input'], 'PHP入門');
        });

        $generation = AiGeneration::sole();
        $this->assertSame(AiExecutionMethod::Api, $generation->execution_method);
        $this->assertSame(AiGenerationStatus::Succeeded, $generation->status);
        $this->assertSame('openai', $generation->provider);
        // 応答に含まれる正確なモデル名を記録する（D-07-07）
        $this->assertSame('gpt-6-sol-2026-09-22', $generation->model);
        $this->assertSame('medium', $generation->reasoning_effort);
        $this->assertSame('resp_123', $generation->provider_response_id);
        $this->assertSame(30000, $generation->input_tokens);
        $this->assertSame(10000, $generation->cached_input_tokens);
        $this->assertSame(12000, $generation->output_tokens);
        $this->assertSame(4000, $generation->reasoning_tokens);
        // (20000 × $2 + 10000 × $0.20 + 12000 × $10) / 1M = $0.162
        $this->assertSame(0.162, $generation->estimated_cost);

        $evaluation = ArticleEvaluation::sole();
        $this->assertSame(EvaluatorType::Ai, $evaluation->evaluator_type);
        $this->assertSame($generation->id, $evaluation->ai_generation_id);

        $this->get(route('ai.generations.show', ['id' => $generation->id]))->assertOk()->assertSee('$0.1620')->assertSee('うち推論 4,000');
    }

    public function test_revision_via_api_uses_mode_defaults(): void
    {
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->response("=== タイトル ===\nPHP入門【改訂】\n=== メタディスクリプション ===\n説明\n=== 本文 ===\n<p>新しい本文</p>"))]);

        $this->post(route('ai.generations.store'), $this->selected([
            'mode' => 'revision', 'target' => "posts:{$this->post->id}", 'execution_method' => 'api',
        ]))->assertRedirect();

        Http::assertSent(fn (Request $request) => $request['model'] === 'gpt-6-sol' && $request['reasoning'] === ['effort' => 'medium']);

        $draft = ArticleDraft::sole();
        $this->assertSame('PHP入門【改訂】', $draft->title_raw);
        $this->assertSame('説明', $draft->meta_description);
        $this->assertSame(AiGeneration::sole()->id, $draft->ai_generation_id);
    }

    public function test_api_is_refused_without_key_or_over_budget_or_unsupported_effort(): void
    {
        Http::fake();
        $request = ['mode' => 'quality_diagnosis', 'target' => "posts:{$this->post->id}", 'execution_method' => 'api', 'model' => 'gpt-6-sol', 'reasoning_effort' => 'medium'];

        // gpt-6-astra は推論なし（none）に対応していない
        $this->post(route('ai.generations.store'), $this->selected(['reasoning_effort' => 'none', 'model' => 'gpt-6-astra'] + $request))->assertSessionHasErrors('ai');

        // 今月の費用＋今回の最大の費用が上限を超える
        AiGeneration::create([
            'blog_id' => $this->blog->id, 'purpose' => 'seo_analysis', 'execution_method' => 'api', 'template_key' => 'x', 'template_version' => '1',
            'input' => 'x', 'status' => 'succeeded', 'estimated_cost' => 9.8,
        ]);
        $this->post(route('ai.generations.store'), $this->selected($request))->assertSessionHasErrors('ai');

        // 先月の費用は数えない
        AiGeneration::query()->update(['created_at' => now()->subMonths(2)]);

        config(['services.openai.key' => null]);
        $this->post(route('ai.generations.store'), $this->selected($request))->assertSessionHasErrors('ai');
        $this->get(route('ai.generations.create', ['mode' => 'quality_diagnosis', 'target' => "posts:{$this->post->id}"]))->assertSee('APIキーが設定されていないため');

        $this->assertSame(1, AiGeneration::count());
        Http::assertNothingSent();

        config(['services.openai.key' => 'sk-test-key']);
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->response($this->diagnosisOutput()))]);
        $this->post(route('ai.generations.store'), $this->selected($request))->assertSessionHasNoErrors();
        $this->assertSame(2, AiGeneration::count());
    }

    public function test_failures_are_recorded_with_cost_and_can_be_retried(): void
    {
        // 出力の上限で打ち切られた（料金はかかる）
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()
            ->push($this->response('途中まで', 'incomplete', ['incomplete_details' => ['reason' => 'max_output_tokens']]))
            ->push(['error' => ['message' => 'Rate limit reached', 'code' => 'rate_limit_exceeded']], 429)
            ->push($this->response($this->diagnosisOutput())),
        ]);

        $this->post(route('ai.generations.store'), $this->selected([
            'mode' => 'quality_diagnosis', 'target' => "posts:{$this->post->id}", 'execution_method' => 'api', 'model' => 'gpt-6-sol', 'reasoning_effort' => 'high',
        ]));

        $first = AiGeneration::sole();
        $this->assertSame(AiGenerationStatus::Failed, $first->status);
        $this->assertStringContainsString('max_output_tokens', $first->error);
        $this->assertSame(0.162, $first->estimated_cost);
        $this->assertNull($first->output);
        $this->assertSame(0, ArticleEvaluation::count());

        $this->get(route('ai.generations.show', ['id' => $first->id]))->assertOk()->assertSee('もう一度API実行する')->assertDontSee('指示文をコピー');

        // 実行し直すと、新しい実行記録になる（前の記録の費用は残す）。429 は対処を添えて記録する
        $this->post(route('ai.generations.retry', ['id' => $first->id]), $this->selected())->assertRedirect();
        $second = AiGeneration::latest('id')->first();
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(AiGenerationStatus::Failed, $second->status);
        $this->assertStringContainsString('少し待ってから', $second->error);
        $this->assertSame($first->input, $second->input);

        $this->post(route('ai.generations.retry', ['id' => $second->id]), $this->selected())->assertRedirect();
        $third = AiGeneration::latest('id')->first();
        $this->assertSame(AiGenerationStatus::Succeeded, $third->status);
        $this->assertSame('high', $third->reasoning_effort);
        $this->assertSame(1, ArticleEvaluation::count());

        // 成功した実行は、実行し直せない
        $this->post(route('ai.generations.retry', ['id' => $third->id]), $this->selected())->assertSessionHasErrors('ai');
        $this->assertSame(3, AiGeneration::count());
    }

    public function test_job_runs_only_once_and_waits_for_queue(): void
    {
        Queue::fake();
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->response($this->diagnosisOutput()))]);

        $this->post(route('ai.generations.store'), $this->selected([
            'mode' => 'quality_diagnosis', 'target' => "posts:{$this->post->id}", 'execution_method' => 'api',
        ]));
        Queue::assertPushed(RunAiApiJob::class);

        $generation = AiGeneration::sole();
        $this->assertSame(AiGenerationStatus::Running, $generation->status);
        $this->get(route('ai.generations.show', ['id' => $generation->id]))->assertOk()->assertSee('まだ処理が始まっていません');

        // 同じJobが2回動いても、APIは1回だけ呼ぶ
        app(AiRunService::class)->runApi($generation);
        app(AiRunService::class)->runApi($generation->fresh());
        Http::assertSentCount(1);
        $this->assertSame(AiGenerationStatus::Succeeded, $generation->fresh()->status);

        // Job自体の失敗（時間切れなど）は、実行中のままにしない
        $stuck = AiGeneration::create($generation->only(['blog_id', 'post_id', 'purpose', 'execution_method', 'template_key', 'template_version', 'input']) + ['status' => 'running']);
        (new RunAiApiJob($stuck->id))->failed(new \RuntimeException('timeout'));
        $this->assertSame(AiGenerationStatus::Failed, $stuck->fresh()->status);
        $this->assertSame(0.162, app(AiGenerationRepository::class)->apiCostSince(now()->subDay()));
    }
}
