<?php

namespace Tests\Feature\Images;

use App\Enums\AiGenerationStatus;
use App\Enums\AiMode;
use App\Enums\ImageKind;
use App\Enums\ImageSource;
use App\Enums\ImageStatus;
use App\Enums\PushState;
use App\Enums\SyncTrigger;
use App\Models\AiGeneration;
use App\Models\Blog;
use App\Models\BlogCredential;
use App\Models\Category;
use App\Models\CategoryEyecatch;
use App\Models\Image;
use App\Models\Media;
use App\Models\User;
use App\Models\WordPressPushOperation;
use App\Repositories\ImageRepository;
use App\Services\Sync\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeWordPress;
use Tests\TestCase;

/**
 * 記事で使う画像：アップロード・図の作成（SVG → PNG）・画像モデル・別の形式・WordPress への登録・アイキャッチ（D-32）。
 */
class ImageTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Blog $blog;

    protected FakeWordPress $wp;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['services.openai.key' => null]);

        $this->user = User::factory()->create();
        $this->blog = Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true, 'quality_profile' => 'si-note']);
        BlogCredential::create(['blog_id' => $this->blog->id, 'username' => 'admin', 'secret' => 'secret']);

        $this->wp = new FakeWordPress();
        $this->wp->lists['users'] = [FakeWordPress::user(1)];
        $this->wp->lists['categories'] = [FakeWordPress::term(10, ['name' => 'JavaScript']), FakeWordPress::term(11, ['name' => 'イベント', 'parent' => 10])];
        $this->wp->lists['media'] = [FakeWordPress::media(30), FakeWordPress::media(31)];
        $this->wp->lists['posts'] = [
            FakeWordPress::post(100, ['categories' => [11], 'featured_media' => 30]),
            FakeWordPress::post(101, ['categories' => [11], 'featured_media' => 30]),
            FakeWordPress::post(102, ['categories' => [10], 'featured_media' => 31]),
        ];
        $this->wp->install();
        app(SyncService::class)->run($this->blog, SyncTrigger::Initial);

        $this->actingAs($this->user);
    }

    protected function selected(array $data = []): array
    {
        return array_merge(['selected_blog_id' => $this->blog->id], $data);
    }

    protected function png(int $width = 40, int $height = 20): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 111, 217));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    protected function svgOutput(): string
    {
        return "```json\n" . json_encode([
            'format'              => 'svg',
            'reason'              => '処理の順序を正確に示すため',
            'title'               => 'クリックの流れ',
            'svg'                 => '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="300" viewBox="0 0 800 300"><rect width="100%" height="100%" fill="#ffffff"/><text x="20" y="40">クリック</text><script>alert(1)</script></svg>',
            'illustration_prompt' => 'A friendly flat illustration of a mouse click on a button, no text, no letters',
            'alt'                 => 'ボタンをクリックすると、イベントが発生し、登録した関数が呼ばれる流れの図',
            'caption'             => 'クリックから関数が呼ばれるまで',
            'filename'            => 'JS Click Flow!',
        ], JSON_UNESCAPED_UNICODE) . "\n```";
    }

    public function test_screenshot_upload_and_ready_requires_alt_and_filename(): void
    {
        $this->get(route('images.index'))->assertOk()->assertSee('画像をアップロードする');

        $this->post(route('images.store'), $this->selected([
            'kind' => 'screenshot', 'title' => 'コンソールの表示',
            'file' => UploadedFile::fake()->createWithContent('console.png', $this->png()),
        ]))->assertRedirect();

        $image = Image::sole();
        $this->assertSame(ImageKind::Screenshot, $image->kind);
        $this->assertSame(ImageSource::Upload, $image->source);
        $this->assertSame([40, 20], [$image->width, $image->height]);
        Storage::disk('local')->assertExists($image->path);
        $this->get(route('images.file', ['id' => $image->id]))->assertOk()->assertHeader('Content-Type', 'image/png');

        // alt・ファイル名がないと、確認済みにできない
        $this->post(route('images.ready', ['id' => $image->id]), $this->selected())->assertSessionHasErrors('image');
        $this->assertSame(ImageStatus::Draft, $image->fresh()->status);

        // ファイル名は英小文字・数字・ハイフンにし、保存したファイルの名前も合わせる
        $this->put(route('images.update', ['id' => $image->id]), $this->selected([
            'title' => 'コンソールの表示', 'kind' => 'screenshot', 'alt' => 'Chrome のコンソールに Hello と表示された画面', 'filename' => 'Chrome Console_Hello',
        ]))->assertRedirect();
        $image->refresh();
        $this->assertSame('chrome-console-hello', $image->filename);
        $this->assertStringEndsWith('/chrome-console-hello.png', $image->path);
        Storage::disk('local')->assertExists($image->path);

        $this->post(route('images.ready', ['id' => $image->id]), $this->selected())->assertRedirect();
        $this->assertSame(ImageStatus::Ready, $image->fresh()->status);

        // 画像以外は受け付けない
        $this->post(route('images.store'), $this->selected([
            'kind' => 'screenshot', 'title' => 'x', 'file' => UploadedFile::fake()->createWithContent('a.png', 'not an image'),
        ]))->assertSessionHasErrors('file');
    }

    public function test_design_manual_flow_saves_safe_svg_and_browser_png(): void
    {
        $this->post(route('images.design'), $this->selected([
            'title' => 'クリックの流れ', 'description' => 'ボタンをクリックしてから関数が呼ばれるまでの流れ', 'format' => 'auto', 'execution_method' => 'manual',
        ]))->assertRedirect();

        $generation = AiGeneration::sole();
        $image = Image::sole();
        $this->assertSame(AiMode::ImageDesign, $generation->purpose);
        $this->assertSame($image->id, $generation->image_id);
        $this->assertStringContainsString('ボタンをクリックしてから関数が呼ばれるまでの流れ', $generation->input);
        $this->assertStringContainsString('1. 図解・表の掲載基準', $generation->input);
        $this->assertStringNotContainsString('形式の値', $generation->input);

        $this->post(route('ai.generations.submit', ['id' => $generation->id]), $this->selected(['output' => $this->svgOutput(), 'model' => 'gpt-6-luna']))->assertRedirect();
        $this->assertSame(AiGenerationStatus::Succeeded, $generation->fresh()->status);

        $image->refresh();
        $this->assertSame(ImageKind::Diagram, $image->kind);
        $this->assertStringContainsString('クリック', $image->svg_source);
        $this->assertStringNotContainsString('<script', $image->svg_source);
        $this->assertSame('js-click-flow', $image->filename);
        $this->assertStringContainsString('SVG の図を選びました', $image->ai_note);
        $this->assertStringContainsString('no text', $image->image_prompt);
        $this->assertFalse($image->hasFile());
        $this->get(route('images.show', ['id' => $image->id]))->assertOk()->assertSee('PNG にして保存する')->assertSee('PNG にしていません');

        // ブラウザで作った PNG を保存する
        $this->post(route('images.png', ['id' => $image->id]), $this->selected(['file' => UploadedFile::fake()->createWithContent('diagram.png', $this->png(1600, 600))]), ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('redirect', route('images.show', ['id' => $image->id]));
        $image->refresh();
        $this->assertSame(ImageSource::AiSvg, $image->source);
        $this->assertSame(1600, $image->width);
        $this->post(route('images.ready', ['id' => $image->id]), $this->selected())->assertRedirect();
        $this->assertTrue($image->fresh()->isReady());

        // SVG を直すと、PNG にし直す（前の PNG は使わない）
        $this->put(route('images.svg', ['id' => $image->id]), $this->selected(['svg' => str_replace('クリック', 'クリックする', $image->svg_source)]))->assertRedirect();
        $image->refresh();
        $this->assertFalse($image->hasFile());
        $this->assertSame(ImageStatus::Draft, $image->status);
        $this->put(route('images.svg', ['id' => $image->id]), $this->selected(['svg' => '<p>not svg</p>']))->assertSessionHasErrors('svg');
    }

    public function test_api_design_choosing_illustration_generates_image_and_records_cost(): void
    {
        config(['services.openai.key' => 'sk-test-key', 'blogos.ai.api.monthly_budget_usd' => null]);
        $illustration = json_encode([
            'format' => 'illustration', 'reason' => '例え話で伝えるため', 'title' => '郵便のたとえ', 'svg' => null,
            'illustration_prompt' => 'A mail carrier delivering letters between two houses, flat illustration, no text, no letters',
            'alt' => '手紙を届ける郵便配達のイラスト', 'caption' => null, 'filename' => 'http-mail-metaphor',
        ], JSON_UNESCAPED_UNICODE);
        Http::fake([
            'api.openai.com/v1/responses' => Http::response(['id' => 'r1', 'status' => 'completed', 'model' => 'gpt-6-luna',
                'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => $illustration]]]],
                'usage' => ['input_tokens' => 3000, 'output_tokens' => 500]]),
            'api.openai.com/v1/images/generations' => Http::response(['data' => [['b64_json' => base64_encode($this->png(1536, 1024))]],
                'usage' => ['input_tokens' => 40, 'input_tokens_details' => ['text_tokens' => 40, 'image_tokens' => 0], 'output_tokens' => 4000]]),
        ]);

        $this->post(route('images.design'), $this->selected([
            'title' => 'HTTP のたとえ', 'description' => 'HTTP の要求と応答を、郵便のやり取りにたとえた絵', 'format' => 'auto',
            'execution_method' => 'api', 'model' => 'gpt-6-luna', 'reasoning_effort' => 'medium',
        ]))->assertRedirect();

        $image = Image::sole();
        $this->assertSame(ImageKind::Illustration, $image->kind);
        $this->assertSame(ImageSource::AiImage, $image->source);
        $this->assertSame([1536, 1024], [$image->width, $image->height]);

        $generated = AiGeneration::where('purpose', AiMode::ImageGeneration)->sole();
        $this->assertSame(AiGenerationStatus::Succeeded, $generated->status);
        // 画像の料金：文章の入力 40 × $5 ＋ 画像の出力 4000 × $30（/1M）
        $this->assertEqualsWithDelta((40 * 5 + 4000 * 30) / 1_000_000, $generated->estimated_cost, 0.0001);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'images/generations')
            && $request['model'] === 'gpt-image-2.5-flare' && $request['size'] === '1536x1024' && $request['quality'] === 'medium'
            && str_contains($request['prompt'], 'no text'));

        // 画像の生成は、AI実行記録から実行し直さない（画像の画面から作る）
        $generated->update(['status' => AiGenerationStatus::Failed]);
        $this->post(route('ai.generations.retry', ['id' => $generated->id]), $this->selected())->assertSessionHasErrors('ai');

        // もう一方の形式（SVG の図）でも作って比べる
        $this->post(route('images.variant', ['id' => $image->id]), $this->selected(['execution_method' => 'manual']))->assertRedirect();
        $variant = Image::where('variant_of_image_id', $image->id)->sole();
        $this->assertSame(ImageKind::Diagram, $variant->kind);
        $this->assertStringContainsString('SVG の図（svg にする）', AiGeneration::where('image_id', $variant->id)->sole()->input);
        $this->get(route('images.show', ['id' => $image->id]))->assertOk()->assertSee('比べる：図解');
    }

    public function test_missing_api_key_scope_is_explained(): void
    {
        config(['services.openai.key' => 'sk-test-key', 'blogos.ai.api.monthly_budget_usd' => null]);
        Http::fake(['api.openai.com/v1/images/generations' => Http::response(['error' => [
            'message' => 'You have insufficient permissions for this operation. Missing scopes: api.model.images.request. Check that you have the correct role in your organization.',
            'type'    => 'invalid_request_error',
        ]], 401)]);
        $image = Image::create([
            'blog_id' => $this->blog->id, 'kind' => ImageKind::Illustration, 'status' => ImageStatus::Draft, 'title' => 'たとえ',
            'image_prompt' => 'A mail carrier, flat illustration, no text',
        ]);

        $this->post(route('images.generate', ['id' => $image->id]), $this->selected())->assertRedirect();

        $generation = AiGeneration::sole();
        $this->assertSame(AiGenerationStatus::Failed, $generation->status);
        $this->assertStringContainsString('APIキーの権限（Permissions）が足りません', $generation->error);
        $this->assertStringContainsString('api.model.images.request', $generation->error);
        $this->assertStringNotContainsString('APIキーが正しいか', $generation->error);
        $this->assertFalse($image->fresh()->hasFile());
    }

    public function test_generated_image_is_kept_even_if_large_and_stale_worker_is_detected(): void
    {
        config(['services.openai.key' => 'sk-test-key', 'blogos.ai.api.monthly_budget_usd' => null, 'blogos.ai.image.max_upload_kb' => 1]);
        Http::fake(['api.openai.com/v1/images/generations' => Http::response(['data' => [['b64_json' => base64_encode($this->png(600, 400))]],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 343]])]);
        $image = Image::create([
            'blog_id' => $this->blog->id, 'kind' => ImageKind::Illustration, 'status' => ImageStatus::Draft, 'title' => 'たとえ',
            'image_prompt' => 'A mail carrier, flat illustration, no text',
        ]);

        // アップロードの上限（ここでは1KB）を超えても、画像モデルが作った画像は保存する（料金がかかっているため）
        $this->post(route('images.generate', ['id' => $image->id]), $this->selected())->assertRedirect();
        $this->assertSame(AiGenerationStatus::Succeeded, AiGeneration::sole()->status);
        $this->assertTrue($image->fresh()->hasFile());
        $this->assertEqualsWithDelta((100 * 5 + 343 * 30) / 1_000_000, AiGeneration::sole()->estimated_cost, 0.0001);

        // 画像モデルの料金が分からない（古い設定のまま動いている Queue の処理）なら、API を呼ばずに止める
        $stale = AiGeneration::create([
            'blog_id' => $this->blog->id, 'image_id' => $image->id, 'purpose' => AiMode::ImageGeneration, 'execution_method' => 'api',
            'model' => 'unknown-image-model', 'template_key' => 'image_generation', 'template_version' => '1.0.0', 'input' => 'x', 'status' => AiGenerationStatus::Running,
        ]);
        app(\App\Services\Images\ImageGenerationService::class)->run($stale);
        $this->assertSame(AiGenerationStatus::Failed, $stale->fresh()->status);
        $this->assertStringContainsString('queue:work を起動し直して', $stale->fresh()->error);
        Http::assertSentCount(1);
    }

    public function test_ready_image_is_uploaded_to_wordpress_media(): void
    {
        $image = Image::create([
            'blog_id' => $this->blog->id, 'kind' => ImageKind::Screenshot, 'status' => ImageStatus::Draft, 'title' => '設定画面',
            'alt' => '設定画面', 'caption' => '設定の例', 'filename' => 'settings-screen',
        ]);
        $this->post(route('images.file.replace', ['id' => $image->id]), $this->selected(['file' => UploadedFile::fake()->createWithContent('s.png', $this->png())]))->assertRedirect();

        // 確認済みでない画像は登録しない
        $this->post(route('images.wordpress', ['id' => $image->id]), $this->selected())->assertSessionHasErrors('image');
        $this->post(route('images.ready', ['id' => $image->id]), $this->selected())->assertRedirect();

        $this->post(route('images.wordpress', ['id' => $image->id]), $this->selected())->assertRedirect(route('images.show', ['id' => $image->id]));

        $image->refresh();
        $this->assertNotNull($image->media_id);
        $media = Media::find($image->media_id);
        $this->assertSame('設定画面', $media->title_raw);
        $this->assertSame('設定画面', $media->alt_text);
        $this->assertSame('settings-screen.png', end($this->wp->uploadedFiles)['filename']);
        $operation = WordPressPushOperation::latest('id')->first();
        $this->assertSame(PushState::Completed, $operation->state);
        $this->assertSame($media->id, $operation->media_id);

        // 登録した画像は、もう一度登録しない・削除しない
        $this->post(route('images.wordpress', ['id' => $image->id]), $this->selected())->assertSessionHasErrors('image');
        $this->delete(route('images.destroy', ['id' => $image->id]), $this->selected())->assertStatus(422);
    }

    public function test_category_eyecatches(): void
    {
        $parent = Category::where('wordpress_id', 10)->sole();
        $child = Category::where('wordpress_id', 11)->sole();
        $media30 = Media::where('wordpress_id', 30)->sole();
        $media31 = Media::where('wordpress_id', 31)->sole();

        // 今の記事で多い画像を目安として表示する
        $used = app(ImageRepository::class)->usedEyecatches($this->blog->id);
        $this->assertSame($media30->id, $used[$child->id]['media_id']);
        $this->assertSame(2, $used[$child->id]['count']);
        $this->get(route('images.eyecatches'))->assertOk()->assertSee('イベント')->assertSee('2記事');

        // 親のカテゴリだけに設定すると、子のカテゴリにも使う
        $this->put(route('images.eyecatches.update'), $this->selected(['eyecatch' => [$parent->id => $media31->id, $child->id => '']]))->assertRedirect();
        $this->assertSame(1, CategoryEyecatch::count());
        $this->assertSame($media31->id, app(ImageRepository::class)->eyecatchFor($child)->id);

        // 子のカテゴリに設定すれば、そちらを使う
        $this->put(route('images.eyecatches.update'), $this->selected(['eyecatch' => [$child->id => $media30->id]]))->assertRedirect();
        $this->assertSame($media30->id, app(ImageRepository::class)->eyecatchFor($child)->id);
    }
}
