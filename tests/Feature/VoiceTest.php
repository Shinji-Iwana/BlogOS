<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\User;
use App\Models\VoiceTurn;
use App\Repositories\AiGenerationRepository;
use App\Services\Voice\VoiceSettings;
use App\Services\Voice\VoiceTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
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

    public function test_status_tool_reads_the_dashboard_panels(): void
    {
        $result = app(VoiceTools::class)->call('get_status', [], $this->blog);

        $this->assertSame(['同期', '定期実行', 'WordPress', 'AI の残高', '内部リンク', '教材・提携'], array_column($result['result']['panels'], 'area'));
        $this->assertNull($result['navigate']);

        // ない画面は開かない
        $this->assertNull(app(VoiceTools::class)->call('open_screen', ['screen' => 'no-such'], $this->blog)['navigate']);
    }

    public function test_settings_are_saved_and_realtime_modes_are_not_ready(): void
    {
        $this->put(route('voice.settings.update'), ['enabled' => '1', 'mode' => 'c', 'voice' => 'onyx', 'instructions' => '明るく'])
            ->assertRedirect(route('ai.settings.edit') . '#voice');
        $settings = app(VoiceSettings::class);
        $this->assertTrue($settings->enabled());
        $this->assertSame('onyx', $settings->voice());

        $this->put(route('voice.settings.update'), ['enabled' => '1', 'mode' => 'd', 'voice' => 'onyx'])->assertSessionHasErrors('mode');
        $this->get(route('ai.settings.edit'))->assertOk()->assertSee('id="voice"', false)->assertSee('※準備中');
    }
}
