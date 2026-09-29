<?php

namespace Tests\Feature\Articles;

use App\Enums\AiBatchTarget;
use App\Enums\AiMode;
use App\Models\ArticleDraft;
use App\Models\Blog;
use App\Models\Category;
use App\Models\Page;
use App\Models\Post;
use App\Models\User;
use App\Services\Ai\AiBatchService;
use App\Services\Ai\PromptBuilder;
use App\Services\Articles\ContentExtractionService;
use App\Services\Articles\InternalLinkChecker;
use App\Services\Articles\LinkSwitchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ブログ全体の内部リンクの確認（D-42）。
 */
class InternalLinkCheckTest extends TestCase
{
    use RefreshDatabase;

    protected Blog $blog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->blog = Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true, 'quality_profile' => 'si-note']);
        $this->actingAs(User::factory()->create());
    }

    protected function createPost(int $wordpressId, string $path, string $content, ?Category $category = null, string $status = 'publish'): Post
    {
        $post = Post::create([
            'blog_id' => $this->blog->id, 'wordpress_id' => $wordpressId, 'title_raw' => "記事{$wordpressId}", 'status' => $status, 'content_raw' => $content,
            'link' => "https://blog.example.test{$path}", 'normalized_path' => $path, 'wordpress_modified_gmt' => '2026-09-01 00:00:00',
        ]);
        if ($category !== null) {
            $post->categories()->attach($category->id);
        }

        return $post;
    }

    protected function createPage(int $wordpressId, string $slug, string $path, int $parentWordpressId, string $content): Page
    {
        return Page::create([
            'blog_id' => $this->blog->id, 'wordpress_id' => $wordpressId, 'wordpress_parent_id' => $parentWordpressId, 'title_raw' => "ロードマップ{$wordpressId}", 'slug' => $slug,
            'status' => 'publish', 'content_raw' => $content, 'link' => "https://blog.example.test{$path}", 'normalized_path' => $path, 'wordpress_modified_gmt' => '2026-09-01 00:00:00',
        ]);
    }

    public function test_link_issues_are_detected_fixed_and_passed_to_revision(): void
    {
        $js = Category::create(['blog_id' => $this->blog->id, 'wordpress_id' => 30, 'name' => 'JavaScript', 'slug' => 'js']);
        $basic = Category::create(['blog_id' => $this->blog->id, 'wordpress_id' => 31, 'name' => '基礎', 'slug' => 'js-basic', 'parent_id' => $js->id]);
        $dialog = Category::create(['blog_id' => $this->blog->id, 'wordpress_id' => 32, 'name' => 'Dialog', 'slug' => 'js-dialog', 'parent_id' => $js->id]);

        // 記事10：リンク切れ（xxx.html）・古い URL（記事11のカテゴリが変わる前）・ロードマップのあるカテゴリの一覧・ロードマップのないカテゴリの一覧
        $source = $this->createPost(10, '/js/js-basic/10.html', '<p><a href="/js/js-basic/xxx.html">仮のリンク</a>・<a href="/js/js-basic/11.html">記事11</a>・'
            . '<a href="/category/js/js-basic/">基礎の一覧</a>・<a href="/category/js/js-dialog/">Dialogの一覧</a></p>', $basic);
        $moved = $this->createPost(11, '/js/js-document/11.html', '<p><a href="/js/js-basic/10.html">記事10</a></p>', $basic);
        $orphan = $this->createPost(12, '/js/js-basic/12.html', '<p>本文</p>', $basic);
        $parentRoadmap = $this->createPage(100, 'js', '/js.html', 0, '<p><a href="/js/js-basic.html">基礎</a></p>');
        $this->createPage(101, 'js-basic', '/js/js-basic.html', 100, '<p><a href="/js/js-basic/10.html">記事10</a></p>');
        app(ContentExtractionService::class)->extractAll($this->blog);

        $checker = app(InternalLinkChecker::class);
        $counts = $checker->counts($this->blog);
        $this->assertSame(1, $counts['broken']);
        $this->assertSame(1, $counts['outdated']);
        $this->assertSame(1, $counts['category']); // Dialog はロードマップがないため、問題にしない
        // 孤立記事：記事12（記事10は記事11とロードマップから、記事11は記事10からリンクされている）。親ロードマップはどこからもリンクされていない
        $orphans = collect($checker->check($this->blog)['articles'])->where('kind', 'orphan')->map(fn ($row) => $row['article']->title_raw)->values()->all();
        $this->assertSame(['記事12', 'ロードマップ100'], $orphans);
        // ロードマップに載っていない記事：記事11・12
        $this->assertSame(2, $counts['not_in_roadmap']);

        $this->get(route('links.check'))->assertOk()->assertSee('リンク切れ（1件）', false)->assertSee('/js/js-basic/xxx.html')
            ->assertSee('/js/js-document/11.html')->assertSee('リンク切れがある記事（1記事）をまとめて改修する');
        $this->get(route('home'))->assertOk()->assertSee('リンク切れが1件あります');

        // 機械的に直せるもの（古い URL・カテゴリの一覧 → ロードマップ）は、リンクの切り替えの編集案で直す（仕上げは通さない）
        $this->assertSame(1, app(LinkSwitchService::class)->createDrafts($this->blog));
        $draft = ArticleDraft::where('auto_reason', LinkSwitchService::REASON)->sole();
        $this->assertSame($source->id, $draft->post_id);
        $this->assertStringContainsString('<a href="/js/js-document/11.html">記事11</a>', $draft->content_raw);
        $this->assertStringContainsString('<a href="/js/js-basic.html">基礎の一覧</a>', $draft->content_raw);
        $this->assertStringContainsString('<a href="/category/js/js-dialog/">', $draft->content_raw);
        $this->assertStringContainsString('<a href="/js/js-basic/xxx.html">', $draft->content_raw);
        $this->assertStringNotContainsString('quads', $draft->content_raw);
        $this->assertCount(2, $draft->finish_notes);

        // リンク切れは、記事改修の指示文に入る。リンクが少ない記事には、記事の一覧で印を付ける
        $prompt = app(PromptBuilder::class)->build(AiMode::Revision, $this->blog, $source, null, [], null)['prompt'];
        $this->assertStringContainsString('「仮のリンク」 → /js/js-basic/xxx.html：この URL の記事がありません', $prompt);
        $this->assertStringContainsString('[[記事:12]] 記事12：https://blog.example.test/js/js-basic/12.html（リンクが少ない記事）', $prompt);
        $this->assertStringNotContainsString('[[記事:10]] 記事10：https://blog.example.test/js/js-basic/10.html（', $prompt);

        // まとめて改修の対象：リンク切れがある記事
        $targets = app(AiBatchService::class)->targets($this->blog, AiMode::Revision, AiBatchTarget::BrokenLinks);
        $this->assertSame([$source->id], array_map(fn ($row) => $row['article']->id, $targets));
        $this->assertNotNull($moved);
        $this->assertNotNull($orphan);
        $this->assertNotNull($parentRoadmap);
    }
}
