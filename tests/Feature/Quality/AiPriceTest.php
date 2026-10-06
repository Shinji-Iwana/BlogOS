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

    /**
     * @param array{transcribe?: string, tts_output?: string, realtime_audio_input?: string} $voice 音声の操作のモデルの料金（省略時は設定と同じ）
     */
    protected function fakePages(array $luna, string $webSearch = '10.00', ?string $solPage = null, array $voice = []): void
    {
        $this->pages = [
            'gpt-6-luna'  => $this->modelPage(...$luna),
            'gpt-6-sol'   => $solPage ?? $this->modelPage('2.00', '0.20', '2.50', '10.00'),
            'gpt-6-astra' => $this->modelPage('10.00', '1.00', '12.50', '50.00'),
            'pricing'     => "<td>Web search (all models)</td><td>\${$webSearch} / 1k calls</td>"
                // 画像モデル（標準の処理の表）
                . '<tr><td>gpt-image-2.5-flare</td><td>Image</td><td>$8.00</td><td>$2.00</td><td>$30.00</td></tr><tr><td>Text</td><td>$5.00</td><td>$1.25</td><td>-</td></tr>'
                // 音声の操作のモデル（D-68-02）：リアルタイム会話の表と、聞き取りの表（公式のページと同じ並び）
                . '<h2>Realtime and audio generation models</h2><tr><td>gpt-realtime-2.1</td><td>Audio</td><td>$32.00</td><td>$0.40</td><td>$64.00</td></tr>'
                . '<tr><td>Text</td><td>$4.00</td><td>$0.40</td><td>$24.00</td></tr><tr><td>Image</td><td>$5.00</td><td>$0.50</td><td>-</td></tr>'
                . '<tr><td>gpt-realtime-2.1-mini</td><td>Audio</td><td>$' . ($voice['realtime_audio_input'] ?? '10.00') . '</td><td>$0.30</td><td>$20.00</td></tr>'
                . '<tr><td>Text</td><td>$0.60</td><td>$0.06</td><td>$2.40</td></tr><tr><td>Image</td><td>$0.80</td><td>$0.08</td><td>-</td></tr>'
                . '<h2>Transcription models</h2><tr><td>gpt-live-transcribe</td><td>Live transcription</td><td>-</td><td>-</td><td>$0.017 / minute</td></tr>'
                . '<tr><td>gpt-transcribe</td><td>Transcription</td><td>-</td><td>-</td><td>$' . ($voice['transcribe'] ?? '0.0045') . ' / minute</td></tr>'
                . '<tr><td>gpt-4o-transcribe</td><td>Transcription</td><td>$2.50</td><td>$10.00</td><td>$0.006 / minute</td></tr>'
                . '<tr><td>gpt-4o-mini-transcribe</td><td>Transcription</td><td>$1.25</td><td>$5.00</td><td>$0.003 / minute</td></tr>',
            'gpt-4o-mini-tts' => '<h2>Pricing</h2><p>Text tokens</p><p>Per 1M tokens</p><div>Input</div><div>$0.60</div><p>Quick comparison</p>'
                . '<p>Audio tokens</p><p>Per 1M tokens</p><div>Output</div><div>$' . ($voice['tts_output'] ?? '12.00') . '</div>',
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

    public function test_voice_model_prices_are_checked(): void
    {
        // 返事の声の音声の出力が値上がり、聞き取りが値下がり、リアルタイム会話（mini）の音声の入力が値上がり（D-68-02）
        $this->fakePages(['0.10', '0.01', '0.125', '0.50'], '10.00', null, ['tts_output' => '16.00', 'transcribe' => '0.004', 'realtime_audio_input' => '12.00']);

        $this->artisan('ai:check-prices')->assertSuccessful();

        $this->assertSame(16.0, AiPrice::where('price_key', 'gpt-4o-mini-tts')->value('audio_output') + 0.0);
        $this->assertSame(12.0, AiPrice::where('price_key', 'gpt-realtime-2.1-mini')->value('audio_input') + 0.0);
        $pending = AiPriceChange::where('status', AiPriceChangeStatus::Pending)->sole();
        $this->assertSame(['gpt-transcribe', 'per_call', 0.004], [$pending->price_key, $pending->field, $pending->new_value]);

        // 費用の目安は、新しい料金表で計算する（返事の声 1分＝音声の出力 1,250トークン）
        $prices = app(\App\Services\Voice\VoicePrices::class);
        $this->assertEqualsWithDelta(16.0 * 1250 / 1_000_000, $prices->ttsPerMinute('gpt-4o-mini-tts'), 0.0000001);
        $this->assertSame(12.0, $prices->realtime('gpt-realtime-2.1-mini')['audio_input']);
        $this->assertSame(0.0045, $prices->transcribePerMinute('gpt-transcribe'));

        // AIの設定の画面：値下がりの確認待ちだけ（料金表・変更の記録・今すぐ照合は出さない。D-63-27・D-63-28）
        $this->get(route('ai.settings.edit'))->assertOk()
            ->assertSee('gpt-transcribe 1分あたり')
            ->assertDontSee('文字の入力 $0.6・音声の出力 $16', false)
            ->assertDontSee('<th>確認した人</th>', false)
            ->assertDontSee('今すぐ公式のページと照合する');
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('ai.prices.check'));

        // 料金表は、画面「OpenAI API料金表情報」（D-63-28）。ブログを選んでいなくても開ける。メニューの「情報」の「WordPress API情報」の下
        $this->blog->update(['is_selected' => false]);
        $html = $this->get(route('ai.prices.index'))->assertOk()
            ->assertSee('<h1>OpenAI API料金表情報</h1>', false)
            ->assertSee('<a href="' . route('home') . '">トップページに戻る</a>', false)
            ->assertSee('文字の入力 $0.6・音声の出力 $16', false)
            ->assertSee('<h2>音声の操作のモデル</h2>', false)
            ->getContent();
        $this->assertMatchesRegularExpression('/>WordPress API情報<\/a><\/li>\s*<li><a href="' . preg_quote(route('ai.prices.index'), '/') . '"\s*>OpenAI API料金表情報<\/a>/', $html);

        // 変更の記録は、画面「OpenAI API料金表との同期履歴」（D-63-27）。ブログを選んでいなくても開ける
        $this->get(route('ai.prices.history'))->assertOk()
            ->assertSee('<h1>OpenAI API料金表との同期履歴', false)
            ->assertSee('<a href="' . route('home') . '">トップページに戻る</a>', false)
            ->assertSee('<th>確認した人</th>', false)
            ->assertSee('gpt-4o-mini-tts 音声の出力')
            ->assertSee('（自動）');
    }

}
