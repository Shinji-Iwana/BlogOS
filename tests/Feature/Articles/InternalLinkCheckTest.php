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

    public function test_posts_missing_from_the_roadmap_are_added_by_a_roadmap_draft(): void
    {
        config(['services.openai.key' => 'sk-test-key', 'blogos.ai.api.monthly_budget_usd' => 10]);
        $prompts = [];
        \Illuminate\Support\Facades\Http::fake(['api.openai.com/v1/responses' => function (\Illuminate\Http\Client\Request $request) use (&$prompts) {
            $prompts[] = $request['input'];

            return \Illuminate\Support\Facades\Http::response([
                'id' => 'resp_1', 'status' => 'completed', 'model' => $request['model'],
                'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => "=== タイトル ===\nAIのタイトル\n=== メタディスクリプション ===\nAIの説明\n=== 本文 ===\n"
                    . "<div class=\"roadmap-step\"><h3>ステップ1：基礎</h3><ul><li>[[記事:10]]</li><li>[[記事:12]]</li></ul></div>\n=== 指摘への対応 ===\n[]"]]]],
                'usage' => ['input_tokens' => 2000, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens' => 1000, 'output_tokens_details' => ['reasoning_tokens' => 100]],
            ]);
        }]);

        $js = Category::create(['blog_id' => $this->blog->id, 'wordpress_id' => 30, 'name' => 'JavaScript', 'slug' => 'js']);
        $basic = Category::create(['blog_id' => $this->blog->id, 'wordpress_id' => 31, 'name' => '基礎', 'slug' => 'js-basic', 'parent_id' => $js->id]);
        $noRoadmap = Category::create(['blog_id' => $this->blog->id, 'wordpress_id' => 32, 'name' => 'Dialog', 'slug' => 'js-dialog', 'parent_id' => $js->id]);
        $this->createPost(10, '/js/js-basic/10.html', '<p>本文</p>', $basic);
        $orphan = $this->createPost(12, '/js/js-basic/12.html', '<p>本文</p>', $basic);
        $this->createPost(13, '/js/js-dialog/13.html', '<p>本文</p>', $noRoadmap);
        $this->createPage(100, 'js', '/js.html', 0, '<p><a href="/js/js-basic.html">基礎</a></p>');
        $roadmap = $this->createPage(101, 'js-basic', '/js/js-basic.html', 100, '<div class="roadmap-step"><h3>ステップ1：基礎</h3><ul><li><a href="/js/js-basic/10.html">記事10</a></li></ul></div>');
        \App\Models\ArticleManagement::create(['blog_id' => $this->blog->id, 'post_id' => $orphan->id, 'article_type' => 'acquisition', 'main_search_intent' => '基礎を知りたい']);
        app(ContentExtractionService::class)->extractAll($this->blog);

        // ロードマップに載っていない記事（記事12）を、子ロードマップのページごとにまとめる。ロードマップのないカテゴリの記事13は対象外で、確認の画面に案内を出す（D-70-06）
        $service = app(\App\Services\Articles\RoadmapLinkService::class);
        $targets = $service->targets($this->blog);
        $this->assertSame([$roadmap->id], array_keys($targets));
        $this->assertSame(['記事12'], collect($targets[$roadmap->id]['posts'])->pluck('title_raw')->all());
        $this->get(route('links.check'))->assertSee('カテゴリのロードマップのページがありません。カテゴリの立ち上げで作ってください');

        // ロードマップのページの改修を、記事を載せることだけの指示で実行し、編集案にする（指摘は渡さない・タイトルとメタディスクリプションは変えない）
        $results = $service->run($this->blog, 'gpt-6-luna', 'medium');
        $this->assertStringContainsString('1件の記事を載せる改修を登録しました', $results[0]);
        $this->assertStringContainsString('ロードマップに記事を載せる（BlogOS が指定）', $prompts[0]);
        $this->assertStringContainsString("[[記事:{$orphan->wordpress_id}]] 記事12（主の検索意図：基礎を知りたい）", $prompts[0]);
        $this->assertStringContainsString('品質の指摘は扱いません', $prompts[0]);
        $draft = ArticleDraft::where('page_id', $roadmap->id)->sole();
        $this->assertSame('ロードマップ101', $draft->title_raw);
        $this->assertStringContainsString('/js/js-basic/12.html', $draft->content_raw);
        $this->assertSame(0, \App\Models\RevisionFinding::count());

        // 作業中の編集案があるロードマップには、重ねて作らない。記事の編集案の画面には「対応中」と出す
        $this->assertStringContainsString('作業中の編集案があるため、登録しませんでした', $service->run($this->blog, 'gpt-6-luna', 'medium')[0]);
        $this->assertSame($draft->id, $service->pendingDraftFor($orphan)?->id);
        $postDraft = app(\App\Services\Articles\DraftService::class)->createFromArticle($orphan, \App\Enums\RevisionScope::Minor, null, null);
        $this->get(route('drafts.edit', ['id' => $postDraft->id]))->assertOk()->assertSee("編集案 #{$draft->id}</a>で、この記事を載せる作業中です", false);
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
