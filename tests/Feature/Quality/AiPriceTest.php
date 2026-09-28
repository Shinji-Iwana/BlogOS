<?php

namespace Tests\Feature\Quality;

use App\Enums\AiPriceChangeStatus;
use App\Models\AiPrice;
use App\Models\AiPriceChange;
use App\Models\AiPriceCheck;
use App\Models\Blog;
use App\Models\User;
use App\Services\Ai\AiApiPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * API実行の料金表と、OpenAIの公式のページとの毎日の照合（D-31-03）。
 */
class AiPriceTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Blog $blog;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->user = User::factory()->create();
        $this->blog = Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true, 'quality_profile' => 'si-note']);
        $this->actingAs($this->user);
    }

    /**
     * 公式のモデルのページ（料金の部分）と同じ形の HTML
     */
    protected function modelPage(string $input, string $cached, string $write, string $output, string $long = '272K'): string
    {
        return "<h2>Pricing</h2><p>Text tokens</p><p>Per 1M tokens</p><div><span>Input</span><span>\${$input}</span></div>"
            . "<div><span>Cached input</span><span>\${$cached}</span></div><div><span>Cache writes</span><span>\${$write}</span></div>"
            . "<div><span>Output</span><span>\${$output}</span></div>"
            . "<p>Prompts with more than {$long} input tokens are priced at 2x input and cache rates and 1.5x output for the full request.</p>";
    }

    /**
     * @var array<string, string> 公式のページの内容（テストの途中で差し替えられるよう、偽の応答は毎回ここを読む）
     */
    protected array $pages = [];

    protected function fakePages(array $luna, string $webSearch = '10.00', ?string $solPage = null): void
    {
        $this->pages = [
            'gpt-6-luna'  => $this->modelPage(...$luna),
            'gpt-6-sol'   => $solPage ?? $this->modelPage('2.00', '0.20', '2.50', '10.00'),
            'gpt-6-astra' => $this->modelPage('10.00', '1.00', '12.50', '50.00'),
            'pricing'     => "<td>Web search (all models)</td><td>\${$webSearch} / 1k calls</td>",
        ];

        Http::fake(fn ($request) => Http::response($this->pages[basename(parse_url($request->url(), PHP_URL_PATH))] ?? '', 200));
    }

    public function test_same_prices_change_nothing(): void
    {
        $this->fakePages(['0.10', '0.01', '0.125', '0.50']);

        $this->artisan('ai:check-prices')->expectsOutput('料金表は公式のページと同じです。')->assertSuccessful();

        $this->assertSame(0, AiPriceChange::count());
        $this->assertTrue(AiPriceCheck::sole()->succeeded());
        $this->assertNotNull(AiPrice::where('price_key', 'gpt-6-luna')->value('checked_at'));
    }

    public function test_increase_is_applied_and_decrease_waits_for_confirmation(): void
    {
        // 出力が値上がり、入力が値下がり、Web検索が値上がり
        $this->fakePages(['0.08', '0.01', '0.125', '0.60'], '12.00');

        $this->artisan('ai:check-prices')->assertSuccessful();

        $luna = AiPrice::where('price_key', 'gpt-6-luna')->sole();
        $this->assertSame(0.60, $luna->output);
        $this->assertSame(0.10, $luna->input);
        $this->assertSame(0.012, AiPrice::where('price_key', AiPrice::WEB_SEARCH)->value('per_call') + 0.0);

        // 費用の目安は、新しい料金表で計算する
        $policy = app(AiApiPolicy::class);
        $this->assertSame(round((20000 * 0.125 + 10000 * 0.01 + 12000 * 0.60) / 1_000_000 + 0.012, 4), $policy->cost('gpt-6-luna', 30000, 10000, 12000, 1));

        $pending = AiPriceChange::where('status', AiPriceChangeStatus::Pending)->sole();
        $this->assertSame('input', $pending->field);
        $this->assertSame(0.08, $pending->new_value);

        // 同じ値下がりを、もう一度記録しない
        $this->artisan('ai:check-prices')->assertSuccessful();
        $this->assertSame(1, AiPriceChange::where('status', AiPriceChangeStatus::Pending)->count());

        // 画面で知らせ、人が確認して反映する
        $this->get(route('home'))->assertOk()->assertSee('値下がりの確認待ちが1件');
        $this->get(route('ai.settings.edit'))->assertOk()->assertSee('値下がり（確認待ち）：1件')->assertSee('gpt-6-luna 入力');
        $this->post(route('ai.prices.apply', ['id' => $pending->id]), ['selected_blog_id' => $this->blog->id])->assertRedirect(route('ai.settings.edit'));

        $this->assertSame(0.08, $luna->fresh()->input);
        $this->assertSame(AiPriceChangeStatus::Applied, $pending->fresh()->status);
        $this->assertSame($this->user->id, $pending->fresh()->decided_by);
    }

    public function test_rejected_or_reverted_decrease(): void
    {
        $this->fakePages(['0.08', '0.01', '0.125', '0.50']);
        $this->artisan('ai:check-prices')->assertSuccessful();
        $pending = AiPriceChange::sole();

        $this->post(route('ai.prices.reject', ['id' => $pending->id]), ['selected_blog_id' => $this->blog->id])->assertRedirect();
        $this->assertSame(AiPriceChangeStatus::Rejected, $pending->fresh()->status);
        $this->assertSame(0.10, AiPrice::where('price_key', 'gpt-6-luna')->value('input') + 0.0);

        // 公式のページが元の値に戻ったら、確認待ちは不要になる
        $this->fakePages(['0.07', '0.01', '0.125', '0.50']);
        $this->artisan('ai:check-prices')->assertSuccessful();
        $this->fakePages(['0.10', '0.01', '0.125', '0.50']);
        $this->artisan('ai:check-prices')->assertSuccessful();
        $this->assertSame(0, AiPriceChange::where('status', AiPriceChangeStatus::Pending)->count());
    }

    public function test_unreadable_page_keeps_prices_and_warns(): void
    {
        // sol のページの形が変わった（料金を読み取れない）・luna はありえない値
        $this->fakePages(['0.10', '0.20', '0.125', '0.50'], '10.00', '<p>Pricing moved</p>');

        $this->artisan('ai:check-prices')->assertFailed();

        $check = AiPriceCheck::sole();
        $this->assertFalse($check->succeeded());
        $this->assertCount(2, $check->messages);
        $this->assertSame(0, AiPriceChange::count());
        $this->assertSame(0.20, AiPrice::where('price_key', 'gpt-6-sol')->value('cached_input') + 0.0);

        $this->get(route('home'))->assertOk()->assertSee('公式のページから読み取れなかった料金があります。');
        $this->get(route('ai.settings.edit'))->assertOk()->assertSee('読み取れなかった料金があります');
    }

    public function test_check_can_be_run_from_screen(): void
    {
        $this->fakePages(['0.10', '0.01', '0.125', '0.50']);

        $this->post(route('ai.prices.check'), ['selected_blog_id' => $this->blog->id])
            ->assertRedirect(route('ai.settings.edit'))
            ->assertSessionHas('status', fn ($status) => str_contains($status, '料金表は公式のページと同じです。'));
    }
}
