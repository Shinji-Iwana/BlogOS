<?php

namespace Tests\Feature\Quality;

use App\Enums\AiCreditEntryType;
use App\Enums\AiExecutionMethod;
use App\Enums\AiGenerationStatus;
use App\Enums\AiMode;
use App\Models\AiCreditEntry;
use App\Models\AiGeneration;
use App\Models\Blog;
use App\Models\Post;
use App\Models\User;
use App\Services\Ai\AiCreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * OpenAI の残高の見込みと、足りなくなる見込みでのAPI実行の停止（D-31-04）。
 */
class AiCreditTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Blog $blog;

    protected Post $post;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.openai.key'           => 'sk-test-key',
            'blogos.ai.api.monthly_budget_usd' => null,
            'blogos.ai.credit.warning_usd'  => 3,
            'blogos.ai.credit.reserve_usd'  => 0.5,
        ]);
        Http::preventStrayRequests();

        $this->user = User::factory()->create();
        $this->blog = Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true, 'quality_profile' => 'si-note']);
        $this->post = Post::create([
            'blog_id' => $this->blog->id, 'wordpress_id' => 100, 'title_raw' => 'PHP入門', 'status' => 'publish',
            'content_raw' => '<p>本文</p>', 'link' => 'https://blog.example.test/php/100.html', 'normalized_path' => '/php/100.html',
        ]);
        $this->actingAs($this->user);
    }

    protected function selected(array $data = []): array
    {
        return array_merge(['selected_blog_id' => $this->blog->id], $data);
    }

    protected function spend(float $cost, string $at): void
    {
        AiGeneration::create([
            'blog_id' => $this->blog->id, 'purpose' => AiMode::QualityDiagnosis, 'execution_method' => AiExecutionMethod::Api, 'provider' => 'openai',
            'model' => 'gpt-6-luna', 'template_key' => 'quality_diagnosis', 'template_version' => '1', 'input' => 'x',
            'status' => AiGenerationStatus::Succeeded, 'estimated_cost' => $cost, 'input_tokens' => 1000, 'output_tokens' => 100,
            'created_at' => $at, 'completed_at' => $at,
        ]);
    }

    public function test_unregistered_balance_is_notified_but_does_not_block(): void
    {
        $this->get(route('home'))->assertOk()->assertSee('OpenAI の残高が登録されていません。');
        $this->assertNull(app(AiCreditService::class)->status()['balance']);

        Http::fake(['api.openai.com/v1/responses' => Http::response(['id' => 'r', 'status' => 'completed', 'model' => 'gpt-6-luna',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'SEO分析']]]],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 10]])]);
        $this->post(route('ai.generations.store'), $this->selected([
            'mode' => 'seo_analysis', 'target' => "posts:{$this->post->id}", 'execution_method' => 'api', 'model' => 'gpt-6-luna', 'reasoning_effort' => 'medium',
        ]))->assertRedirect();
        $this->assertSame(AiGenerationStatus::Succeeded, AiGeneration::sole()->status);
    }

    public function test_balance_is_estimated_from_registered_balance_purchases_and_costs(): void
    {
        $this->spend(2.00, now()->subDays(3)->toDateTimeString());

        // OpenAI の画面で見た残高（2日前）
        $this->post(route('ai.credits.balance'), $this->selected(['amount' => 6.43, 'occurred_at' => now(config('blogos.display_timezone'))->subDays(2)->format('Y-m-d\TH:i')]))->assertRedirect();
        $this->spend(1.50, now()->subDay()->toDateTimeString());
        $this->spend(0.25, now()->subHour()->toDateTimeString());

        $status = app(AiCreditService::class)->status();
        $this->assertSame(4.68, $status['balance']);
        $this->assertSame('ok', $status['level']);

        // 課金を登録すると、見込みに加わる
        $this->post(route('ai.credits.purchase'), $this->selected(['amount' => 10]))->assertRedirect();
        $this->assertSame(14.68, app(AiCreditService::class)->status()['balance']);

        // 実際の残高を登録すると、その時点の見込みとの差を残し、見込みは実際の残高から計算し直す
        $this->post(route('ai.credits.balance'), $this->selected(['amount' => 14.50]))->assertRedirect()->assertSessionHas('status', fn ($status) => str_contains($status, '差 $-0.18'));
        $entry = AiCreditEntry::where('type', AiCreditEntryType::Balance)->latest('id')->first();
        $this->assertSame(14.68, $entry->estimated_balance);
        $this->assertSame(14.50, app(AiCreditService::class)->status()['balance']);

        // 画面：見込みと、OpenAI の画面と比べるための日ごとの記録
        $this->get(route('ai.credits.index'))->assertOk()->assertSee('$14.50')->assertSee('gpt-6-luna')->assertSee('差 -0.18');
    }

    public function test_low_balance_warns_and_insufficient_balance_blocks_api(): void
    {
        $this->post(route('ai.credits.balance'), $this->selected(['amount' => 2.50]))->assertRedirect();
        $this->get(route('home'))->assertOk()->assertSee('OpenAI の残高の見込みが $2.50 になりました');

        // 残しておく額（$0.50）を下回る見込みなら、実行しない
        $this->post(route('ai.credits.balance'), $this->selected(['amount' => 0.51]))->assertRedirect();
        $this->get(route('home'))->assertOk()->assertSee('API実行は止まっているか、まもなく止まります');

        $this->post(route('ai.generations.store'), $this->selected([
            'mode' => 'seo_analysis', 'target' => "posts:{$this->post->id}", 'execution_method' => 'api', 'model' => 'gpt-6-luna', 'reasoning_effort' => 'medium',
        ]))->assertSessionHasErrors('ai');
        $this->assertStringContainsString('OpenAI の残高が足りなくなる見込み', session('errors')->first('ai'));
        $this->assertSame(0, AiGeneration::count());

        // 課金を登録すると、また実行できる
        $this->post(route('ai.credits.purchase'), $this->selected(['amount' => 5]))->assertRedirect();
        $this->assertSame('ok', app(AiCreditService::class)->status()['level']);
    }

    public function test_monthly_budget_is_optional(): void
    {
        $this->post(route('ai.credits.balance'), $this->selected(['amount' => 100]))->assertRedirect();
        $this->spend(9.99, now()->toDateTimeString());

        // 月の支出の上限を設定した場合だけ、上限で止める
        config(['blogos.ai.api.monthly_budget_usd' => 10]);
        $this->post(route('ai.generations.store'), $this->selected([
            'mode' => 'seo_analysis', 'target' => "posts:{$this->post->id}", 'execution_method' => 'api', 'model' => 'gpt-6-luna', 'reasoning_effort' => 'medium',
        ]))->assertSessionHasErrors('ai');
        $this->assertStringContainsString('月の支出の上限', session('errors')->first('ai'));

        // 登録の誤りは削除できる
        $entry = AiCreditEntry::sole();
        $this->delete(route('ai.credits.destroy', ['id' => $entry->id]), $this->selected())->assertRedirect();
        $this->assertSame(0, AiCreditEntry::count());
    }
}
