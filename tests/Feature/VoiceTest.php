<?php

namespace Tests\Feature;

use App\Jobs\SyncBlogJob;
use App\Models\AiBatch;
use App\Models\Blog;
use App\Models\Post;
use App\Models\User;
use App\Models\VoiceTurn;
use App\Repositories\AiGenerationRepository;
use App\Services\Voice\VoiceSettings;
use App\Services\Voice\VoiceTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 音声の操作（ジャービス。方式 c。D-58）。OpenAI への通信は偽の応答に置き換える。
 */
class VoiceTest extends TestCase
{
    use RefreshDatabase;

    protected Blog $blog;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.openai.key' => 'test-key']);
        $this->actingAs(User::factory()->create());
        $this->blog = Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true]);
    }

    protected function enable(): void
    {
        app(VoiceSettings::class)->save(['enabled' => true, 'mode' => 'c', 'voice' => 'cedar', 'instructions' => '落ち着いた執事のように'], null);
    }

    protected function audio(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('voice.webm', 'fake-audio-bytes');
    }

    public function test_voice_is_off_until_enabled(): void
    {
        $this->get(route('home'))->assertOk()->assertDontSee('id="voice-button"', false);

        $this->post(route('voice.turn'), ['audio' => $this->audio(), 'seconds' => 2])
            ->assertStatus(422)->assertJson(['ok' => false]);
        Http::assertNothingSent();
    }

    public function test_spoken_request_opens_a_screen_and_records_cost(): void
    {
        $this->enable();
        $this->get(route('home'))->assertOk()->assertSee('id="voice-button"', false)->assertSee('id="voice-panel"', false);

        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => '編集案の一覧を開いて']),
            'api.openai.com/v1/responses'            => Http::sequence()
                // 1回目：道具（画面を開く）を呼ぶ
                ->push(['status' => 'completed', 'model' => 'gpt-6-luna', 'output' => [
                    ['type' => 'function_call', 'call_id' => 'call_1', 'name' => 'open_screen', 'arguments' => '{"screen":"drafts.index"}'],
                ], 'usage' => ['input_tokens' => 1200, 'output_tokens' => 20]])
                // 2回目：道具の結果を受けて、返事をする
                ->push(['status' => 'completed', 'model' => 'gpt-6-luna', 'output' => [
                    ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '編集案の一覧を開きます。']]],
                ], 'usage' => ['input_tokens' => 1300, 'output_tokens' => 15]]),
            'api.openai.com/v1/audio/speech'         => Http::response('ID3-fake-mp3', 200, ['Content-Type' => 'audio/mpeg']),
        ]);

        $this->post(route('voice.turn'), ['audio' => $this->audio(), 'seconds' => 2.5])
            ->assertOk()
            ->assertJson([
                'ok'         => true,
                'transcript' => '編集案の一覧を開いて',
                'reply'      => '編集案の一覧を開きます。',
                'navigate'   => route('drafts.index'),
                'audio'      => base64_encode('ID3-fake-mp3'),
            ]);

        // 2回目の判断には、道具の呼び出しと結果を渡している
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/responses')
            && collect($request['input'])->contains(fn ($item) => ($item['type'] ?? null) === 'function_call_output'));
        // 返事の声は、選んだ声と話し方の指示で作る
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/audio/speech') && $request['voice'] === 'cedar' && $request['instructions'] === '落ち着いた執事のように');

        $turn = VoiceTurn::sole();
        $this->assertSame('open_screen', $turn->tool_calls[0]['name']);
        $this->assertSame(2500, $turn->input_tokens);
        $this->assertGreaterThan(0, $turn->estimated_cost);

        // 音声の費用は、AIの費用（残高の見込み・今月の費用）に含める
        $this->assertEqualsWithDelta($turn->estimated_cost, app(AiGenerationRepository::class)->apiCostSince(now()->subDay()), 0.000001);

        // 直前のやり取りを覚えておく（続きの発言のため）
        $this->assertCount(2, session('voice.history'));
    }

    public function test_missing_audio_permission_is_explained(): void
    {
        $this->enable();
        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response(['error' => [
                'message' => "You have insufficient permissions for this operation. Missing scopes: api.model.audio.request. Check that you have the correct role in your organization.",
            ]], 401),
        ]);

        // 制限付きの API キーで音声の権限がない：権限の名前を最後まで出し、画面での呼び名を添える
        $response = $this->post(route('voice.turn'), ['audio' => $this->audio(), 'seconds' => 2])
            ->assertStatus(422)
            ->assertJsonFragment(['ok' => false]);
        $this->assertStringContainsString('api.model.audio.request：音声（Audio）', $response->json('error'));
        $this->assertSame(0.0, VoiceTurn::sole()->estimated_cost);
    }

    /**
     * 判断の AI の偽の応答（道具の呼び出し、または返事）
     */
    protected function aiCalls(array $calls): array
    {
        return ['status' => 'completed', 'model' => 'gpt-6-luna', 'usage' => ['input_tokens' => 100, 'output_tokens' => 10], 'output' => array_map(
            fn ($call, $i) => ['type' => 'function_call', 'call_id' => "call_{$i}", 'name' => $call, 'arguments' => '{}'], $calls, array_keys($calls))];
    }

    protected function aiSays(string $text): array
    {
        return ['status' => 'completed', 'model' => 'gpt-6-luna', 'usage' => ['input_tokens' => 100, 'output_tokens' => 10], 'output' => [
            ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => $text]]],
        ]];
    }

    public function test_sync_runs_only_after_confirmation_in_the_next_turn(): void
    {
        $this->enable();
        Queue::fake();
        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::sequence()->push(['text' => '同期して'])->push(['text' => 'はい']),
            'api.openai.com/v1/responses'            => Http::sequence()
                // 1回目の発言：操作の準備（まだ実行しない）→ 内容を伝えて尋ねる
                ->push($this->aiCalls(['start_sync']))
                ->push($this->aiSays('Example Blog の同期を始めます。実行しますか？'))
                // 2回目の発言：同意 → 確認した
                ->push($this->aiCalls(['confirm_action']))
                ->push($this->aiSays('同期を開始しました。')),
            'api.openai.com/v1/audio/speech'         => Http::response('ID3', 200),
        ]);

        $this->post(route('voice.turn'), ['audio' => $this->audio(), 'seconds' => 1])->assertOk();
        Queue::assertNothingPushed();
        $this->assertTrue(VoiceTurn::first()->tool_calls[0]['result']['needs_confirmation']);

        $this->post(route('voice.turn'), ['audio' => $this->audio(), 'seconds' => 1])->assertOk()->assertJson(['reply' => '同期を開始しました。']);
        Queue::assertPushed(SyncBlogJob::class, 1);
    }

    public function test_confirmation_in_the_same_turn_is_refused(): void
    {
        $this->enable();
        Queue::fake();
        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => '同期して']),
            'api.openai.com/v1/responses'            => Http::sequence()
                // AI が、尋ねずに同じ発言の中で確認を済ませようとした
                ->push($this->aiCalls(['start_sync', 'confirm_action']))
                ->push($this->aiSays('同期を始めます。実行しますか？')),
            'api.openai.com/v1/audio/speech'         => Http::response('ID3', 200),
        ]);

        $this->post(route('voice.turn'), ['audio' => $this->audio(), 'seconds' => 1])->assertOk();

        Queue::assertNothingPushed();
        $this->assertStringContainsString('次の発言', VoiceTurn::sole()->tool_calls[1]['result']['error']);
    }

    public function test_quality_diagnosis_needs_targets_and_is_limited(): void
    {
        $tools = app(VoiceTools::class)->withContext(null, 1);

        // 対象がなければ、確認待ちにしない
        $this->assertSame('対象の記事がありません。', $tools->call('run_quality_diagnosis', ['target' => 'unevaluated', 'threshold' => null, 'limit' => 10], $this->blog)['result']['error']);
        // 声で選べない対象は断る
        $this->assertArrayHasKey('error', $tools->call('run_quality_diagnosis', ['target' => 'all', 'threshold' => null, 'limit' => 10], $this->blog)['result']);
    }

    public function test_quality_diagnosis_is_registered_after_confirmation(): void
    {
        Queue::fake();
        foreach ([1, 2, 3] as $id) {
            Post::create(['blog_id' => $this->blog->id, 'wordpress_id' => $id, 'title_raw' => "記事{$id}", 'status' => 'publish',
                'link' => "https://blog.example.test/{$id}.html", 'normalized_path' => "/{$id}.html", 'wordpress_modified_gmt' => '2026-09-01 00:00:00']);
        }

        // 1回目の発言：未評価の記事を2件 → 確認待ち（件数と対象の全体を伝える）
        $prepared = app(VoiceTools::class)->withContext(null, 1)->call('run_quality_diagnosis', ['target' => 'unevaluated', 'threshold' => null, 'limit' => 2], $this->blog);
        $this->assertTrue($prepared['result']['needs_confirmation']);
        $this->assertStringContainsString('2件', $prepared['result']['summary']);
        $this->assertSame(0, AiBatch::count());

        // 2回目の発言：確認した → まとめて実行に登録し、その画面を開く
        $done = app(VoiceTools::class)->withContext(null, 2)->call('confirm_action', [], $this->blog);
        $batch = AiBatch::sole();
        $this->assertSame(2, $batch->total_count);
        $this->assertSame(route('ai.batches.show', ['id' => $batch->id]), $done['navigate']);
    }

    public function test_status_tool_reads_the_dashboard_panels(): void
    {
        $result = app(VoiceTools::class)->call('get_status', [], $this->blog);

        $this->assertSame(['同期', '定期実行', 'WordPress', 'AI の残高', '内部リンク', '教材・提携'], array_column($result['result']['panels'], 'area'));
        $this->assertNull($result['navigate']);

        // ない画面は開かない
        $this->assertNull(app(VoiceTools::class)->call('open_screen', ['screen' => 'no-such'], $this->blog)['navigate']);
    }

    public function test_settings_are_saved(): void
    {
        // 開いていた画面に戻る（メニューのポップアップから保存する。D-61）
        $this->from(route('drafts.index'))->put(route('voice.settings.update'), ['enabled' => '1', 'mode' => 'c', 'voice' => 'onyx', 'instructions' => '明るく'])
            ->assertRedirect(route('drafts.index'));
        $settings = app(VoiceSettings::class);
        $this->assertTrue($settings->enabled());
        $this->assertSame('onyx', $settings->voice());

        // リアルタイム会話（画面では B・C。保存する値は d・e）も選べる（D-58-06）
        $this->put(route('voice.settings.update'), ['enabled' => '1', 'mode' => 'e', 'voice' => 'onyx'])->assertSessionHasNoErrors();
        $this->assertSame('gpt-realtime-2.1', app(VoiceSettings::class)->realtimeModel());
        $this->put(route('voice.settings.update'), ['enabled' => '1', 'mode' => 'x', 'voice' => 'onyx'])->assertSessionHasErrors('mode');

        // 設定はメニューの「AI → 音声操作」のポップアップにある。AIの設定の画面には、もうない（D-61）
        $this->get(route('home'))->assertOk()
            ->assertSee('data-modal-open="voice-settings-modal"', false)
            ->assertSee('id="voice-settings-modal"', false)
            ->assertSee('リアルタイム会話（mini')
            // 説明は、各項目の「?」のツールチップに出す（D-61-02）
            ->assertSee('class="tip"', false)
            ->assertSee('data-tip="返事の声の話し方', false);
        $this->get(route('ai.settings.edit'))->assertOk()->assertDontSee('id="voice"', false)->assertDontSee('音声の設定を保存する')
            // API実行の料金表に、音声の操作のモデルの料金も出す（D-68）
            ->assertSee('音声の操作のモデル')
            ->assertSee('gpt-4o-mini-tts')
            ->assertSee('返事の声（文字を声にする）（使用中：方式A）')
            ->assertSee('gpt-realtime-2.1-mini')
            ->assertSee('（使用中：方式B）');
    }

    public function test_settings_popup_stays_open_with_errors(): void
    {
        // 誤りで戻ったときは、ポップアップを開いたままにし、誤りを出す（D-61）
        $this->from(route('home'))->put(route('voice.settings.update'), ['_form' => 'voice', 'enabled' => '1', 'mode' => 'x', 'voice' => 'onyx'])
            ->assertRedirect(route('home'));
        $html = $this->get(route('home'))->assertOk()->assertSee('<ul class="text-error">', false)->getContent();
        $this->assertMatchesRegularExpression('/id="voice-settings-modal"[^>]*data-modal-autoopen/', $html);

        // 誤りがなければ、開かない
        $this->assertDoesNotMatchRegularExpression('/id="voice-settings-modal"[^>]*data-modal-autoopen/', $this->get(route('scheduled-tasks.runs'))->getContent());
    }

    public function test_realtime_session_gives_an_ephemeral_key_with_tools(): void
    {
        $this->enable();
        // 方式 c のままでは、リアルタイム会話は始めない
        $this->post(route('voice.realtime.session'))->assertStatus(422);

        app(VoiceSettings::class)->save(['enabled' => true, 'mode' => 'd', 'voice' => 'cedar', 'instructions' => '落ち着いた執事のように'], null);
        Http::fake(['api.openai.com/v1/realtime/client_secrets' => Http::response(['value' => 'ek_test', 'expires_at' => 1790000000])]);

        $this->post(route('voice.realtime.session'))->assertOk()->assertJson(['ok' => true, 'secret' => 'ek_test', 'model' => 'gpt-realtime-2.1-mini', 'max_seconds' => 300]);

        // 本当の API キーで鍵を作り、会話の設定（モデル・声・話し方・道具）を渡す
        Http::assertSent(function ($request) {
            $session = $request['session'];

            return $request->hasHeader('Authorization', 'Bearer test-key')
                && $session['model'] === 'gpt-realtime-2.1-mini'
                && $session['audio']['output']['voice'] === 'cedar'
                && str_contains($session['instructions'], '落ち着いた執事のように')
                && in_array('confirm_action', array_column($session['tools'], 'name'), true)
                && ! array_key_exists('strict', $session['tools'][0]);
        });
    }

    public function test_realtime_tools_confirm_only_in_a_later_turn_and_usage_is_recorded(): void
    {
        app(VoiceSettings::class)->save(['enabled' => true, 'mode' => 'd', 'voice' => 'cedar', 'instructions' => ''], null);
        Http::fake(['api.openai.com/v1/realtime/client_secrets' => Http::response(['value' => 'ek_test'])]);
        Queue::fake();
        $this->post(route('voice.realtime.session'))->assertOk();

        // 発言1：同期の準備（まだ実行しない）
        $prepared = $this->post(route('voice.realtime.tool'), ['name' => 'start_sync', 'arguments' => '{}', 'turn' => 1])->assertOk();
        $this->assertTrue(json_decode($prepared->json('output'), true)['needs_confirmation']);
        // 同じ発言の中の確認は断る
        $this->post(route('voice.realtime.tool'), ['name' => 'confirm_action', 'arguments' => '{}', 'turn' => 1])->assertOk();
        Queue::assertNothingPushed();

        // 発言2：同意 → 実行
        $this->post(route('voice.realtime.tool'), ['name' => 'start_sync', 'arguments' => '{}', 'turn' => 2]);
        $this->post(route('voice.realtime.tool'), ['name' => 'confirm_action', 'arguments' => '{}', 'turn' => 3])->assertOk();
        Queue::assertPushed(SyncBlogJob::class, 1);

        // 応答の使用量から費用を残し、AIの費用に含める
        $this->post(route('voice.realtime.usage'), [
            'usage'      => ['input_tokens' => 2600, 'output_tokens' => 1250, 'input_token_details' => ['audio_tokens' => 600, 'text_tokens' => 2000, 'cached_tokens' => 0], 'output_token_details' => ['audio_tokens' => 1200, 'text_tokens' => 50]],
            'transcript' => '同期して',
            'reply'      => '同期を始めます。実行しますか？',
            'tools'      => ['start_sync'],
        ])->assertOk();

        $turn = VoiceTurn::sole();
        $this->assertSame('d', $turn->mode);
        // mini：音声 600×$10 + 文字 2000×$0.6 + 音声の出力 1200×$20 + 文字の出力 50×$2.4（100万トークンあたり）＋ 文字起こし 60秒×$0.003/分
        $this->assertEqualsWithDelta((600 * 10 + 2000 * 0.6 + 1200 * 20 + 50 * 2.4) / 1_000_000 + 0.003, $turn->estimated_cost, 0.000001);
        $this->assertEqualsWithDelta($turn->estimated_cost, app(AiGenerationRepository::class)->apiCostSince(now()->subDay()), 0.000001);
    }

    public function test_screens_open_in_the_drawer_and_can_be_closed_by_voice(): void
    {
        $this->enable();

        // トップページ：画面のパネルがあり、リンクとメニューはパネルに開く。ほかのサイトの中には表示させない
        $this->get(route('home'))->assertOk()
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertSee('id="screen-drawer"', false)
            ->assertSee('data-drawer-links', false)
            ->assertSee('data-home-url="' . route('home') . '"', false);

        // トップページ以外：リンクは、ふつうに画面を移る
        $this->get(route('scheduled-tasks.runs'))->assertOk()->assertDontSee('data-drawer-links', false);

        // 声で「閉じて」：パネルを閉じる（close）
        $closed = app(VoiceTools::class)->call('close_screen', [], $this->blog);
        $this->assertSame('close', $closed['navigate']);
        $this->assertContains('close_screen', array_column(app(VoiceTools::class)->definitions(true), 'name'));
    }
}
