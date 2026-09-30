<?php

namespace Tests\Feature\Articles;

use App\Enums\ChangeSource;
use App\Models\ArticleDraft;
use App\Models\Blog;
use App\Models\Post;
use App\Models\User;
use App\Repositories\ArticleDraftRepository;
use App\Services\Articles\ArticleHtmlFinisher;
use App\Services\Articles\ContentExtractionService;
use App\Services\Articles\DraftService;
use App\Services\Articles\LinkSwitchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * タイトルが変わった記事へのリンクの文字を、新しいタイトルに直す（D-46）。
 */
class ArticleLinkTextTest extends TestCase
{
    use RefreshDatabase;

    protected Blog $blog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->blog = Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true]);
        $this->actingAs(User::factory()->create());
    }

    protected function createPost(int $wordpressId, string $title, string $content): Post
    {
        return Post::create([
            'blog_id' => $this->blog->id, 'wordpress_id' => $wordpressId, 'title_raw' => $title, 'status' => 'publish', 'content_raw' => $content,
            'link' => "https://blog.example.test/js/{$wordpressId}.html", 'normalized_path' => "/js/{$wordpressId}.html", 'wordpress_modified_gmt' => '2026-09-01 00:00:00',
        ]);
    }

    public function test_link_texts_follow_title_changes_in_drafts_and_published_articles(): void
    {
        // 記事10のタイトルが「DOMとは」から「DOMとは？仕組みを解説」に変わった（反映の履歴）
        $target = $this->createPost(10, 'DOMとは？仕組みを解説', '<p>本文</p>');
        DB::table('post_histories')->insert(['blog_id' => $this->blog->id, 'post_id' => $target->id, 'change_set_id' => (string) Str::uuid(), 'field' => 'title_raw',
            'old_value' => 'DOMとは', 'new_value' => 'DOMとは？仕組みを解説', 'source' => ChangeSource::BlogosPush->value, 'changed_at' => now()]);

        // 公開中の記事11：以前のタイトルのリンクと、人が書いた文字のリンク
        $published = $this->createPost(11, '記事11', '<p><a href="/js/10.html">DOMとは</a>・<a href="/js/10.html">こちら</a></p>');
        // 公開中の記事12：作業中の編集案がある
        $withDraft = $this->createPost(12, '記事12', '<p><a href="/js/10.html">DOMとは</a></p>');
        app(ContentExtractionService::class)->extractAll($this->blog);
        $draft = app(DraftService::class)->createFromArticle($withDraft, null, null);
        app(ArticleDraftRepository::class)->update($draft, ['content_raw' => '<p>改修中 <a href="https://blog.example.test/js/10.html">DOMとは</a></p>'], ChangeSource::Ai, null);

        app(LinkSwitchService::class)->createDrafts($this->blog);

        // 作業中の編集案は、その場で直す（新しい編集案は作らない）
        $this->assertSame('<p>改修中 <a href="https://blog.example.test/js/10.html">DOMとは？仕組みを解説</a></p>', $draft->fresh()->content_raw);
        $this->assertContains('タイトルが変わった記事へのリンクの文字を直しました：「DOMとは」→「DOMとは？仕組みを解説」', $draft->fresh()->finish_notes);
        $this->assertSame(1, ArticleDraft::where('post_id', $withDraft->id)->count());

        // 公開中の記事は、リンクの切り替えの編集案で直す。人が書いた文字は変えない
        $switch = ArticleDraft::where('auto_reason', LinkSwitchService::REASON)->sole();
        $this->assertSame($published->id, $switch->post_id);
        $this->assertSame('<p><a href="/js/10.html">DOMとは？仕組みを解説</a>・<a href="/js/10.html">こちら</a></p>', $switch->content_raw);
        $this->get(route('drafts.link-switch'))->assertOk()->assertSee('「DOMとは」→「DOMとは？仕組みを解説」')->assertDontSee('作業中の編集案があるため');

        // 仕上げ（目印を置き換え直す）でも直す
        $finished = app(ArticleHtmlFinisher::class)->finish($this->blog, '<p>前の記事：<a href="/js/10.html">DOMとは</a></p>', false);
        $this->assertStringContainsString('<a href="/js/10.html">DOMとは？仕組みを解説</a>', $finished['content']);
    }
}
