<?php

namespace Tests\Feature\Articles;

use App\Enums\DraftState;
use App\Enums\PushResourceType;
use App\Models\ArticleDraft;
use App\Models\Blog;
use App\Models\Post;
use App\Models\User;
use App\Services\Articles\ArticleHtmlFinisher;
use App\Services\Articles\DraftService;
use App\Services\Articles\LinkSwitchService;
use App\Services\Push\ArticlePushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 公開された記事へのリンクの切り替え（D-39）。
 */
class LinkSwitchTest extends TestCase
{
    use RefreshDatabase;

    protected Blog $blog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->blog = Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true, 'quality_profile' => 'si-note']);
        $this->actingAs(User::factory()->create());
    }

    protected function createPost(int $wordpressId, string $status, string $content = '<p>本文</p>', string $title = ''): Post
    {
        return Post::create([
            'blog_id' => $this->blog->id, 'wordpress_id' => $wordpressId, 'title_raw' => $title ?: "記事{$wordpressId}", 'status' => $status, 'content_raw' => $content,
            'link' => "https://blog.example.test/js/{$wordpressId}.html", 'normalized_path' => "/js/{$wordpressId}.html", 'wordpress_modified_gmt' => '2026-09-01 00:00:00',
        ]);
    }

    public function test_titles_become_links_after_targets_are_published(): void
    {
        $unpublished = $this->createPost(50, 'draft');
        $newDraft = app(DraftService::class)->createNew($this->blog, PushResourceType::Post, null);
        $newDraft->update(['title_raw' => 'これから書く記事']);

        // 公開していない記事・まだ WordPress にない記事は、タイトルだけにして目印を残す
        $finished = app(ArticleHtmlFinisher::class)->finish($this->blog, "<p>次は [[記事:50]] と [[記事:下書き{$newDraft->id}]] です。</p>");
        $this->assertStringContainsString('<!-- blogos:記事:50 -->記事50<!-- /blogos -->', $finished['content']);
        $this->assertStringContainsString("<!-- blogos:下書き:{$newDraft->id} -->これから書く記事<!-- /blogos -->", $finished['content']);

        // その本文で公開済みの記事と、人が作業中の編集案がある記事
        $source = $this->createPost(60, 'publish', $finished['content']);
        $busy = $this->createPost(61, 'publish', $finished['content']);
        $busyDraft = app(DraftService::class)->createFromArticle($busy, null, null);

        $service = app(LinkSwitchService::class);
        $this->assertSame([], $service->pending($this->blog));

        // 記事50が公開され、新しい記事も反映・公開された
        $unpublished->update(['status' => 'publish']);
        $published = $this->createPost(70, 'publish', '<p>本文</p>', 'これから書く記事');
        $newDraft->update(['post_id' => $published->id, 'state' => DraftState::Pushed]);

        $this->assertCount(2, $service->pending($this->blog));
        $this->assertSame(1, $service->createDrafts($this->blog));

        $draft = ArticleDraft::where('auto_reason', LinkSwitchService::REASON)->sole();
        $this->assertSame($source->id, $draft->post_id);
        $this->assertStringContainsString('<a href="/js/50.html">記事50</a>', $draft->content_raw);
        $this->assertStringContainsString('<a href="/js/70.html">これから書く記事</a>', $draft->content_raw);
        $this->assertStringNotContainsString('blogos:', $draft->content_raw);
        $this->assertContains('公開された記事「これから書く記事」へのリンクに切り替えました。', $draft->finish_notes);

        // 作業中の編集案がある記事には作らず、画面で知らせる
        $this->assertFalse(ArticleDraft::where('post_id', $busy->id)->where('id', '!=', $busyDraft->id)->exists());
        $this->get(route('drafts.link-switch'))->assertOk()
            ->assertSee('公開された記事「記事50」へのリンクに切り替えました。')
            ->assertSee('作業中の編集案があるため、作っていない記事')
            ->assertSee("編集案 #{$busyDraft->id}");
        $this->get(route('home'))->assertOk()->assertSee('公開された記事へのリンクに切り替えられる記事が1件あります');

        // 同じ記事に二重に作らない
        $this->assertSame(0, $service->createDrafts($this->blog));

        // まとめて反映（人の承認）
        $this->mock(ArticlePushService::class)->shouldReceive('push')->once()->withArgs(fn ($pushed) => $pushed->id === $draft->id);
        $this->post(route('drafts.link-switch.push'), ['selected_blog_id' => $this->blog->id, 'selected' => [$draft->id]])
            ->assertSessionHas('status', '1件を反映しました。');
    }

    public function test_new_article_drafts_are_offered_to_ai_as_link_targets(): void
    {
        $this->createPost(24, 'publish');
        $newDraft = app(DraftService::class)->createNew($this->blog, PushResourceType::Post, null);
        $newDraft->update(['title_raw' => 'これから書く記事']);

        $prompt = app(\App\Services\Ai\PromptBuilder::class)->build(\App\Enums\AiMode::NewArticle, $this->blog, null, null, ['メインキーワード' => 'JavaScript'], null)['prompt'];
        $this->assertStringContainsString('[[記事:24]] 記事24', $prompt);
        $this->assertStringContainsString("[[記事:下書き{$newDraft->id}]] これから書く記事：（まだ公開していない新しい記事）", $prompt);
    }
}
