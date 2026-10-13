<?php

namespace Tests\Feature\Topics;

use App\Enums\AiGenerationStatus;
use App\Enums\KeywordType;
use App\Enums\SuggestionStatus;
use App\Models\AiGeneration;
use App\Models\ArticleKeyword;
use App\Models\Blog;
use App\Models\Category;
use App\Models\Page;
use App\Models\Post;
use App\Models\TopicSuggestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 記事の企画（D-40）。
 */
class TopicPlanningTest extends TestCase
{
    use RefreshDatabase;

    protected Blog $blog;

    protected Category $js;

    protected Category $basic;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.openai.key' => null]);
        $this->blog = Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true, 'quality_profile' => 'si-note']);
        $this->js = Category::create(['blog_id' => $this->blog->id, 'wordpress_id' => 10, 'name' => 'JavaScript', 'slug' => 'js']);
        $this->basic = Category::create(['blog_id' => $this->blog->id, 'wordpress_id' => 11, 'name' => '基礎', 'slug' => 'js-basic', 'parent_id' => $this->js->id]);
        Category::create(['blog_id' => $this->blog->id, 'wordpress_id' => 12, 'name' => 'Event（イベント操作）', 'slug' => 'js-event', 'parent_id' => $this->js->id]);

        $post = Post::create(['blog_id' => $this->blog->id, 'wordpress_id' => 24, 'title_raw' => 'JavaScriptとは？【JavaScript入門】', 'status' => 'publish', 'link' => 'https://blog.example.test/js/js-basic/24.html', 'normalized_path' => '/js/js-basic/24.html']);
        $post->categories()->attach($this->basic->id);
        ArticleKeyword::create(['blog_id' => $this->blog->id, 'post_id' => $post->id, 'keyword' => 'JavaScript とは', 'keyword_type' => KeywordType::Main->value]);
        Page::create(['blog_id' => $this->blog->id, 'wordpress_id' => 2063, 'title_raw' => 'JavaScript 基礎ロードマップ', 'status' => 'publish', 'link' => 'https://blog.example.test/js/js-basic.html', 'normalized_path' => '/js/js-basic.html',
            'content_raw' => '<div class="roadmap-step"><h3>ステップ1：導入</h3></div><div class="roadmap-step"><h3>ステップ2：文法</h3></div>']);

        foreach ([['javascript 変数', 30, 25.0], ['javascript とは', 10, 5.0]] as [$query, $impressions, $position]) {
            DB::table('google_search_console_query_daily')->insert([
                'blog_id' => $this->blog->id, 'date' => now()->subDays(5)->toDateString(), 'page_url' => $post->link, 'page_url_hash' => sha1($post->link),
                'post_id' => $post->id, 'query' => $query, 'query_hash' => sha1($query), 'clicks' => 0, 'impressions' => $impressions, 'position' => $position,
            ]);
        }

        $this->actingAs(User::factory()->create());
    }

    /**
     * カテゴリごとの記事種類（D-83-01）：親の行は子を含めた小計。件数から、そのカテゴリ・記事種類の記事の一覧を開ける
     */
    public function test_article_type_matrix_by_category(): void
    {
        $basicPost = Post::where('wordpress_id', 24)->sole();
        \App\Models\ArticleManagement::create(['blog_id' => $this->blog->id, 'post_id' => $basicPost->id, 'article_type' => 'acquisition', 'article_subtype' => 'know']);
        $event = Category::where('slug', 'js-event')->sole();
        $doPost = Post::create(['blog_id' => $this->blog->id, 'wordpress_id' => 30, 'title_raw' => 'addEventListenerの使い方', 'status' => 'publish', 'link' => 'https://blog.example.test/js/js-event/30.html']);
        $doPost->categories()->attach($event->id);
        \App\Models\ArticleManagement::create(['blog_id' => $this->blog->id, 'post_id' => $doPost->id, 'article_type' => 'acquisition', 'article_subtype' => 'do']);
        $revenue = Post::create(['blog_id' => $this->blog->id, 'wordpress_id' => 31, 'title_raw' => 'JavaScript入門書のおすすめ', 'status' => 'publish', 'link' => 'https://blog.example.test/js/31.html']);
        $revenue->categories()->attach($this->js->id);
        \App\Models\ArticleManagement::create(['blog_id' => $this->blog->id, 'post_id' => $revenue->id, 'article_type' => 'revenue']);
        // 下書き・未登録の記事
        Post::create(['blog_id' => $this->blog->id, 'wordpress_id' => 32, 'title_raw' => '下書き', 'status' => 'draft', 'link' => 'https://blog.example.test/?p=32'])->categories()->attach($event->id);
        Post::create(['blog_id' => $this->blog->id, 'wordpress_id' => 33, 'title_raw' => '未登録の記事', 'status' => 'publish', 'link' => 'https://blog.example.test/js/js-event/33.html'])->categories()->attach($event->id);
        // 子ロードマップの固定ページは、カテゴリと同じスラッグで数える
        $roadmap = Page::where('wordpress_id', 2063)->sole();
        $roadmap->update(['slug' => 'js-basic']);
        \App\Models\ArticleManagement::create(['blog_id' => $this->blog->id, 'page_id' => $roadmap->id, 'article_type' => 'child_roadmap']);

        $matrix = app(\App\Services\Topics\ArticleTypeMatrixService::class)->build($this->blog);
        $rows = collect($matrix['rows'])->keyBy(fn ($row) => $row['category']->slug);
        $this->assertSame(1, $rows['js-basic']['counts']['acquisition:know']);
        $this->assertSame(1, $rows['js-basic']['counts']['child_roadmap']);
        $this->assertSame(['acquisition:do' => 1, 'unregistered' => 1], array_filter($rows['js-event']['counts']));
        // 親の行は、自分と子の記事の小計
        $this->assertSame(5, $rows['js']['total']);
        $this->assertSame(1, $rows['js']['counts']['revenue']);
        $this->assertSame(5, $matrix['total']);
        $this->assertEqualsWithDelta(0.2, $matrix['revenue_ratio'], 0.0001);
        $this->assertTrue($matrix['revenue_over']);

        $this->get(route('topics.index'))->assertOk()
            ->assertSee('<h2 data-code="TYPES">カテゴリごとの記事種類', false)
            ->assertSee('収益記事の割合が目安（10%）を超えています。')
            ->assertSee(e(route('articles.index', ['type' => 'posts', 'category_id' => $this->js->id, 'kind' => 'revenue'])), false);

        // 件数から開く記事の一覧は、そのカテゴリ（子を含む）・記事種類で絞る
        $this->get(route('articles.index', ['type' => 'posts', 'category_id' => $this->js->id, 'kind' => 'acquisition:do']))->assertOk()
            ->assertSee('絞り込み：カテゴリ「JavaScript」（子カテゴリを含む）')
            ->assertSee('addEventListenerの使い方')->assertDontSee('JavaScript入門書のおすすめ');
        $this->get(route('articles.index', ['type' => 'posts', 'kind' => 'unregistered']))->assertOk()
            ->assertSee('未登録の記事')->assertDontSee('addEventListenerの使い方');

        // ロードマップは、固定ページの一覧をカテゴリと同じスラッグで絞って開く（D-83-03）
        $this->get(route('topics.index'))->assertOk()
            ->assertSee(e(route('articles.index', ['type' => 'pages', 'category_id' => $this->basic->id, 'kind' => 'child_roadmap'])), false);
        Page::create(['blog_id' => $this->blog->id, 'wordpress_id' => 2070, 'title_raw' => 'ほかのロードマップ', 'status' => 'publish', 'slug' => 'other', 'link' => 'https://blog.example.test/other.html']);
        $this->get(route('articles.index', ['type' => 'pages', 'category_id' => $this->js->id, 'kind' => 'child_roadmap']))->assertOk()
            ->assertSee('JavaScript 基礎ロードマップ')->assertDontSee('ほかのロードマップ');
    }

    public function test_article_planning_creates_suggestions_with_duplicate_notes(): void
    {
        $this->get(route('topics.index'))->assertOk()
            ->assertSee('Event（イベント操作）：0記事')   // 記事の少ないカテゴリ
            ->assertSee('javascript 変数');              // 記事が合っていない検索語句（25位）

        $this->post(route('topics.store'), ['selected_blog_id' => $this->blog->id, 'category_id' => $this->basic->id, 'unit' => 'articles', 'execution_method' => 'manual'])->assertRedirect();
        $generation = AiGeneration::sole();
        $this->assertStringContainsString('子カテゴリの、まだ記事にしていない内容', $generation->input);
        $this->assertStringContainsString('JavaScriptとは？【JavaScript入門】（メインキーワード：JavaScript とは）', $generation->input);
        $this->assertStringContainsString('ステップ2：文法', $generation->input);
        $this->assertStringContainsString('javascript 変数：30／0／25', $generation->input);
        $this->assertStringContainsString('- 基礎（js-basic）：1記事', $generation->input);

        $output = "```json\n" . json_encode(['summary' => '文法の抜けを埋める', 'categories' => [], 'articles' => [
            ['title' => '変数とは？値を入れる箱【JavaScript入門】', 'main_keyword' => 'JavaScript 変数', 'sub_keywords' => ['let'], 'search_intent' => '変数の意味を知りたい', 'article_type' => 'acquisition', 'article_subtype' => 'know', 'roadmap_step' => 'ステップ2：文法', 'priority' => 'high', 'reason' => '検索語句「javascript 変数」の順位が低い', 'sources' => ['https://developer.mozilla.org/ja/docs/Web/JavaScript']],
            ['title' => 'JavaScriptとは何か', 'main_keyword' => 'JavaScript とは', 'priority' => 'low', 'reason' => '重複'],
        ]], JSON_UNESCAPED_UNICODE) . "\n```";
        $this->post(route('ai.generations.submit', ['id' => $generation->id]), ['selected_blog_id' => $this->blog->id, 'output' => $output, 'model' => 'gpt-6-luna'])->assertSessionHasNoErrors();
        $this->assertSame(AiGenerationStatus::Succeeded, $generation->fresh()->status);

        $suggestions = TopicSuggestion::orderBy('id')->get();
        $this->assertCount(2, $suggestions);
        $this->assertSame($this->basic->id, $suggestions[0]->category_id);
        $this->assertNull($suggestions[0]->duplicate_note);
        $this->assertStringContainsString('メインキーワードが同じ記事があります', (string) $suggestions[1]->duplicate_note);

        $this->get(route('topics.index'))->assertOk()->assertSee('変数とは？値を入れる箱')->assertSee('重複の可能性');

        // 採用すると、新規記事の画面に値を入れて開ける
        $this->post(route('topics.review', ['id' => $suggestions[0]->id]), ['selected_blog_id' => $this->blog->id, 'action' => 'accept'])->assertRedirect();
        $this->assertSame(SuggestionStatus::Accepted, $suggestions[0]->fresh()->status);
        $this->get(route('ai.generations.create', \App\Support\TopicLinks::newArticle($suggestions[0]->fresh())))->assertOk()
            ->assertSee('value="JavaScript 変数"', false)
            ->assertSee('記事の企画の案：変数とは？値を入れる箱');
    }

    public function test_category_planning_creates_category_and_first_article_suggestions(): void
    {
        $this->post(route('topics.store'), ['selected_blog_id' => $this->blog->id, 'category_id' => $this->js->id, 'unit' => 'categories', 'execution_method' => 'manual'])->assertRedirect();
        $generation = AiGeneration::sole();
        $this->assertStringContainsString('親カテゴリの、足りない子カテゴリ', $generation->input);

        $output = json_encode(['summary' => 'ok', 'articles' => [], 'categories' => [
            ['name' => 'Array（配列操作）', 'slug' => 'js-array', 'scope' => '配列の操作', 'position' => '基礎の後', 'priority' => 'high', 'reason' => '配列は必須',
                'first_articles' => [['title' => '配列とは？【JavaScript入門】', 'main_keyword' => 'JavaScript 配列'], ['title' => 'mapの使い方【JavaScript入門】', 'main_keyword' => 'JavaScript map']]],
            ['name' => 'Event（イベント操作）', 'slug' => 'js-event', 'first_articles' => []],
        ]], JSON_UNESCAPED_UNICODE);
        $this->post(route('ai.generations.submit', ['id' => $generation->id]), ['selected_blog_id' => $this->blog->id, 'output' => $output, 'model' => 'gpt-6-luna'])->assertSessionHasNoErrors();

        $category = TopicSuggestion::where('type', 'category')->where('slug', 'js-array')->sole();
        $this->assertSame(2, $category->articles()->count());
        $this->assertSame('同じ名前・スラッグのカテゴリが既にあります。', TopicSuggestion::where('slug', 'js-event')->sole()->duplicate_note);

        // 子カテゴリの案を見送ると、その記事の案も見送る
        $this->post(route('topics.review', ['id' => $category->id]), ['selected_blog_id' => $this->blog->id, 'action' => 'reject'])->assertRedirect();
        $this->assertSame(0, $category->articles()->where('status', SuggestionStatus::Pending->value)->count());

        // 企画し直すと、同じカテゴリ・同じ種類の確認待ちの案は置き換える
        $this->post(route('topics.store'), ['selected_blog_id' => $this->blog->id, 'category_id' => $this->js->id, 'unit' => 'categories', 'execution_method' => 'manual']);
        $second = AiGeneration::latest('id')->first();
        $this->post(route('ai.generations.submit', ['id' => $second->id]), ['selected_blog_id' => $this->blog->id, 'output' => $output, 'model' => 'gpt-6-luna']);
        $this->assertSame(SuggestionStatus::Superseded, TopicSuggestion::where('slug', 'js-event')->oldest('id')->first()->status);
    }
}
