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

    public function test_usage_is_broken_down_by_purpose_and_trigger(): void
    {
        // 自動（定期実行）の品質診断2回、人が実行した記事改修1回、音声の操作（方式A）1回
        $this->spend(0.30, now()->subHour()->toDateTimeString());
        $this->spend(0.20, now()->subHour()->toDateTimeString());
        AiGeneration::create([
            'blog_id' => $this->blog->id, 'purpose' => AiMode::Revision, 'execution_method' => AiExecutionMethod::Api, 'provider' => 'openai',
            'model' => 'gpt-6-luna', 'template_key' => 'revision', 'template_version' => '1', 'input' => 'x', 'requested_by' => $this->user->id,
            'status' => AiGenerationStatus::Succeeded, 'estimated_cost' => 1.00, 'input_tokens' => 5000, 'cached_input_tokens' => 1000, 'output_tokens' => 3000,
        ]);
        \App\Models\VoiceTurn::create(['user_id' => $this->user->id, 'mode' => 'c', 'transcribe_model' => 'gpt-transcribe', 'text_model' => 'gpt-6-luna', 'tts_model' => 'gpt-4o-mini-tts',
            'input_tokens' => 800, 'output_tokens' => 40, 'estimated_cost' => 0.004]);

        $html = $this->get(route('ai.credits.index'))->assertOk()->assertSee('処理ごとの内訳')->getContent();
        preg_match('/<h2>処理ごとの内訳<\/h2>.*?<\/table>/s', $html, $table);

        // 費用の多い順。処理・きっかけ・回数・トークン・費用・1回あたり・割合
        $this->assertMatchesRegularExpression('/記事改修<\/td>\s*<td>人が実行<\/td>\s*<td>gpt-6-luna<\/td>.*?品質診断<\/td>\s*<td>自動（定期実行など）<\/td>\s*<td>gpt-6-luna<\/td>.*?音声の操作（方式A）<\/td>\s*<td>人が実行（声）<\/td>\s*<td>gpt-transcribe・gpt-6-luna・gpt-4o-mini-tts<\/td>/s', $table[0]);
        $this->assertStringContainsString('>5,000</td>', $table[0]);
        $this->assertStringContainsString('>$0.5000</td>', $table[0]);
        $this->assertStringContainsString('>$0.2500</td>', $table[0]);
        $this->assertMatchesRegularExpression('/>66\.5%<\/td>/', $table[0]);
        $this->assertStringContainsString('>$1.5040</td>', $table[0]);
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
    public function test_balance_and_purchase_are_registered_from_menu_popups(): void
    {
        // メニューの「設定 → AI」に2つの項目があり、それぞれポップアップを開く。日時の欄には今が入っている（D-62）
        $this->travelTo(now()->setTime(12, 34));
        $home = $this->get(route('home'))->assertOk()
            ->assertSee('data-modal-open="credit-balance-modal"', false)
            ->assertSee('data-modal-open="credit-purchase-modal"', false)
            ->assertSee('id="credit-balance-modal"', false)
            ->assertSee('id="credit-purchase-modal"', false)
            ->getContent();
        $this->assertStringContainsString('value="' . now(config('blogos.display_timezone'))->format('Y-m-d\TH:i') . '"', $home);
        // 「AIの費用と残高」の画面には、もう登録の欄はない
        $this->get(route('ai.credits.index'))->assertOk()->assertDontSee('<h2>登録する</h2>', false)->assertDontSee('空なら今');

        // 登録した後は、開いていた画面に戻る
        $this->from(route('drafts.index'))->post(route('ai.credits.balance'), $this->selected(['_form' => 'credit-balance', 'amount' => 20, 'occurred_at' => now(config('blogos.display_timezone'))->format('Y-m-d\TH:i')]))
            ->assertRedirect(route('drafts.index'));
        $this->from(route('drafts.index'))->post(route('ai.credits.purchase'), $this->selected(['_form' => 'credit-purchase', 'amount' => 5]))
            ->assertRedirect(route('drafts.index'));
        $this->assertSame([AiCreditEntryType::Balance, AiCreditEntryType::Purchase], AiCreditEntry::orderBy('id')->pluck('type')->all());

        // 日時は日本時間として読み、今より後は断る
        $this->post(route('ai.credits.balance'), $this->selected(['amount' => 1, 'occurred_at' => now(config('blogos.display_timezone'))->addMinutes(5)->format('Y-m-d\TH:i')]))
            ->assertSessionHasErrors('occurred_at');
        $this->assertSame(2, AiCreditEntry::count());
    }

    public function test_credit_popup_stays_open_with_errors(): void
    {
        $this->from(route('home'))->post(route('ai.credits.purchase'), $this->selected(['_form' => 'credit-purchase', 'amount' => '']))->assertRedirect(route('home'));

        $html = $this->get(route('home'))->assertOk()->assertSee('金額（米ドル）を入力してください。')->getContent();
        $this->assertMatchesRegularExpression('/id="credit-purchase-modal"[^>]*data-modal-autoopen/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="credit-balance-modal"[^>]*data-modal-autoopen/', $html);
    }
}
