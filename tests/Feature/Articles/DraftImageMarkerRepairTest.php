<?php

namespace Tests\Feature\Articles;

use App\Enums\ChangeSource;
use App\Enums\ImageKind;
use App\Enums\PushResourceType;
use App\Models\AiGeneration;
use App\Models\Blog;
use App\Models\User;
use App\Repositories\ArticleDraftRepository;
use App\Services\Ai\AiOutputParser;
use App\Services\Articles\DraftService;
use App\Services\Images\ImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 画像の依頼の key が本文の目印と結び付かなかった編集案の修正（D-34-06）。
 */
class DraftImageMarkerRepairTest extends TestCase
{
    use RefreshDatabase;

    public function test_image_key_with_template_explanation_is_normalized(): void
    {
        $this->assertSame('新規1', AiOutputParser::imageKey('新規1（本文の [[画像:新規1]] と同じ）'));
        $this->assertSame('新規2', AiOutputParser::imageKey('[[画像:新規2]]'));
        $this->assertSame('新規3', AiOutputParser::imageKey('3'));
    }

    public function test_unlinked_image_marker_is_relinked_when_placeholders_are_replaced_again(): void
    {
        $blog = Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true, 'quality_profile' => 'si-note']);
        $this->actingAs(User::factory()->create());

        // AI の回答：本文の目印は [[画像:新規1]]、画像の依頼の key は例の説明文ごと
        $output = "=== タイトル ===\n記事\n=== 本文 ===\n<h2>見出し</h2>\n<p>[[画像:新規1]]</p>\n=== 画像の依頼 ===\n```json\n"
            . '[{"key": "新規1（本文の [[画像:新規1]] と同じ）", "kind": "screenshot", "title": "実行結果", "description": "画面を撮る", "alt": "実行結果"}]' . "\n```\n";
        $draft = app(DraftService::class)->createNew($blog, PushResourceType::Post, null);
        $generation = AiGeneration::create(['blog_id' => $blog->id, 'article_draft_id' => $draft->id, 'purpose' => 'new_article', 'execution_method' => 'api', 'provider' => 'openai',
            'template_key' => 'new_article', 'template_version' => '1.2.6', 'quality_common_version' => '1.3.0', 'input' => 'x', 'output' => $output, 'status' => 'succeeded']);
        app(ArticleDraftRepository::class)->update($draft, ['title_raw' => '記事', 'content_raw' => "<h2>見出し</h2>\n<p>[[画像:新規1]]</p>", 'ai_generation_id' => $generation->id], ChangeSource::Ai, null);
        // 画像の案は作られたが、本文の目印と結び付いていない。ほかの編集案の画像は対象にしない
        $other = app(ImageService::class)->create($blog, ImageKind::Screenshot, '実行結果', null, null);
        $image = app(ImageService::class)->create($blog, ImageKind::Screenshot, '実行結果', '画面を撮る', null, ['article_draft_id' => $draft->id]);

        $this->artisan('drafts:repair-image-markers', ['--dry-run' => true])->expectsOutputToContain('1件の目印を結び付けました')->assertSuccessful();
        $this->assertStringContainsString('[[画像:新規1]]', $draft->fresh()->content_raw);

        $this->post(route('drafts.finish', ['id' => $draft->id]), ['selected_blog_id' => $blog->id])->assertRedirect();
        $content = $draft->fresh()->content_raw;
        $this->assertStringContainsString("[[画像:{$image->id}]]", $content);
        $this->assertStringNotContainsString('[[画像:新規1]]', $content);
        $this->assertStringNotContainsString("[[画像:{$other->id}]]", $content);
    }
}
